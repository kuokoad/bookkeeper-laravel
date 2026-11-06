<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/financial-reports.test.ts: the P&L, the
| balance sheet, the cash flow and the operational reports, read through the
| real reporting services over real transactions. The whole old file is
| ported; nothing is deferred.
*/

use App\Domain\Errors\ValidationError;
use App\Domain\Money;
use App\Enums\AdjustmentReason;
use App\Services\CashbookService;
use App\Services\CustomerPaymentService;
use App\Services\PurchaseService;
use App\Services\Reporting\FinancialService;
use App\Services\Reporting\OperationsService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use App\Services\SupplierPaymentService;
use App\Support\Clock;
use Tests\Support\Shop;

const FR_TODAY = '2026-08-17';
const FR_PERIOD = ['from' => '2026-08-01', 'to' => '2026-08-31'];

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->momo = Shop::paymentAccount('MOBILE_MONEY');
    $this->stocked = function (string $name, int $costEach, int $priceEach, int $qtyUnits = 100): int {
        $id = Shop::product($this->actor, $name, $costEach, $priceEach);
        Shop::openingStock($this->actor, $id, $qtyUnits * 1000, $costEach * $qtyUnits, '2026-08-01');

        return $id;
    };
    $this->sale = fn (string $date, array $items, array $tenders, array $extra = []): array => SaleService::createSale([
        'businessDate' => $date,
        'items' => array_map(fn (array $i): array => ['productId' => $i[0], 'qty' => $i[1] * 1000], $items),
        'tenders' => array_map(fn (array $t): array => ['paymentAccountId' => $t[0], 'amount' => $t[1]], $tenders),
        ...$extra,
    ], $this->actor);
    $this->capital = fn (string $date, int $account, int $amount) => CashbookService::recordOwnerCapital(['businessDate' => $date, 'paymentAccountId' => $account, 'amount' => $amount], $this->actor);
    $this->drawings = fn (string $date, int $account, int $amount) => CashbookService::recordOwnerDrawings(['businessDate' => $date, 'paymentAccountId' => $account, 'amount' => $amount], $this->actor);
    $this->expense = fn (string $date, string $category, int $amount, string $description = 'Rent') => Shop::expense($this->actor, $date, Shop::expenseCategory($category), $amount, $description);
});

describe('profit and loss', function () {
    it('computes gross and net profit from real transactions', function () {
        $id = ($this->stocked)('Milo', 500, 1_000);
        ($this->sale)(FR_TODAY, [[$id, 10]], [[$this->cash, 10_000]]);
        ($this->expense)(FR_TODAY, 'Rent', 2_000);
        Shop::income($this->actor, FR_TODAY, Shop::incomeCategory('Commission'), 1_500, 'Commission', ['paymentAccountId' => $this->momo]);

        $pl = FinancialService::getProfitAndLoss(FR_PERIOD);

        expect($pl['salesRevenue'])->toBe(10_000)
            ->and($pl['netSales'])->toBe(10_000)
            ->and($pl['costOfGoodsSold'])->toBe(5_000)
            ->and($pl['grossProfit'])->toBe(5_000)
            ->and($pl['totalOtherIncome'])->toBe(1_500)
            ->and($pl['totalExpenses'])->toBe(2_000)
            // 50.00 gross + 15.00 other income - 20.00 expenses = 45.00
            ->and($pl['netProfit'])->toBe(4_500)
            ->and($pl['grossMarginBp'])->toBe(5_000);
    });

    it('shows discounts and returns as reductions of revenue, not as expenses', function () {
        $id = ($this->stocked)('Milo', 500, 1_000);
        $sale = ($this->sale)(FR_TODAY, [[$id, 10]], [[$this->cash, 9_000]], ['invoiceDiscount' => 1_000, 'allowPriceOverride' => true]);
        $items = ReturnsService::getReturnableSaleItems($sale['saleId']);
        // 2 of 10 units: worth 18.00 after the discount, not 20.00.
        $result = ReturnsService::createCustomerReturn($sale['saleId'], [
            'businessDate' => FR_TODAY,
            'items' => [['itemId' => $items[0]['id'], 'qty' => 2_000]],
            'refunds' => [['paymentAccountId' => $this->cash, 'amount' => 1_800]],
        ], $this->actor);

        expect($result['refunded'])->toBe(1_800)->and($result['creditApplied'])->toBe(0);

        $pl = FinancialService::getProfitAndLoss(FR_PERIOD);
        expect($pl['salesRevenue'])->toBe(10_000)
            ->and($pl['salesDiscounts'])->toBe(1_000)
            ->and($pl['salesReturns'])->toBe(1_800)
            ->and($pl['netSales'])->toBe(7_200)
            ->and(collect($pl['expenses'])->contains(fn (array $l): bool => preg_match('/discount|return/i', $l['name']) === 1))->toBeFalse();
    });

    it('a partial return on a discounted sale refunds only what was paid', function () {
        $id = ($this->stocked)('Milo', 500, 1_000);
        $sale = ($this->sale)(FR_TODAY, [[$id, 10]], [[$this->cash, 9_000]], ['invoiceDiscount' => 1_000, 'allowPriceOverride' => true]);
        expect($sale['total'])->toBe(9_000);
        $items = ReturnsService::getReturnableSaleItems($sale['saleId']);

        expect(fn () => ReturnsService::createCustomerReturn($sale['saleId'], [
            'businessDate' => FR_TODAY,
            'items' => [['itemId' => $items[0]['id'], 'qty' => 2_000]],
            'refunds' => [['paymentAccountId' => $this->cash, 'amount' => 2_000]],
        ], $this->actor))->toThrow(ValidationError::class, 'can be refunded in cash');

        $all = ReturnsService::createCustomerReturn($sale['saleId'], [
            'businessDate' => FR_TODAY,
            'items' => [['itemId' => $items[0]['id'], 'qty' => 10_000]],
            'refunds' => [['paymentAccountId' => $this->cash, 'amount' => 9_000]],
        ], $this->actor);
        expect($all['refunded'])->toBe(9_000);

        $pl = FinancialService::getProfitAndLoss(FR_PERIOD);
        expect($pl['netSales'])->toBe(0)
            ->and($pl['grossProfit'])->toBe(0)
            ->and(FinancialService::getBalanceSheet('2026-08-31')['totalCash'])->toBe(0)
            ->and(FinancialService::getBalanceSheet('2026-08-31')['balances'])->toBeTrue();
    });

    it('ignores a voided sale', function () {
        $id = ($this->stocked)('Milo', 500, 1_000);
        // Dated today: the void's reversal is dated from the clock, so both sit in this month.
        $today = Clock::today();
        $period = Shop::monthOf($today);
        $sale = ($this->sale)($today, [[$id, 10]], [[$this->cash, 10_000]]);

        expect(FinancialService::getProfitAndLoss($period)['netSales'])->toBe(10_000);
        SaleService::voidSale($sale['saleId'], 'Entered twice', $this->actor);
        expect(FinancialService::getProfitAndLoss($period)['netSales'])->toBe(0)
            ->and(FinancialService::getProfitAndLoss($period)['grossProfit'])->toBe(0);
    });

    it('excludes owner drawings from expenses', function () {
        ($this->capital)(FR_TODAY, $this->cash, 100_000);
        ($this->drawings)(FR_TODAY, $this->cash, 20_000);

        $pl = FinancialService::getProfitAndLoss(FR_PERIOD);
        expect($pl['totalExpenses'])->toBe(0)
            ->and($pl['netProfit'])->toBe(0)
            ->and($pl['totalRevenue'])->toBe(0);
    });

    it('respects the period boundaries', function () {
        $id = Shop::product($this->actor, 'Milo', 500, 1_000);
        Shop::openingStock($this->actor, $id, 100_000, 50_000, '2026-07-01');
        ($this->sale)('2026-07-15', [[$id, 5]], [[$this->cash, 5_000]]);
        ($this->sale)('2026-08-15', [[$id, 3]], [[$this->cash, 3_000]]);

        expect(FinancialService::getProfitAndLoss(FR_PERIOD)['netSales'])->toBe(3_000)
            ->and(FinancialService::getProfitAndLoss(['from' => '2026-07-01', 'to' => '2026-07-31'])['netSales'])->toBe(5_000);
    });

    it('returns null margins rather than a misleading zero when there is no revenue', function () {
        $pl = FinancialService::getProfitAndLoss(FR_PERIOD);
        expect($pl['grossMarginBp'])->toBeNull()->and($pl['netMarginBp'])->toBeNull();
    });
});

describe('balance sheet', function () {
    it('balances after a full round of trading', function () {
        $customer = Shop::customer($this->actor, 'Ama');
        $supplier = Shop::supplier($this->actor, 'Depot');
        $id = Shop::product($this->actor, 'Milo', 500, 1_000);

        ($this->capital)('2026-08-01', $this->cash, 100_000);
        PurchaseService::createPurchase(['supplierId' => $supplier, 'businessDate' => '2026-08-02', 'items' => [['productId' => $id, 'qty' => 100_000, 'unitCost' => 500]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 20_000]]], $this->actor);
        ($this->sale)('2026-08-05', [[$id, 30]], [[$this->cash, 10_000]], ['customerId' => $customer]);
        CustomerPaymentService::recordCustomerPayment(['customerId' => $customer, 'businessDate' => '2026-08-06', 'paymentAccountId' => $this->momo, 'amount' => 5_000], $this->actor);
        SupplierPaymentService::recordSupplierPayment(['supplierId' => $supplier, 'businessDate' => '2026-08-07', 'paymentAccountId' => $this->cash, 'amount' => 15_000], $this->actor);
        ($this->expense)('2026-08-08', 'Rent', 8_000);
        ($this->drawings)('2026-08-09', $this->cash, 3_000);

        $sheet = FinancialService::getBalanceSheet('2026-08-31');

        expect($sheet['balances'])->toBeTrue("difference {$sheet['difference']}")
            ->and($sheet['difference'])->toBe(0)
            ->and($sheet['totalAssets'])->toBe($sheet['totalLiabilitiesAndEquity'])
            ->and($sheet['ownersCapital'])->toBe(100_000)
            ->and($sheet['drawings'])->toBe(3_000)
            ->and($sheet['inventory'])->toBeGreaterThan(0)
            ->and($sheet['receivables'])->toBe(15_000)
            ->and($sheet['payables'])->toBe(15_000);
    });

    it('stays balanced at every step of a longer sequence', function () {
        $customer = Shop::customer($this->actor, 'Ama');
        $supplier = Shop::supplier($this->actor, 'Depot');
        $id = Shop::product($this->actor, 'Milo', 500, 1_000);

        $steps = [
            fn () => ($this->capital)('2026-08-01', $this->cash, 50_000),
            fn () => PurchaseService::createPurchase(['supplierId' => $supplier, 'businessDate' => '2026-08-02', 'items' => [['productId' => $id, 'qty' => 50_000, 'unitCost' => 500]], 'tenders' => []], $this->actor),
            fn () => ($this->sale)('2026-08-03', [[$id, 20]], [], ['customerId' => $customer]),
            fn () => ($this->expense)('2026-08-04', 'Transport', 1_500, 'Taxi'),
            fn () => Shop::income($this->actor, '2026-08-05', Shop::incomeCategory('Commission'), 2_000, 'Commission', ['paymentAccountId' => $this->momo]),
            fn () => CustomerPaymentService::recordCustomerPayment(['customerId' => $customer, 'businessDate' => '2026-08-06', 'paymentAccountId' => $this->cash, 'amount' => 12_000], $this->actor),
            fn () => SupplierPaymentService::recordSupplierPayment(['supplierId' => $supplier, 'businessDate' => '2026-08-07', 'paymentAccountId' => $this->cash, 'amount' => 10_000], $this->actor),
            fn () => StockAdjustmentService::createStockAdjustment(['businessDate' => '2026-08-08', 'reason' => AdjustmentReason::Damaged, 'items' => [['productId' => $id, 'direction' => 'OUT', 'qty' => 2_000]]], $this->actor),
            fn () => ($this->drawings)('2026-08-09', $this->cash, 4_000),
        ];

        foreach ($steps as $index => $step) {
            $step();
            $sheet = FinancialService::getBalanceSheet('2026-08-31');
            expect($sheet['balances'])->toBeTrue('after step '.($index + 1).": difference {$sheet['difference']}");
        }
    });

    it('reflects the position as at a date, not today', function () {
        $id = ($this->stocked)('Milo', 500, 1_000);
        ($this->sale)('2026-08-20', [[$id, 10]], [[$this->cash, 10_000]]);

        $before = FinancialService::getBalanceSheet('2026-08-19');
        $after = FinancialService::getBalanceSheet('2026-08-31');

        expect($before['totalCash'])->toBe(0)
            ->and($after['totalCash'])->toBe(10_000)
            ->and($before['balances'])->toBeTrue()
            ->and($after['balances'])->toBeTrue();
    });
});

describe('cash flow', function () {
    it('separates where money came from and went', function () {
        $customer = Shop::customer($this->actor, 'Ama');
        $id = ($this->stocked)('Milo', 500, 1_000);
        ($this->capital)('2026-08-01', $this->cash, 50_000);
        ($this->sale)('2026-08-05', [[$id, 10]], [[$this->cash, 10_000]]);
        ($this->sale)('2026-08-06', [[$id, 5]], [], ['customerId' => $customer]);
        CustomerPaymentService::recordCustomerPayment(['customerId' => $customer, 'businessDate' => '2026-08-07', 'paymentAccountId' => $this->cash, 'amount' => 2_000], $this->actor);
        ($this->expense)('2026-08-08', 'Rent', 8_000);

        $flow = FinancialService::getCashFlow(FR_PERIOD);
        $byType = collect($flow['lines'])->keyBy('sourceType');

        expect($byType['CAPITAL']['inMinor'])->toBe(50_000)
            ->and($byType['SALE']['inMinor'])->toBe(10_000)
            ->and($byType['CUSTOMER_PAYMENT']['inMinor'])->toBe(2_000)
            ->and($byType['EXPENSE']['outMinor'])->toBe(8_000)
            // The credit sale contributed NO cash — only the later payment did.
            ->and($byType['SALE']['inMinor'])->toBe(10_000)
            ->and($flow['totalIn'])->toBe(62_000)
            ->and($flow['totalOut'])->toBe(8_000)
            ->and($flow['netMovement'])->toBe(54_000)
            ->and($flow['closingBalance'])->toBe(54_000)
            ->and($flow['reconciles'])->toBeTrue();
    });

    it('carries an opening balance from before the period', function () {
        ($this->capital)('2026-07-01', $this->cash, 30_000);
        ($this->expense)('2026-08-05', 'Rent', 5_000);

        $flow = FinancialService::getCashFlow(FR_PERIOD);
        expect($flow['openingBalance'])->toBe(30_000)
            ->and($flow['totalOut'])->toBe(5_000)
            ->and($flow['closingBalance'])->toBe(25_000);
    });

    it('agrees with the balance sheet cash figure', function () {
        $id = ($this->stocked)('Milo', 500, 1_000);
        ($this->capital)('2026-08-01', $this->cash, 50_000);
        ($this->sale)('2026-08-05', [[$id, 10]], [[$this->momo, 10_000]]);

        expect(FinancialService::getCashFlow(FR_PERIOD)['closingBalance'])->toBe(FinancialService::getBalanceSheet('2026-08-31')['totalCash']);
    });

    it('can be filtered to one account', function () {
        ($this->capital)('2026-08-01', $this->cash, 50_000);
        ($this->capital)('2026-08-01', $this->momo, 20_000);

        expect(FinancialService::getCashFlow(FR_PERIOD, $this->cash)['totalIn'])->toBe(50_000)
            ->and(FinancialService::getCashFlow(FR_PERIOD, $this->momo)['totalIn'])->toBe(20_000)
            ->and(FinancialService::getCashFlow(FR_PERIOD)['totalIn'])->toBe(70_000);
    });
});

describe('operational reports', function () {
    beforeEach(function () {
        $this->trading = function (): array {
            $customer = Shop::customer($this->actor, 'Ama');
            $milo = ($this->stocked)('Milo', 500, 1_000, 100);
            $bread = ($this->stocked)('Bread', 800, 1_200, 50);
            ($this->sale)('2026-08-05', [[$milo, 10], [$bread, 5]], [[$this->cash, 16_000]]);
            ($this->sale)('2026-08-06', [[$milo, 4]], [[$this->momo, 2_000]], ['customerId' => $customer]);

            return compact('customer', 'milo', 'bread');
        };
    });

    it('breaks sales down by day, product, category, customer and method', function () {
        ($this->trading)();

        $byDay = OperationsService::getSalesByDay(FR_PERIOD);
        expect($byDay)->toHaveCount(2)
            ->and($byDay[0]['total'])->toBe(16_000)
            ->and($byDay[1]['total'])->toBe(4_000);

        $milo = collect(OperationsService::getSalesByProduct(FR_PERIOD))->firstWhere('productName', 'Milo');
        expect($milo['qtySold'])->toBe(14_000)
            ->and($milo['revenue'])->toBe(14_000)
            ->and($milo['cost'])->toBe(7_000)
            ->and($milo['profit'])->toBe(7_000)
            ->and($milo['marginBp'])->toBe(5_000);

        $byCustomer = collect(OperationsService::getSalesByCustomer(FR_PERIOD));
        expect($byCustomer->firstWhere('customerName', 'Walk-in customers')['total'])->toBe(16_000)
            ->and($byCustomer->firstWhere('customerName', 'Ama')['total'])->toBe(4_000);

        $byMethod = collect(OperationsService::getSalesByPaymentMethod(FR_PERIOD));
        expect($byMethod->firstWhere('kind', 'CASH')['received'])->toBe(16_000)
            ->and($byMethod->firstWhere('kind', 'MOBILE_MONEY')['received'])->toBe(2_000)
            ->and(OperationsService::getSalesByCategory(FR_PERIOD)[0]['categoryName'])->toBe('Uncategorised');
    });

    it('sales report totals tie to the profit and loss', function () {
        ($this->trading)();
        $byDay = OperationsService::getSalesByDay(FR_PERIOD);
        $pl = FinancialService::getProfitAndLoss(FR_PERIOD);

        expect(Money::sum(array_column($byDay, 'total')))->toBe($pl['netSales'])
            ->and(Money::sum(array_column($byDay, 'cogs')))->toBe($pl['costOfGoodsSold'])
            ->and(Money::sum(array_column($byDay, 'profit')))->toBe($pl['grossProfit']);
    });

    it('values stock at cost and shows what it would fetch', function () {
        ($this->stocked)('Milo', 500, 1_000, 20);
        $valuation = OperationsService::getStockValuation();
        $milo = collect($valuation['rows'])->firstWhere('productName', 'Milo');

        expect($milo['qtyOnHand'])->toBe(20_000)
            ->and($milo['stockValue'])->toBe(10_000)
            ->and($milo['retailValue'])->toBe(20_000)
            ->and($milo['potentialProfit'])->toBe(10_000)
            ->and($valuation['totalCostValue'])->toBe(10_000);
    });

    it('summarises stock movement for a period', function () {
        $id = ($this->stocked)('Milo', 500, 1_000);
        ($this->sale)('2026-08-05', [[$id, 10]], [[$this->cash, 10_000]]);
        $milo = collect(OperationsService::getStockMovementSummary(FR_PERIOD))->firstWhere('productName', 'Milo');

        expect($milo['qtyIn'])->toBe(100_000)
            ->and($milo['qtyOut'])->toBe(10_000)
            ->and($milo['netQty'])->toBe(90_000)
            ->and($milo['closingQty'])->toBe(90_000);
    });

    it('breaks purchases down by supplier', function () {
        $supplier = Shop::supplier($this->actor, 'Depot');
        $id = Shop::product($this->actor, 'Milo', 500, 1_000);
        PurchaseService::createPurchase(['supplierId' => $supplier, 'businessDate' => '2026-08-03', 'items' => [['productId' => $id, 'qty' => 20_000, 'unitCost' => 500]], 'tenders' => []], $this->actor);

        $bySupplier = OperationsService::getPurchasesBySupplier(FR_PERIOD);
        expect($bySupplier)->toHaveCount(1)
            ->and($bySupplier[0]['supplierName'])->toBe('Depot')
            ->and($bySupplier[0]['total'])->toBe(10_000);
    });
});
