<?php

declare(strict_types=1);

/*
| Ported from the product and stock parts of the old
| tests/services/module-filters.test.ts: a filter narrows in SQL before any
| page limit, and totals come from the same clause as the rows.
|
| The stock-movement tests stand in for createSale with a SALE movement through
| the gateway, the only part of the sale these filters read. Deferred: the
| purchase, expense, customer/supplier and expense-category filters (Phases 4 to
| 6) and, in "narrows by category and by the supplier who delivered it", the
| supplier half, which needs a delivery (Phase 5).
*/

use App\Enums\AdjustmentReason;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Services\StockAdjustmentService;
use App\Support\Clock;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->drinks = CatalogService::createCategory(['name' => 'Drinks'], $this->actor);
    $this->food = CatalogService::createCategory(['name' => 'Food'], $this->actor);
    $this->coke = CatalogService::createProduct(['name' => 'Coca-Cola 500ml', 'sku' => 'COKE-500', 'categoryId' => $this->drinks, 'costPrice' => 300, 'sellingPrice' => 500, 'unit' => 'pcs', 'minStock' => 10_000], $this->actor);
    $this->rice = CatalogService::createProduct(['name' => 'Rice 5kg', 'sku' => 'RICE-5', 'categoryId' => $this->food, 'costPrice' => 4_000, 'sellingPrice' => 6_000, 'unit' => 'bag', 'minStock' => 5_000], $this->actor);
    $this->stock = fn (int $id, int $units, int $cost) => Shop::openingStock($this->actor, $id, $units * 1000, $cost, '2026-08-01');
    $this->filler = fn (string $name, array $extra = []): int => CatalogService::createProduct(['name' => $name, 'costPrice' => 100, 'sellingPrice' => 200, 'unit' => 'pcs', 'trackInventory' => false, ...$extra], $this->actor);
    $this->sale = fn (int $id, int $units, string $date) => Shop::move([
        'productId' => $id, 'direction' => 'OUT', 'qty' => $units * 1000, 'movementType' => 'SALE', 'sourceType' => 'TEST',
        'businessDate' => $date, 'occurredAt' => Clock::now(),
    ]);
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

    it('narrows by category', function () {
        expect(CatalogService::countProducts(['categoryId' => $this->drinks]))->toBe(1);
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
