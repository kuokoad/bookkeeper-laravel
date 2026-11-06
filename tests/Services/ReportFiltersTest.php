<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/report-filters.test.ts: filters on the
| sales, purchase, inventory and cash-flow reports. A filtered report must
| still tie back to the ledger, and a period with no trading says nothing.
| The whole old file is ported. The last block ("the report parsers") is new:
| the old app had no test of parseSalesReportFilters and friends, which the
| CSV exports now share with the report pages.
*/

use App\Domain\Money;
use App\Enums\AdjustmentReason;
use App\Services\CatalogService;
use App\Services\PurchaseService;
use App\Services\Reporting\FinancialService;
use App\Services\Reporting\OperationsService as Ops;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use App\Support\Filters\ListFilters;
use Tests\Support\Shop;

const RF_AUGUST = ['from' => '2026-08-01', 'to' => '2026-08-31'];
const RF_JANUARY = ['from' => '2026-01-01', 'to' => '2026-01-31'];

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->momo = Shop::paymentAccount('MOBILE_MONEY');
    $this->drinks = CatalogService::createCategory(['name' => 'Drinks'], $this->actor);
    $this->food = CatalogService::createCategory(['name' => 'Food'], $this->actor);
    $this->coke = Shop::product($this->actor, 'Coca-Cola 500ml', 300, 500, ['categoryId' => $this->drinks]);
    $this->rice = Shop::product($this->actor, 'Rice 5kg', 4_000, 6_000, ['categoryId' => $this->food, 'unit' => 'bag']);
    $this->kofi = Shop::customer($this->actor, 'Kofi Mensah');
    $this->ama = Shop::customer($this->actor, 'Ama Serwaa');
    $this->kasapreko = Shop::supplier($this->actor, 'Kasapreko Distributors');
    $this->madina = Shop::supplier($this->actor, 'Madina Market Wholesale');

    StockAdjustmentService::createStockAdjustment([
        'businessDate' => '2026-07-31',
        'reason' => AdjustmentReason::OpeningStock,
        'items' => [
            ['productId' => $this->coke, 'direction' => 'IN', 'qty' => 500_000, 'totalCost' => 150_000],
            ['productId' => $this->rice, 'direction' => 'IN', 'qty' => 100_000, 'totalCost' => 400_000],
        ],
    ], $this->actor);

    $this->sale = fn (string $date, int $product, int $quantity, int $unitPrice, ?int $customer = null, ?int $account = null) => SaleService::createSale([
        'businessDate' => $date,
        'customerId' => $customer,
        'items' => [['productId' => $product, 'qty' => $quantity * 1000]],
        'tenders' => [['paymentAccountId' => $account ?? $this->cash, 'amount' => $quantity * $unitPrice]],
    ], $this->actor);
    // 10 Cokes (50.00) and a bag of rice (60.00) on one receipt.
    $this->mixed = fn (?int $customer = null) => SaleService::createSale([
        'businessDate' => '2026-08-05',
        'customerId' => $customer,
        'items' => [['productId' => $this->coke, 'qty' => 10_000], ['productId' => $this->rice, 'qty' => 1_000]],
        'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 11_000]],
    ], $this->actor);
    $this->delivery = fn (string $date, int $supplier, int $product, int $quantity, int $unitCost, ?int $account = null) => PurchaseService::createPurchase([
        'supplierId' => $supplier,
        'businessDate' => $date,
        'items' => [['productId' => $product, 'qty' => $quantity * 1000, 'unitCost' => $unitCost]],
        'tenders' => [['paymentAccountId' => $account ?? $this->cash, 'amount' => $quantity * $unitCost]],
    ], $this->actor);
});

$total = fn (array $rows, string $key = 'total'): int => Money::sum(array_column($rows, $key));

describe('the sales report', function () use ($total) {
    it('narrows every table by date', function () use ($total) {
        ($this->sale)('2026-07-15', $this->coke, 10, 500);
        ($this->sale)('2026-08-05', $this->coke, 10, 500);
        ($this->sale)('2026-09-05', $this->coke, 10, 500);

        $byDay = Ops::getSalesByDay(RF_AUGUST);
        expect($byDay)->toHaveCount(1)
            ->and($byDay[0]['businessDate'])->toBe('2026-08-05')
            ->and($total($byDay))->toBe(5_000);
    });

    it('narrows by customer', function () use ($total) {
        ($this->sale)('2026-08-05', $this->coke, 10, 500, $this->kofi);
        ($this->sale)('2026-08-06', $this->coke, 4, 500, $this->ama);

        $filters = [...RF_AUGUST, 'customerId' => $this->kofi];
        expect($total(Ops::getSalesByDay($filters)))->toBe(5_000)
            ->and(Ops::getSalesByCustomer($filters))->toHaveCount(1);
    });

    it('narrows by payment method, and the tender table agrees', function () use ($total) {
        ($this->sale)('2026-08-05', $this->coke, 10, 500, account: $this->cash);
        ($this->sale)('2026-08-06', $this->coke, 4, 500, account: $this->momo);

        $cashOnly = [...RF_AUGUST, 'paymentAccountId' => $this->cash];
        $byMethod = Ops::getSalesByPaymentMethod($cashOnly);
        expect($total(Ops::getSalesByDay($cashOnly)))->toBe(5_000)
            ->and($byMethod)->toHaveCount(1)
            ->and($byMethod[0]['received'])->toBe(5_000);
    });

    it('narrows the by-product table to the matching lines only', function () {
        ($this->mixed)();

        $byProduct = Ops::getSalesByProduct([...RF_AUGUST, 'productId' => $this->coke]);
        $byCategory = Ops::getSalesByCategory([...RF_AUGUST, 'categoryId' => $this->drinks]);
        expect($byProduct)->toHaveCount(1)
            ->and($byProduct[0]['productId'])->toBe($this->coke)
            ->and($byProduct[0]['revenue'])->toBe(5_000)
            ->and($byCategory)->toHaveCount(1)
            ->and($byCategory[0]['revenue'])->toBe(5_000);
    });

    it('reports a day in line money, not in whole receipts', function () {
        ($this->mixed)();

        $byDay = Ops::getSalesLinesByDay([...RF_AUGUST, 'productId' => $this->coke]);
        expect($byDay)->toHaveCount(1)
            ->and($byDay[0]['revenue'])->toBe(5_000)
            // The receipt is counted once, not once per line on it.
            ->and($byDay[0]['saleCount'])->toBe(1)
            ->and(Ops::getSalesByDay(RF_AUGUST)[0]['total'])->toBe(11_000);
    });

    it('reports a customer in line money too', function () {
        ($this->mixed)($this->kofi);

        $byCustomer = Ops::getSalesLinesByCustomer([...RF_AUGUST, 'productId' => $this->coke]);
        expect($byCustomer)->toHaveCount(1)
            ->and($byCustomer[0]['customerName'])->toBe('Kofi Mensah')
            ->and($byCustomer[0]['revenue'])->toBe(5_000);
    });

    it('counts one receipt once however many matching lines it carries', function () {
        SaleService::createSale([
            'businessDate' => '2026-08-05',
            'items' => [['productId' => $this->coke, 'qty' => 4_000], ['productId' => $this->coke, 'qty' => 6_000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 5_000]],
        ], $this->actor);

        $byDay = Ops::getSalesLinesByDay([...RF_AUGUST, 'productId' => $this->coke]);
        expect($byDay[0]['saleCount'])->toBe(1)->and($byDay[0]['revenue'])->toBe(5_000);
    });

    it('gives line revenue that agrees with the by-product table', function () use ($total) {
        ($this->sale)('2026-08-05', $this->coke, 10, 500);
        ($this->sale)('2026-08-06', $this->coke, 4, 500);
        ($this->sale)('2026-08-07', $this->rice, 1, 6_000);

        $filters = [...RF_AUGUST, 'productId' => $this->coke];
        $fromDays = $total(Ops::getSalesLinesByDay($filters), 'revenue');
        expect($fromDays)->toBe($total(Ops::getSalesByProduct($filters), 'revenue'))->and($fromDays)->toBe(7_000);
    });

    it('narrows line money by category as well as by product', function () {
        ($this->mixed)();
        expect(Ops::getSalesLinesByDay([...RF_AUGUST, 'categoryId' => $this->drinks])[0]['revenue'])->toBe(5_000)
            ->and(Ops::getSalesLinesByDay([...RF_AUGUST, 'categoryId' => $this->food])[0]['revenue'])->toBe(6_000);
    });

    it('says nothing for a product that did not sell in the period', function () {
        ($this->sale)('2026-08-05', $this->coke, 10, 500);
        expect(Ops::getSalesLinesByDay([...RF_AUGUST, 'productId' => $this->rice]))->toBe([])
            ->and(Ops::getSalesLinesByCustomer([...RF_AUGUST, 'productId' => $this->rice]))->toBe([]);
    });

    it('returns nothing at all for a period with no trading', function () {
        ($this->sale)('2026-08-05', $this->coke, 10, 500);
        expect(Ops::getSalesByDay(RF_JANUARY))->toBe([])
            ->and(Ops::getSalesByProduct(RF_JANUARY))->toBe([])
            ->and(Ops::getSalesByCategory(RF_JANUARY))->toBe([])
            ->and(Ops::getSalesByCustomer(RF_JANUARY))->toBe([])
            ->and(Ops::getSalesByPaymentMethod(RF_JANUARY))->toBe([]);
    });

    it('returns nothing for a filter that matches no sales', function () {
        ($this->sale)('2026-08-05', $this->coke, 10, 500);
        expect(Ops::getSalesByDay([...RF_AUGUST, 'customerId' => 9_999]))->toBe([])
            ->and(Ops::getSalesByProduct([...RF_AUGUST, 'productId' => 9_999]))->toBe([]);
    });

    it('still ties to the Profit & Loss when no filter is applied', function () use ($total) {
        ($this->sale)('2026-08-05', $this->coke, 10, 500);
        ($this->sale)('2026-08-06', $this->rice, 2, 6_000);

        $byDay = Ops::getSalesByDay(RF_AUGUST);
        $pl = FinancialService::getProfitAndLoss(RF_AUGUST);
        expect($total($byDay, 'net'))->toBe($pl['netSales'])
            ->and($total($byDay, 'cogs'))->toBe($pl['costOfGoodsSold']);
    });
});

describe('the purchase report', function () use ($total) {
    it('narrows by supplier, product, category and payment method', function () use ($total) {
        ($this->delivery)('2026-08-02', $this->kasapreko, $this->coke, 100, 300);
        ($this->delivery)('2026-08-03', $this->madina, $this->rice, 10, 4_000, $this->momo);

        expect(Ops::getPurchasesBySupplier([...RF_AUGUST, 'supplierId' => $this->kasapreko]))->toHaveCount(1)
            ->and(Ops::getPurchasesByProduct([...RF_AUGUST, 'productId' => $this->rice]))->toHaveCount(1)
            ->and($total(Ops::getPurchasesByDay([...RF_AUGUST, 'categoryId' => $this->food])))->toBe(40_000)
            ->and($total(Ops::getPurchasesByDay([...RF_AUGUST, 'paymentAccountId' => $this->momo])))->toBe(40_000);
    });

    it('says nothing for a period with no deliveries', function () {
        ($this->delivery)('2026-08-02', $this->kasapreko, $this->coke, 100, 300);
        expect(Ops::getPurchasesByDay(RF_JANUARY))->toBe([])
            ->and(Ops::getPurchasesBySupplier(RF_JANUARY))->toBe([])
            ->and(Ops::getPurchasesByProduct(RF_JANUARY))->toBe([]);
    });
});

describe('the inventory report', function () use ($total) {
    it('narrows the valuation by category, and its totals follow', function () use ($total) {
        $drinksOnly = Ops::getStockValuation(['categoryId' => $this->drinks]);
        expect(Ops::getStockValuation()['rows'])->toHaveCount(2)
            ->and($drinksOnly['rows'])->toHaveCount(1)
            ->and($drinksOnly['totalCostValue'])->toBe(150_000)
            ->and($drinksOnly['totalCostValue'])->toBe($total($drinksOnly['rows'], 'stockValue'));
    });

    it('narrows by stock status', function () {
        ($this->sale)('2026-08-05', $this->rice, 100, 6_000);

        $out = Ops::getStockValuation(['stockStatus' => 'out']);
        expect($out['rows'])->toHaveCount(1)
            ->and($out['rows'][0]['productName'])->toBe('Rice 5kg')
            ->and($out['outOfStockCount'])->toBe(1);
    });

    it('narrows by the supplier who delivered the product', function () {
        ($this->delivery)('2026-08-02', $this->kasapreko, $this->coke, 10, 300);
        expect(Ops::getStockValuation(['supplierId' => $this->kasapreko])['rows'])->toHaveCount(1)
            ->and(Ops::getStockValuation(['supplierId' => $this->madina])['rows'])->toBe([]);
    });

    it('reports zero rather than NaN when a filter matches nothing', function () {
        $nothing = Ops::getStockValuation(['categoryId' => 9_999]);
        expect($nothing['rows'])->toBe([])
            ->and($nothing['totalCostValue'])->toBe(0)
            ->and($nothing['totalRetailValue'])->toBe(0)
            ->and($nothing['lowStockCount'])->toBe(0)
            ->and($nothing['outOfStockCount'])->toBe(0);
    });
});

describe('the cash flow report', function () {
    it('opens each account from what it held before the window', function () {
        ($this->sale)('2026-07-20', $this->coke, 10, 500);
        ($this->sale)('2026-08-05', $this->coke, 4, 500);

        $flow = FinancialService::getCashFlow(RF_AUGUST);
        expect($flow['openingBalance'])->toBe(5_000)
            ->and($flow['totalIn'])->toBe(2_000)
            ->and($flow['closingBalance'])->toBe(7_000)
            ->and($flow['reconciles'])->toBeTrue();
    });

    it('narrows to one account, and still reconciles', function () {
        ($this->sale)('2026-07-20', $this->coke, 10, 500, account: $this->cash);
        ($this->sale)('2026-08-05', $this->coke, 4, 500, account: $this->momo);

        $momo = FinancialService::getCashFlow(RF_AUGUST, $this->momo);
        $cash = FinancialService::getCashFlow(RF_AUGUST, $this->cash);
        expect($momo['openingBalance'])->toBe(0)
            ->and($momo['totalIn'])->toBe(2_000)
            ->and($momo['closingBalance'])->toBe(2_000)
            ->and($momo['reconciles'])->toBeTrue()
            ->and($cash['openingBalance'])->toBe(5_000)
            ->and($cash['totalIn'])->toBe(0)
            ->and($cash['closingBalance'])->toBe(5_000);
    });

    it('reconciles over a period in which nothing moved', function () {
        ($this->sale)('2026-07-20', $this->coke, 10, 500);
        $flow = FinancialService::getCashFlow(RF_AUGUST);
        expect($flow['lines'])->toBe([])
            ->and($flow['openingBalance'])->toBe($flow['closingBalance'])
            ->and($flow['reconciles'])->toBeTrue();
    });
});

describe('the report parsers', function () {
    it('takes the period and validated ids, and drops junk', function () {
        $parsed = ListFilters::parseSalesReportFilters(['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-15', 'customer' => '7', 'product' => 'abc', 'account' => '0'], '2026-08-20');
        expect($parsed['filters'])->toBe(['from' => '2026-08-01', 'to' => '2026-08-15', 'customerId' => 7])
            ->and($parsed['carried'])->toBe(['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-15', 'customer' => '7', 'product' => 'abc', 'account' => '0']);
    });

    it('reads a supplier on the purchase report and none of the sales keys', function () {
        $parsed = ListFilters::parsePurchaseReportFilters(['supplier' => '3', 'customer' => '4'], '2026-08-20');
        expect($parsed['filters'])->toBe(['from' => '2026-08-01', 'to' => '2026-08-20', 'supplierId' => 3])
            ->and($parsed['preset'])->toBe('month');
    });

    it('gives the inventory report no dates and a whitelisted stock status', function () {
        expect(ListFilters::parseInventoryReportFilters(['category' => '2', 'stock' => 'low'])['filters'])->toBe(['categoryId' => 2, 'stockStatus' => 'low'])
            ->and(ListFilters::parseInventoryReportFilters(['stock' => 'DROP TABLE'])['filters'])->toBe([]);
    });

    it('takes one account for the cash flow', function () {
        $parsed = ListFilters::parseCashFlowFilters(['period' => 'today', 'account' => '2'], '2026-08-20');
        expect($parsed['filters'])->toBe(['from' => '2026-08-20', 'to' => '2026-08-20'])
            ->and($parsed['accountId'])->toBe(2)
            ->and($parsed['carried'])->toBe(['period' => 'today', 'account' => '2']);
    });
});
