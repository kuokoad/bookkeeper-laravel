<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/refunds-after-returns.test.ts: hand back
| no more than was paid, and reverse goods only once.
|
| The P&L's net sales (Phase 7) is read here as the August journal lines on
| Sales Revenue, Sales Discounts and Sales Returns, credits less debits: the
| figure getProfitAndLoss(...).netSales reports. Nothing is deferred: the
| purchase void case arrived with the purchases in Phase 5.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\ValidationError;
use App\Services\CustomerPaymentService;
use App\Services\PurchaseService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const RAR_DAY = '2026-08-10';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->supplier = Shop::supplier($this->actor, 'Kofi Traders');
    $this->voidAt = Carbon::parse('2026-08-11 10:00:00', 'Africa/Accra');
    $this->rice = Shop::product($this->actor, 'Rice 5kg', 8_000, 12_000, ['unit' => 'bag']);
    $this->coke = Shop::product($this->actor, 'Coca-Cola', 2_000, 3_000);
    Shop::openingStock($this->actor, $this->rice, 20_000, 160_000, '2026-08-01');
    Shop::openingStock($this->actor, $this->coke, 20_000, 40_000, '2026-08-01');

    $this->sell = fn (array $lines, int $paid, int $invoiceDiscount = 0): array => SaleService::createSale([
        'businessDate' => RAR_DAY,
        'items' => array_map(fn (array $l): array => ['productId' => $l[0], 'qty' => $l[1] * 1000], $lines),
        ...($invoiceDiscount === 0 ? [] : ['invoiceDiscount' => $invoiceDiscount, 'allowPriceOverride' => true]),
        'tenders' => $paid === 0 ? [] : [['paymentAccountId' => $this->cash, 'amount' => $paid]],
    ], $this->actor);

    $this->returnAll = fn (int $saleId, int $refund): array => ReturnsService::createCustomerReturn($saleId, [
        'businessDate' => RAR_DAY,
        'items' => array_map(fn (array $i): array => ['itemId' => $i['id'], 'qty' => $i['qtyMilli']], SaleService::getSale($saleId)['items']),
        'refunds' => $refund === 0 ? [] : [['paymentAccountId' => $this->cash, 'amount' => $refund]],
    ], $this->actor);

    $this->onHand = fn (int $productId): int => (int) DB::table('products')->where('id', $productId)->value('qty_on_hand_milli');

    $this->netSales = fn (): int => (int) DB::table('journal_lines')
        ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
        ->whereIn('accounts.code', [ChartOfAccounts::SALES_REVENUE, ChartOfAccounts::SALES_DISCOUNTS, ChartOfAccounts::SALES_RETURNS])
        ->whereBetween('journal_entries.entry_date', ['2026-08-01', '2026-08-31'])
        ->selectRaw('COALESCE(SUM(`journal_lines`.`credit_minor` - `journal_lines`.`debit_minor`), 0) AS n')
        ->value('n');
});

describe('a return of a discounted sale', function () {
    it('hands back what the customer paid under tax-inclusive prices', function () {
        Shop::setSingleTax(2_000, true);
        $sale = ($this->sell)([[$this->rice, 1]], 10_800, 1_200);

        expect(($this->returnAll)($sale['saleId'], 10_800)['refunded'])->toBe(10_800)
            ->and(($this->netSales)())->toBe(0);
    });

    it('refuses a refund of more than a fully paid sale took', function () {
        Shop::setSingleTax(2_000, true);
        $sale = ($this->sell)([[$this->rice, 1]], 10_800, 1_200);

        expect(fn () => ($this->returnAll)($sale['saleId'], 11_000))->toThrow(ValidationError::class, 'At most 108.00 can be refunded in cash');
    });

    it('shares the discount between lines when only one comes back', function () {
        Shop::setSingleTax(2_000, true);
        // 150.00 less 15.00: rice carries 12.00 of the discount, coke 3.00.
        $sale = ($this->sell)([[$this->rice, 1], [$this->coke, 1]], 13_500, 1_500);
        $rice = collect(SaleService::getSale($sale['saleId'])['items'])->firstWhere('productId', $this->rice);

        $result = ReturnsService::createCustomerReturn($sale['saleId'], [
            'businessDate' => RAR_DAY,
            'items' => [['itemId' => $rice['id'], 'qty' => 1_000]],
            'refunds' => [['paymentAccountId' => $this->cash, 'amount' => 10_800]],
        ], $this->actor);

        expect($result['refunded'])->toBe(10_800)->and(($this->netSales)())->toBe(2_250);
    });

    it('holds at the Ghanaian rates, where nothing divides evenly', function () {
        Shop::setGhanaTaxes(true);
        $sale = ($this->sell)([[$this->rice, 1], [$this->coke, 1]], 14_863, 137);

        expect(($this->returnAll)($sale['saleId'], 14_863)['refunded'])->toBe(14_863)
            ->and(($this->netSales)())->toBe(0);
    });

    it('gives back nothing for goods that were given away', function () {
        Shop::setSingleTax(2_000, true);
        $sale = ($this->sell)([[$this->coke, 1]], 0, 3_000);

        expect(fn () => ($this->returnAll)($sale['saleId'], 500))->toThrow(ValidationError::class, 'Nothing can be refunded in cash');
        ($this->returnAll)($sale['saleId'], 0);
        expect(($this->netSales)())->toBe(0);
    });

    it('is unchanged when tax is added on top', function () {
        Shop::setSingleTax(2_000, false);
        // 120.00 less 12.00, plus 21.60 tax.
        $sale = ($this->sell)([[$this->rice, 1]], 12_960, 1_200);

        expect(($this->returnAll)($sale['saleId'], 12_960)['refunded'])->toBe(12_960)
            ->and(($this->netSales)())->toBe(0);
    });
});

describe('voiding after a return', function () {
    it('refuses a sale with goods already back, and changes nothing', function () {
        $sale = ($this->sell)([[$this->rice, 2]], 24_000);
        $rice = SaleService::getSale($sale['saleId'])['items'][0];
        ReturnsService::createCustomerReturn($sale['saleId'], [
            'businessDate' => RAR_DAY,
            'items' => [['itemId' => $rice['id'], 'qty' => 1_000]],
            'refunds' => [['paymentAccountId' => $this->cash, 'amount' => 12_000]],
        ], $this->actor);
        $stock = ($this->onHand)($this->rice);

        expect(fn () => SaleService::voidSale($sale['saleId'], 'rung up by mistake', $this->actor, $this->voidAt))->toThrow(ConflictError::class);
        expect(SaleService::getSale($sale['saleId'])['status'])->toBe('POSTED')
            ->and(($this->onHand)($this->rice))->toBe($stock);
    });

    it('refuses a purchase with goods already sent back, and changes nothing', function () {
        $purchase = Shop::purchase($this->actor, $this->supplier, [[$this->rice, 2_000, 8_000]], [], ['businessDate' => RAR_DAY]);
        $line = PurchaseService::getPurchase($purchase['purchaseId'])['items'][0];
        ReturnsService::createSupplierReturn($purchase['purchaseId'], ['businessDate' => RAR_DAY, 'items' => [['itemId' => $line['id'], 'qty' => 1_000]], 'refunds' => []], $this->actor);
        $stock = ($this->onHand)($this->rice);

        expect(fn () => PurchaseService::voidPurchase($purchase['purchaseId'], 'wrong supplier', $this->actor, $this->voidAt))->toThrow(ConflictError::class);
        expect(PurchaseService::getPurchase($purchase['purchaseId'])['status'])->toBe('POSTED')
            ->and(($this->onHand)($this->rice))->toBe($stock);
    });

    it('says so before asking for a real payment to be undone', function () {
        $customer = Shop::customer($this->actor, 'Mensah Provisions');
        $sale = SaleService::createSale([
            'businessDate' => RAR_DAY,
            'customerId' => $customer,
            'items' => [['productId' => $this->rice, 'qty' => 2_000]],
            'tenders' => [],
        ], $this->actor);
        CustomerPaymentService::recordCustomerPayment(['customerId' => $customer, 'businessDate' => RAR_DAY, 'paymentAccountId' => $this->cash, 'amount' => 10_000], $this->actor);
        $rice = SaleService::getSale($sale['saleId'])['items'][0];
        ReturnsService::createCustomerReturn($sale['saleId'], ['businessDate' => RAR_DAY, 'items' => [['itemId' => $rice['id'], 'qty' => 1_000]], 'refunds' => []], $this->actor);

        expect(fn () => SaleService::voidSale($sale['saleId'], 'rung up by mistake', $this->actor, $this->voidAt))->toThrow(ConflictError::class, 'already been returned');
    });

    it('still voids a sale nothing has come back from', function () {
        $sale = ($this->sell)([[$this->rice, 1]], 12_000);

        SaleService::voidSale($sale['saleId'], 'rung up by mistake', $this->actor, $this->voidAt);

        expect(SaleService::getSale($sale['saleId'])['status'])->toBe('VOIDED');
    });
});
