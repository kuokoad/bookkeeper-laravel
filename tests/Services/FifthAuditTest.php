<?php

declare(strict_types=1);

/*
| Fifth audit: each test is named for the behaviour that broke, and asserts
| what a shop owner would check, in minor units.
*/

use App\Domain\Errors\ConflictError;
use App\Domain\Errors\ValidationError;
use App\Reports\Csv\ListExport;
use App\Reports\Csv\ReportExport;
use App\Services\CashbookService;
use App\Services\CatalogService;
use App\Services\PaymentAccountService;
use App\Services\PurchaseService;
use App\Services\Reporting\OperationsService;
use App\Services\Reporting\TaxReturnService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use App\Services\SupplierPaymentService;
use App\Services\SupplierService;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const FIFTH_DAY = '2026-08-16';

beforeEach(function () {
    Shop::setNoTax();
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->product = Shop::product($this->actor, 'Milo 400g', 500, 800);
    $this->tomorrow = Carbon::parse(Clock::today())->addDay()->format('Y-m-d');
});

afterEach(fn () => Carbon::setTestNow());

describe('dates (finding 4)', function () {
    it('refuses owner capital and drawings dated in the future, so a count does not expect money not yet moved', function () {
        Shop::capital($this->actor, $this->cash, 10_000);

        expect(fn () => CashbookService::recordOwnerDrawings(['businessDate' => $this->tomorrow, 'paymentAccountId' => $this->cash, 'amount' => 5_000], $this->actor))
            ->toThrow(ValidationError::class, 'A drawing cannot be dated in the future.')
            ->and(fn () => CashbookService::recordOwnerCapital(['businessDate' => $this->tomorrow, 'paymentAccountId' => $this->cash, 'amount' => 5_000], $this->actor))
            ->toThrow(ValidationError::class, 'A capital payment cannot be dated in the future.')
            ->and(PaymentAccountService::getPaymentAccountBalance($this->cash))->toBe(10_000);
    });

    it('refuses a supplier payment dated in the future', function () {
        $supplier = Shop::supplier($this->actor);
        Shop::purchase($this->actor, $supplier, [[$this->product, 10_000, 500]]);
        Shop::capital($this->actor, $this->cash, 10_000);

        expect(fn () => SupplierPaymentService::recordSupplierPayment(['supplierId' => $supplier, 'businessDate' => $this->tomorrow, 'paymentAccountId' => $this->cash, 'amount' => 5_000], $this->actor))
            ->toThrow(ValidationError::class, 'A supplier payment cannot be dated in the future.')
            ->and(SupplierService::getSupplierBalance($supplier))->toBe(5_000);
    });

    it('refuses a supplier return dated in the future or before the purchase it returns', function () {
        $supplier = Shop::supplier($this->actor);
        $purchase = Shop::purchase($this->actor, $supplier, [[$this->product, 10_000, 500]], [], ['businessDate' => FIFTH_DAY]);
        $item = PurchaseService::getPurchase($purchase['purchaseId'])['items'][0]['id'];
        $return = fn (string $date) => ReturnsService::createSupplierReturn($purchase['purchaseId'], ['businessDate' => $date, 'items' => [['itemId' => $item, 'qty' => 1_000]], 'refunds' => []], $this->actor);

        expect(fn () => $return($this->tomorrow))->toThrow(ValidationError::class, 'A supplier return cannot be dated in the future.')
            ->and(fn () => $return('2026-08-10'))->toThrow(ValidationError::class, 'A supplier return cannot be dated before the purchase it returns (16 Aug 2026).')
            ->and(DB::table('purchases')->count())->toBe(1);
    });
});

describe('editing an oversold sale (finding 6)', function () {
    beforeEach(function () {
        // Negative stock allowed; a fallback cost of 1.50, 2 tins worth 2.00 on the shelf.
        Shop::setting('allow_negative_stock', true);
        $this->tin = Shop::product($this->actor, 'Sardine tin', 150, 800);
        Shop::openingStock($this->actor, $this->tin, 2_000, 200, FIFTH_DAY);
        $this->sell = fn (int $qty): array => ['businessDate' => FIFTH_DAY, 'items' => [['productId' => $this->tin, 'qty' => $qty]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => intdiv($qty, 1000) * 800]]];
    });

    it('is refused once a delivery has cleared the stock it oversold, so the shelf keeps its true value', function () {
        $sale = SaleService::createSale(($this->sell)(5_000), $this->actor);
        Shop::purchase($this->actor, Shop::supplier($this->actor), [[$this->tin, 3_000, 300]], [], ['businessDate' => FIFTH_DAY]);
        $value = CatalogService::getProduct($this->tin)['stockValue'];

        expect(fn () => SaleService::editSale($sale['saleId'], ($this->sell)(2_000), 'Sold 2', $this->actor))
            ->toThrow(ConflictError::class, 'stock has come in since')
            ->and(CatalogService::getProduct($this->tin)['stockValue'])->toBe($value);
        Shop::assertBooksHealthy('after the refused edit');
    });

    it('refuses voiding it too once a delivery has cleared the stock it oversold', function () {
        $sale = SaleService::createSale(($this->sell)(5_000), $this->actor);
        Shop::purchase($this->actor, Shop::supplier($this->actor), [[$this->tin, 3_000, 300]], [], ['businessDate' => FIFTH_DAY]);
        $value = CatalogService::getProduct($this->tin)['stockValue'];

        expect(fn () => SaleService::voidSale($sale['saleId'], 'Rung up wrong', $this->actor))
            ->toThrow(ConflictError::class, 'so it cannot be voided')
            ->and(CatalogService::getProduct($this->tin)['stockValue'])->toBe($value)
            ->and(DB::table('sales')->where('sales.id', $sale['saleId'])->value('status'))->toBe('POSTED');
        Shop::assertBooksHealthy('after the refused void');
    });

    it('still lets it be voided while nothing has come in since', function () {
        $sale = SaleService::createSale(($this->sell)(5_000), $this->actor);
        SaleService::voidSale($sale['saleId'], 'Rung up wrong', $this->actor);

        // Back to the 2 tins at 1.00 it found, not 2 tins at the average of what it took.
        expect(CatalogService::getProduct($this->tin)['qtyOnHand'])->toBe(2_000)
            ->and(CatalogService::getProduct($this->tin)['stockValue'])->toBe(200);
        Shop::assertBooksHealthy('after the void');
    });

    it('refuses voiding a stock adjustment that took out more than was on the shelf once a delivery has come in since', function () {
        $writeOff = Shop::stockOut($this->actor, $this->tin, 5_000, FIFTH_DAY);
        Shop::purchase($this->actor, Shop::supplier($this->actor), [[$this->tin, 3_000, 300]], [], ['businessDate' => FIFTH_DAY]);
        $value = CatalogService::getProduct($this->tin)['stockValue'];

        expect(fn () => StockAdjustmentService::voidStockAdjustment($writeOff['adjustmentId'], 'Counted wrong', $this->actor))
            ->toThrow(ConflictError::class, 'so it cannot be voided')
            ->and(CatalogService::getProduct($this->tin)['stockValue'])->toBe($value)
            ->and(DB::table('stock_adjustments')->where('stock_adjustments.id', $writeOff['adjustmentId'])->value('status'))->toBe('POSTED');
        Shop::assertBooksHealthy('after the refused void');
    });

    it('still lets that stock adjustment be voided while nothing has come in since', function () {
        $writeOff = Shop::stockOut($this->actor, $this->tin, 5_000, FIFTH_DAY);
        StockAdjustmentService::voidStockAdjustment($writeOff['adjustmentId'], 'Counted wrong', $this->actor);

        expect(CatalogService::getProduct($this->tin)['qtyOnHand'])->toBe(2_000)
            ->and(CatalogService::getProduct($this->tin)['stockValue'])->toBe(200);
        Shop::assertBooksHealthy('after the void');
    });

    it('takes oversold goods back at what the delivery that cleared them cost, never at the price the sale guessed', function (int $back, int $worth) {
        $sale = SaleService::createSale(($this->sell)(5_000), $this->actor);
        // 2 tins found at 1.00 each, 3 oversold at the 1.50 fallback; the delivery cleared those 3 at 3.00 each,
        // so the five cost 11.00, not the 6.50 the sale recorded.
        Shop::purchase($this->actor, Shop::supplier($this->actor), [[$this->tin, 3_000, 300]], [], ['businessDate' => FIFTH_DAY]);
        $item = DB::table('sale_items')->where('sale_items.sale_id', $sale['saleId'])->value('id');

        ReturnsService::createCustomerReturn($sale['saleId'], ['businessDate' => FIFTH_DAY, 'items' => [['itemId' => $item, 'qty' => $back]],
            'refunds' => [['paymentAccountId' => $this->cash, 'amount' => intdiv($back, 1000) * 800]]], $this->actor);

        expect(CatalogService::getProduct($this->tin)['stockValue'])->toBe($worth);
        Shop::assertBooksHealthy('after the return');
    })->with([
        'all five' => [5_000, 1_100],
        // Oversold units first: the 3 back are the 3 the delivery cleared at 3.00.
        'three of five' => [3_000, 900],
    ]);

    it('takes oversold goods back before any delivery as the sale took them, oversold units first', function (int $before, int $worthBefore, int $worthAfter) {
        $sale = SaleService::createSale(($this->sell)(5_000), $this->actor);
        $item = DB::table('sale_items')->where('sale_items.sale_id', $sale['saleId'])->value('id');
        $return = fn (int $qty) => ReturnsService::createCustomerReturn($sale['saleId'], ['businessDate' => FIFTH_DAY, 'items' => [['itemId' => $item, 'qty' => $qty]],
            'refunds' => [['paymentAccountId' => $this->cash, 'amount' => intdiv($qty, 1000) * 800]]], $this->actor);

        // The first units back cancel the 3 oversold at their 1.50 fallback; only then come the 2 found at 1.00.
        $return($before);
        expect(CatalogService::getProduct($this->tin)['stockValue'])->toBe($worthBefore);

        // Then a delivery of 3 at 3.00 and the rest back: 2 found tins at 1.00 and 3 bought at 3.00 is 11.00.
        Shop::purchase($this->actor, Shop::supplier($this->actor), [[$this->tin, 3_000, 300]], [], ['businessDate' => FIFTH_DAY]);
        if ($before < 5_000) {
            $return(5_000 - $before);
        }
        expect(CatalogService::getProduct($this->tin)['stockValue'])->toBe($worthAfter);
        Shop::assertBooksHealthy('after the returns');
    })->with([
        'all five, the sale undone' => [5_000, 200, 1_100],
        'two, then three after the delivery' => [2_000, -150, 1_100],
        'four, past zero' => [4_000, 100, 1_100],
    ]);

    it('is still allowed while nothing has come in since', function () {
        $sale = SaleService::createSale(($this->sell)(5_000), $this->actor);
        SaleService::editSale($sale['saleId'], ($this->sell)(2_000), 'Sold 2', $this->actor);

        expect(CatalogService::getProduct($this->tin)['qtyOnHand'])->toBe(0);
        Shop::assertBooksHealthy('after the edit');
    });
});

describe('reports and CSVs', function () {
    it('lists a product sold in two units as two rows, never adding cartons to pieces (finding 7)', function () {
        Shop::openingStock($this->actor, $this->product, 200_000, 100_000, FIFTH_DAY);
        $carton = SaleService::createSale(['businessDate' => FIFTH_DAY, 'items' => [['productId' => $this->product, 'qty' => 5_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 4_000]]], $this->actor);
        SaleService::createSale(['businessDate' => FIFTH_DAY, 'items' => [['productId' => $this->product, 'qty' => 10_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 8_000]]], $this->actor);
        // History from before a unit change, as imported: the first sale was in cartons.
        DB::table('sale_items')->where('sale_id', $carton['saleId'])->update(['unit' => 'carton']);
        $period = ['from' => FIFTH_DAY, 'to' => FIFTH_DAY];

        foreach ([OperationsService::getSalesByProduct($period), OperationsService::getSalesByProduct($period, OperationsService::earnedLines($period))] as $rows) {
            $byUnit = array_column($rows, 'qtySold', 'unit');
            ksort($byUnit);
            expect($byUnit)->toBe(['carton' => 5_000, 'pcs' => 10_000]);
        }
        $csv = array_column(array_filter(ReportExport::download('sales', ['from' => FIFTH_DAY, 'to' => FIFTH_DAY], FIFTH_DAY)['rows'], fn (array $r): bool => $r[0] === 'By stock item'), 1);
        expect($csv)->toContain('Milo 400g (carton)', 'Milo 400g (pcs)');
    });

    it('counts only taxed documents in the tax return "charged on" and "paid on" boxes (finding 8)', function () {
        $supplier = Shop::supplier($this->actor);
        Shop::purchase($this->actor, $supplier, [[$this->product, 10_000, 500]], [], ['businessDate' => FIFTH_DAY]);
        Shop::setSingleTax(1_500);
        Shop::purchase($this->actor, $supplier, [[$this->product, 2_000, 500]], [], ['businessDate' => FIFTH_DAY]);

        $tax = TaxReturnService::getTaxReturn(['from' => FIFTH_DAY, 'to' => FIFTH_DAY]);
        expect($tax['taxablePurchasesMinor'])->toBe(1_000)
            ->and($tax['purchaseCount'])->toBe(1);
    });

    it('labels a supplier return and the reversal of a voided purchase in the purchases CSV as the screen does, never "Paid" (finding 9)', function () {
        $supplier = Shop::supplier($this->actor);
        $kept = Shop::purchase($this->actor, $supplier, [[$this->product, 10_000, 500]], [], ['businessDate' => FIFTH_DAY]);
        $voided = Shop::purchase($this->actor, $supplier, [[$this->product, 1_000, 500]], [], ['businessDate' => FIFTH_DAY]);
        PurchaseService::voidPurchase($voided['purchaseId'], 'Entered twice', $this->actor);
        $item = PurchaseService::getPurchase($kept['purchaseId'])['items'][0]['id'];
        ReturnsService::createSupplierReturn($kept['purchaseId'], ['businessDate' => FIFTH_DAY, 'items' => [['itemId' => $item, 'qty' => 1_000]], 'refunds' => []], $this->actor);

        $statuses = array_column(ListExport::download('purchases', [], FIFTH_DAY)['rows'], 8);
        sort($statuses);
        // The reversal itself is not listed, on screen or in the file; the return was labelled "Paid".
        expect($statuses)->toBe(['Return', 'Unpaid', 'Voided']);
    });

    it('labels the reversal of a voided sale in the sales CSV as the screen does, never "Paid" (finding 9)', function () {
        Shop::openingStock($this->actor, $this->product, 10_000, 5_000, FIFTH_DAY);
        $sale = Shop::sell($this->actor, [[$this->product, 1_000]], [['paymentAccountId' => $this->cash, 'amount' => 800]], ['businessDate' => FIFTH_DAY]);
        SaleService::voidSale($sale['saleId'], 'Rung up twice', $this->actor);

        $statuses = array_column(ListExport::download('sales', [], Clock::today())['rows'], 11);
        sort($statuses);
        // Today's list holds the reversal, dated today; the voided original is on its own day.
        expect($statuses)->toBe(['Reversal']);
    });

    it('names the CSV of an as-at report by its day and the year-end pack by its year (finding 13)', function () {
        expect(ReportExport::download('balance-sheet', ['asAt' => '2025-12-31'], '2026-11-12')['filename'])->toBe('bookkeeper_balance-sheet_2025-12-31.csv')
            ->and(ReportExport::download('receivables', ['to' => '2026-06-30'], '2026-11-12')['filename'])->toBe('bookkeeper_receivables_2026-06-30.csv');
    });
});
