<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/product-delete.test.ts.
|
| A duplicate typed in by mistake can be deleted; a product with any history
| cannot. Archiving hides a product, so it must not hide stock the books carry.
|
| Deferred until their services exist: "refuses a product that is only on a
| quotation" (quotations, Phase 4), "refuses a service item that was sold"
| (sales, Phase 4), "still values stock that comes back onto an archived
| product" (sales and returns, Phase 4), and the stock-valuation report half of
| the archive refusal (reports, Phase 7; its summary half is asserted here).
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\NotFoundError;
use App\Services\CatalogService;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->make = fn (string $name, bool $track = true): int => Shop::product($this->actor, $name, 500, 800, ['trackInventory' => $track]);
    $this->addStock = fn (int $id, int $units, int $cost) => Shop::openingStock($this->actor, $id, $units * 1000, $cost, '2026-11-04');
    $this->expectRefusedAndKept = function (int $id): void {
        expect(CatalogService::isProductInUse($id))->toBeTrue()
            ->and(fn () => CatalogService::deleteProduct($id, $this->actor))->toThrow(ConflictError::class)
            ->and(DB::table('products')->where('id', $id)->exists())->toBeTrue();
    };
});

describe('deleting a product', function () {
    it('removes an unused duplicate and keeps what it was in the audit trail', function () {
        ($this->make)('Milo 400g');
        $duplicate = CatalogService::createProduct(['name' => 'Milo 400g (copy)', 'sku' => 'MILO-2', 'costPrice' => 500, 'sellingPrice' => 800, 'unit' => 'pcs'], $this->actor);

        expect(CatalogService::isProductInUse($duplicate))->toBeFalse();
        CatalogService::deleteProduct($duplicate, $this->actor);

        expect(DB::table('products')->where('id', $duplicate)->exists())->toBeFalse();
        $entry = DB::table('audit_logs')->where('entity_type', 'product')->where('summary', 'Deleted product "Milo 400g (copy)"')->first();
        expect($entry)->not->toBeNull();
        $deleted = json_decode($entry->metadata, true)['deleted'];
        expect($deleted)->toMatchArray(['sku' => 'MILO-2', 'costPriceMinor' => 500, 'sellingPriceMinor' => 800])
            ->and($deleted['createdAt'])->toBeString();
    });

    it('refuses a product with stock history and leaves stock value untouched', function () {
        $id = ($this->make)('Coca-Cola 350ml');
        ($this->addStock)($id, 10, 5_000);

        ($this->expectRefusedAndKept)($id);
        expect(InventoryService::getInventoryValue())->toBe(5_000);
    });

    it('says not found for a product that does not exist', function () {
        expect(fn () => CatalogService::deleteProduct(9_999, $this->actor))->toThrow(NotFoundError::class);
    });
});

describe('archiving a product', function () {
    it('refuses while it holds stock, so stock valuation keeps matching the inventory account', function () {
        $id = ($this->make)('Coca-Cola 350ml');
        ($this->addStock)($id, 10, 5_000);

        expect(fn () => CatalogService::setProductActive($id, false, $this->actor))->toThrow(ConflictError::class)
            ->and(CatalogService::getProduct($id)['isActive'])->toBeTrue()
            ->and(CatalogService::getStockSummary()['totalStockValue'])->toBe(Shop::balance(ChartOfAccounts::INVENTORY));
    });

    it('archives and restores a product with no stock', function () {
        $id = ($this->make)('Milo 400g');

        CatalogService::setProductActive($id, false, $this->actor);
        expect(CatalogService::getProduct($id)['isActive'])->toBeFalse();

        CatalogService::setProductActive($id, true, $this->actor);
        expect(CatalogService::getProduct($id)['isActive'])->toBeTrue();
    });
});
