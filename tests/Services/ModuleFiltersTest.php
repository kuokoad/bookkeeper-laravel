<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/module-filters.test.ts: purchase,
| product, stock, customer/supplier and (since Phase 6) expense filters. A
| filter narrows in SQL before any page limit, and totals come from the same
| clause as the rows.
*/

use App\Enums\AdjustmentReason;
use App\Services\CashbookService;
use App\Services\CatalogService;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\PaymentAccountService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use App\Services\SupplierService;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->drinks = CatalogService::createCategory(['name' => 'Drinks'], $this->actor);
    $this->food = CatalogService::createCategory(['name' => 'Food'], $this->actor);
    $this->coke = CatalogService::createProduct(['name' => 'Coca-Cola 500ml', 'sku' => 'COKE-500', 'categoryId' => $this->drinks, 'costPrice' => 300, 'sellingPrice' => 500, 'unit' => 'pcs', 'minStock' => 10_000], $this->actor);
    $this->rice = CatalogService::createProduct(['name' => 'Rice 5kg', 'sku' => 'RICE-5', 'categoryId' => $this->food, 'costPrice' => 4_000, 'sellingPrice' => 6_000, 'unit' => 'bag', 'minStock' => 5_000], $this->actor);
    $this->stock = fn (int $id, int $units, int $cost) => Shop::openingStock($this->actor, $id, $units * 1000, $cost, '2026-08-01');
    $this->filler = fn (string $name, array $extra = []): int => CatalogService::createProduct(['name' => $name, 'costPrice' => 100, 'sellingPrice' => 200, 'unit' => 'pcs', 'trackInventory' => false, ...$extra], $this->actor);
    $this->sale = fn (int $id, int $units, string $date, ?int $customerId = null) => SaleService::createSale([
        'businessDate' => $date,
        'customerId' => $customerId,
        'items' => [['productId' => $id, 'qty' => $units * 1000]],
        'tenders' => $customerId === null ? [['paymentAccountId' => Shop::paymentAccount('CASH'), 'amount' => $units * (int) Shop::productRow($id)->selling_price_minor]] : [],
    ], $this->actor);
    $this->cash = Shop::paymentAccount('CASH');
    $this->momo = Shop::paymentAccount('MOBILE_MONEY');
    $this->kasapreko = Shop::supplier($this->actor, 'Kasapreko Distributors', ['phone' => '0302000111']);
    $this->madina = Shop::supplier($this->actor, 'Madina Market Wholesale', ['phone' => '0244777888']);
    $this->delivery = fn (string $date, int $supplierId, int $productId, int $units, int $unitCost, int $paid, ?int $account = null): int => Shop::purchase(
        $this->actor,
        $supplierId,
        [[$productId, $units * 1000, $unitCost]],
        $paid > 0 ? [['paymentAccountId' => $account ?? $this->cash, 'amount' => $paid]] : [],
        ['businessDate' => $date],
    )['purchaseId'];
});

describe('purchase filters', function () {
    it('narrows by supplier, product, category and payment method', function () {
        ($this->delivery)('2026-08-02', $this->kasapreko, $this->coke, 100, 300, 30_000);
        ($this->delivery)('2026-08-03', $this->madina, $this->rice, 10, 4_000, 40_000, $this->momo);

        $range = ['from' => '2026-08-01', 'to' => '2026-08-31'];
        expect(PurchaseService::countPurchases([...$range, 'supplierId' => $this->kasapreko]))->toBe(1)
            ->and(PurchaseService::countPurchases([...$range, 'productId' => $this->rice]))->toBe(1)
            ->and(PurchaseService::countPurchases([...$range, 'categoryId' => $this->drinks]))->toBe(1)
            ->and(PurchaseService::countPurchases([...$range, 'paymentKind' => 'MOBILE_MONEY']))->toBe(1)
            ->and(PurchaseService::countPurchases([...$range, 'paymentAccountId' => $this->cash]))->toBe(1);
    });

    it('tells fully paid from partly paid from outstanding', function () {
        ($this->delivery)('2026-08-02', $this->kasapreko, $this->coke, 100, 300, 30_000); // paid in full
        ($this->delivery)('2026-08-03', $this->madina, $this->rice, 10, 4_000, 10_000); // part paid
        ($this->delivery)('2026-08-04', $this->madina, $this->rice, 5, 4_000, 0); // nothing paid

        $range = ['from' => '2026-08-01', 'to' => '2026-08-31'];
        expect(PurchaseService::countPurchases([...$range, 'paymentState' => 'paid']))->toBe(1)
            ->and(PurchaseService::countPurchases([...$range, 'paymentState' => 'partial']))->toBe(1)
            // "Outstanding" covers both the part-paid and the unpaid.
            ->and(PurchaseService::countPurchases([...$range, 'paymentState' => 'outstanding']))->toBe(2);
    });

    it('finds a credit delivery sitting past the page limit', function () {
        $owing = ($this->delivery)('2026-08-01', $this->madina, $this->rice, 5, 4_000, 0);
        for ($day = 2; $day <= 20; $day++) {
            ($this->delivery)('2026-08-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT), $this->kasapreko, $this->coke, 10, 300, 3_000);
        }

        $filters = ['from' => '2026-08-01', 'to' => '2026-08-31', 'paymentState' => 'outstanding'];
        expect(PurchaseService::countPurchases($filters))->toBe(1)
            ->and(PurchaseService::listPurchases([...$filters, 'limit' => 5])[0]['id'])->toBe($owing);
    });

    it('searches purchase number, supplier and product', function () {
        $id = ($this->delivery)('2026-08-02', $this->kasapreko, $this->coke, 100, 300, 30_000);
        $row = collect(PurchaseService::listPurchases())->firstWhere('id', $id);

        $range = ['from' => '2026-08-01', 'to' => '2026-08-31'];
        foreach ([$row['purchaseNo'], 'kasapreko', '0302', 'coca', 'COKE-500'] as $term) {
            expect(array_column(PurchaseService::listPurchases([...$range, 'search' => $term]), 'id'))->toContain($id);
        }
    });

    it('totals only the deliveries the filter selects', function () {
        ($this->delivery)('2026-08-02', $this->kasapreko, $this->coke, 100, 300, 30_000); // 300.00
        ($this->delivery)('2026-08-03', $this->madina, $this->rice, 10, 4_000, 10_000); // 400.00, 300.00 owing
        ($this->delivery)('2026-09-02', $this->kasapreko, $this->coke, 100, 300, 30_000); // outside the window

        $filters = ['from' => '2026-08-01', 'to' => '2026-08-31'];
        $summary = PurchaseService::getFilteredPurchasesSummary($filters);
        $rows = PurchaseService::listPurchases($filters);

        expect($summary['count'])->toBe(2)
            ->and($summary['total'])->toBe(array_sum(array_column($rows, 'totalMinor')))
            ->and($summary['paid'])->toBe(40_000)
            ->and($summary['outstanding'])->toBe(30_000)
            ->and($summary['total'])->toBe($summary['paid'] + $summary['outstanding']);
    });

    it('reports nothing rather than throwing when nothing matches', function () {
        ($this->delivery)('2026-08-02', $this->kasapreko, $this->coke, 100, 300, 30_000);
        $filters = ['from' => '2026-01-01', 'to' => '2026-01-31'];

        expect(PurchaseService::countPurchases($filters))->toBe(0)
            ->and(PurchaseService::getFilteredPurchasesSummary($filters))->toMatchArray(['count' => 0, 'total' => 0, 'paid' => 0, 'outstanding' => 0]);
    });

    it('pages without repeating a delivery', function () {
        for ($day = 1; $day <= 15; $day++) {
            ($this->delivery)('2026-08-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT), $this->kasapreko, $this->coke, 10, 300, 3_000);
        }
        $filters = ['from' => '2026-08-01', 'to' => '2026-08-31'];
        expect(PurchaseService::countPurchases($filters))->toBe(15);

        $seen = [];
        for ($page = 0; $page < 3; $page++) {
            foreach (PurchaseService::listPurchases([...$filters, 'limit' => 6, 'offset' => $page * 6]) as $row) {
                expect($seen)->not->toHaveKey($row['id']);
                $seen[$row['id']] = true;
            }
        }
        expect($seen)->toHaveCount(15);
    });

    it('treats a wildcard in the search as the character it is', function () {
        ($this->delivery)('2026-08-02', $this->kasapreko, $this->coke, 100, 300, 30_000);
        expect(PurchaseService::countPurchases(['search' => '%']))->toBe(0)
            ->and(SupplierService::countSuppliers(['search' => '_']))->toBe(0);
    });
});

describe('product and stock-status filters', function () {
    it('finds low stock and out of stock, in SQL', function () {
        ($this->stock)($this->coke, 3, 900); // below its minimum of 10
        // RICE is left at zero, so it is out of stock.

        expect(CatalogService::countProducts(['stockStatus' => 'low']))->toBe(2) // out counts as low
            ->and(CatalogService::countProducts(['stockStatus' => 'out']))->toBe(1)
            ->and(CatalogService::listProducts(['stockStatus' => 'out'])[0]['id'])->toBe($this->rice);
    });

    it('finds a low-stock product sorted past the page limit', function () {
        // Enough products that the low-stock one cannot be on the first page by name.
        for ($index = 0; $index < 30; $index++) {
            ($this->filler)('Aaa filler '.str_pad((string) $index, 3, '0', STR_PAD_LEFT), ['minStock' => 0]);
        }
        ($this->stock)($this->coke, 3, 900);

        expect(array_column(CatalogService::listProducts(['stockStatus' => 'low', 'limit' => 5]), 'id'))->toContain($this->coke);
    });

    it('narrows by category and by the supplier who delivered it', function () {
        ($this->delivery)('2026-08-02', $this->kasapreko, $this->coke, 100, 300, 30_000);

        expect(CatalogService::countProducts(['categoryId' => $this->drinks]))->toBe(1)
            ->and(CatalogService::countProducts(['supplierId' => $this->kasapreko]))->toBe(1)
            ->and(CatalogService::countProducts(['supplierId' => $this->madina]))->toBe(0);
    });

    it('searches name, SKU and barcode', function () {
        expect(array_column(CatalogService::listProducts(['search' => 'coca']), 'id'))->toBe([$this->coke])
            ->and(array_column(CatalogService::listProducts(['search' => 'RICE-5']), 'id'))->toBe([$this->rice])
            ->and(CatalogService::listProducts(['search' => 'zzz']))->toBe([]);
    });

    it('sorts by stock value and by quantity, ascending and descending', function () {
        ($this->stock)($this->coke, 100, 30_000);
        ($this->stock)($this->rice, 2, 8_000);

        expect(CatalogService::listProducts(['sort' => 'value', 'direction' => 'desc'])[0]['id'])->toBe($this->coke)
            ->and(CatalogService::listProducts(['sort' => 'quantity', 'direction' => 'asc'])[0]['id'])->toBe($this->rice);
    });

    it('summarises the whole catalogue, not just the first page of it', function () {
        ($this->stock)($this->coke, 100, 30_000);
        ($this->stock)($this->rice, 2, 8_000);
        for ($index = 0; $index < 600; $index++) {
            ($this->filler)('Zzz filler '.str_pad((string) $index, 4, '0', STR_PAD_LEFT));
        }

        $summary = CatalogService::getStockSummary();
        expect($summary['productCount'])->toBe(602)
            ->and($summary['totalStockValue'])->toBe(38_000)
            // RICE holds 2 against a minimum of 5, so it is low but not out.
            ->and($summary['lowStockCount'])->toBe(1)
            ->and($summary['outOfStockCount'])->toBe(0);
    });

    it('describes exactly the products a filter selects', function () {
        ($this->stock)($this->coke, 100, 30_000);
        ($this->stock)($this->rice, 2, 8_000);

        $selection = CatalogService::getStockSummary(['categoryId' => $this->food]);
        expect($selection['productCount'])->toBe(1)->and($selection['totalStockValue'])->toBe(8_000);
    });
});

describe('stock movement filters', function () {
    it('narrows by product, category, movement type and date', function () {
        StockAdjustmentService::createStockAdjustment([
            'businessDate' => '2026-08-01',
            'reason' => AdjustmentReason::OpeningStock,
            'items' => [
                ['productId' => $this->coke, 'direction' => 'IN', 'qty' => 100_000, 'totalCost' => 30_000],
                ['productId' => $this->rice, 'direction' => 'IN', 'qty' => 10_000, 'totalCost' => 40_000],
            ],
        ], $this->actor);
        ($this->sale)($this->coke, 5, '2026-08-05');

        expect(InventoryService::countStockLedger())->toBe(3)
            ->and(InventoryService::countStockLedger(['productId' => $this->coke]))->toBe(2)
            ->and(InventoryService::countStockLedger(['categoryId' => $this->food]))->toBe(1)
            ->and(InventoryService::countStockLedger(['movementType' => 'SALE']))->toBe(1)
            ->and(InventoryService::countStockLedger(['from' => '2026-08-05', 'to' => '2026-08-05']))->toBe(1);
    });

    it('searches the product name, SKU and the source reference', function () {
        ($this->stock)($this->coke, 100, 30_000);

        expect(InventoryService::countStockLedger(['search' => 'coca']))->toBe(1)
            ->and(InventoryService::countStockLedger(['search' => 'COKE-500']))->toBe(1)
            ->and(InventoryService::countStockLedger(['search' => 'zzz']))->toBe(0);
    });

    it('never alters the recorded balances', function () {
        ($this->stock)($this->coke, 100, 30_000);
        ($this->sale)($this->coke, 5, '2026-08-05');

        $unfiltered = InventoryService::getStockLedger(['productId' => $this->coke]);
        $narrowed = InventoryService::getStockLedger(['productId' => $this->coke, 'movementType' => 'SALE']);

        $sale = collect($unfiltered)->firstWhere('movementType', 'SALE');
        expect($narrowed)->toHaveCount(1)
            ->and($narrowed[0]['balanceQty'])->toBe($sale['balanceQty'])
            ->and($narrowed[0]['balanceValue'])->toBe($sale['balanceValue'])
            // And the product's own cached figures are untouched by any of this.
            ->and(CatalogService::listProducts(['id' => $this->coke])[0]['qtyOnHand'])->toBe(95_000);
    });

    it('treats a wildcard in the search as the character it is', function () {
        ($this->stock)($this->coke, 100, 30_000);
        expect(InventoryService::countStockLedger(['search' => '%']))->toBe(0)
            ->and(CatalogService::countProducts(['search' => '_']))->toBe(0);
    });
});

describe('customer and supplier balance filters', function () {
    it('finds who owes from the ledger, not from a flag', function () {
        $kofi = Shop::customer($this->actor, 'Kofi Mensah');
        Shop::customer($this->actor, 'Ama Serwaa');
        ($this->stock)($this->rice, 10, 40_000);
        ($this->sale)($this->rice, 1, '2026-08-02', $kofi);

        expect(CustomerService::countCustomers(['balanceState' => 'owing']))->toBe(1)
            ->and(CustomerService::listCustomers(['balanceState' => 'owing'])[0]['id'])->toBe($kofi)
            ->and(CustomerService::countCustomers(['balanceState' => 'zero']))->toBe(1);
    });

    it('finds a debtor sorted past the page limit', function () {
        for ($index = 0; $index < 30; $index++) {
            Shop::customer($this->actor, 'Aaa customer '.str_pad((string) $index, 3, '0', STR_PAD_LEFT));
        }
        $zoe = Shop::customer($this->actor, 'Zoe Owusu');
        ($this->stock)($this->rice, 10, 40_000);
        ($this->sale)($this->rice, 1, '2026-08-02', $zoe);

        expect(CustomerService::countCustomers(['balanceState' => 'owing']))->toBe(1)
            ->and(CustomerService::listCustomers(['balanceState' => 'owing', 'limit' => 5])[0]['id'])->toBe($zoe);
    });

    it('finds who the shop owes from actual payable entries', function () {
        ($this->delivery)('2026-08-02', $this->madina, $this->rice, 10, 4_000, 0); // nothing paid
        ($this->delivery)('2026-08-03', $this->kasapreko, $this->coke, 10, 300, 3_000); // paid in full

        expect(SupplierService::countSuppliers(['balanceState' => 'owing']))->toBe(1)
            ->and(SupplierService::listSuppliers(['balanceState' => 'owing'])[0]['id'])->toBe($this->madina);
    });

    it('searches customers and suppliers on more than the name', function () {
        Shop::customer($this->actor, 'Kofi Mensah', null, ['phone' => '0244000111', 'email' => 'kofi@example.com']);

        expect(CustomerService::countCustomers(['search' => '0244']))->toBe(1)
            ->and(CustomerService::countCustomers(['search' => 'example.com']))->toBe(1)
            ->and(SupplierService::countSuppliers(['search' => 'madina']))->toBe(1);
    });

    it('sorts by balance', function () {
        ($this->delivery)('2026-08-02', $this->madina, $this->rice, 10, 4_000, 0);
        ($this->delivery)('2026-08-03', $this->kasapreko, $this->coke, 10, 300, 0);

        $highestFirst = SupplierService::listSuppliers(['sort' => 'balance', 'direction' => 'desc']);
        expect($highestFirst[0]['id'])->toBe($this->madina);
    });
});

describe('expense filters', function () {
    beforeEach(function () {
        $this->rent = PaymentAccountService::createCategory('EXPENSE', 'Shed rental', $this->actor);
        $this->transport = PaymentAccountService::createCategory('EXPENSE', 'Market runs', $this->actor);
        $this->spend = fn (string $date, int $categoryId, int $amount, string $description, ?int $account = null) => Shop::expense(
            $this->actor,
            $date,
            $categoryId,
            $amount,
            $description,
            ['paymentAccountId' => $account ?? $this->cash],
        );
    });

    it('narrows by date, category, payment method and search', function () {
        ($this->spend)('2026-08-02', $this->rent, 80_000, 'Shop rent for August');
        ($this->spend)('2026-08-03', $this->transport, 4_500, 'Taxi to Madina market', $this->momo);
        ($this->spend)('2026-09-02', $this->rent, 80_000, 'Shop rent for September');

        $range = ['from' => '2026-08-01', 'to' => '2026-08-31'];
        expect(CashbookService::countExpenses($range))->toBe(2)
            ->and(CashbookService::countExpenses([...$range, 'categoryAccountId' => $this->rent]))->toBe(1)
            ->and(CashbookService::countExpenses([...$range, 'paymentAccountId' => $this->momo]))->toBe(1)
            ->and(CashbookService::countExpenses([...$range, 'search' => 'madina']))->toBe(1)
            ->and(CashbookService::countExpenses([...$range, 'staffId' => $this->actor->id]))->toBe(2)
            ->and(CashbookService::countExpenses([...$range, 'staffId' => $this->actor->id + 1]))->toBe(0);
    });

    it('narrows by amount', function () {
        ($this->spend)('2026-08-02', $this->rent, 80_000, 'Rent');
        ($this->spend)('2026-08-03', $this->transport, 4_500, 'Taxi');

        $range = ['from' => '2026-08-01', 'to' => '2026-08-31'];
        expect(CashbookService::countExpenses([...$range, 'minAmount' => 10_000]))->toBe(1)
            ->and(CashbookService::countExpenses([...$range, 'maxAmount' => 10_000]))->toBe(1);
    });

    it('reports the count, total and average of exactly what is filtered', function () {
        ($this->spend)('2026-08-02', $this->rent, 80_000, 'Rent');
        ($this->spend)('2026-08-03', $this->transport, 4_000, 'Taxi');
        ($this->spend)('2026-08-04', $this->transport, 6_000, 'Taxi');
        ($this->spend)('2026-09-01', $this->rent, 80_000, 'Next month');

        $filters = ['from' => '2026-08-01', 'to' => '2026-08-31', 'categoryAccountId' => $this->transport];
        $summary = CashbookService::getFilteredExpensesSummary($filters);
        $rows = CashbookService::listExpenses($filters);

        expect($summary['count'])->toBe(2)
            ->and($rows)->toHaveCount(2)
            ->and($summary['total'])->toBe(10_000)
            ->and($summary['average'])->toBe(5_000)
            ->and($summary['total'])->toBe(array_sum(array_column($rows, 'amountMinor')));
    });

    it('splits by category over the same filter as the table', function () {
        ($this->spend)('2026-08-02', $this->rent, 80_000, 'Rent');
        ($this->spend)('2026-08-03', $this->transport, 4_000, 'Taxi');
        ($this->spend)('2026-09-01', $this->rent, 80_000, 'Next month');

        $filters = ['from' => '2026-08-01', 'to' => '2026-08-31'];
        $byCategory = CashbookService::getFilteredExpensesByCategory($filters);

        expect($byCategory)->toHaveCount(2)
            ->and(array_sum(array_column($byCategory, 'total')))->toBe(CashbookService::getFilteredExpensesSummary($filters)['total']);
    });

    it('shows an average of nothing rather than dividing by zero', function () {
        expect(CashbookService::getFilteredExpensesSummary(['from' => '2026-08-01', 'to' => '2026-08-31']))->toBe(['count' => 0, 'total' => 0, 'average' => 0, 'voidedLater' => 0, 'voidedEarlier' => 0]);
    });
});

describe('expense categories offered by the filter', function () {
    it('come from the shop, not from a hard-coded list', function () {
        $before = count(PaymentAccountService::listExpenseCategories());
        PaymentAccountService::createCategory('EXPENSE', 'Night watchman', $this->actor);
        expect(PaymentAccountService::listExpenseCategories())->toHaveCount($before + 1);
    });
});
