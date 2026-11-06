<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/product-replace-unit.test.ts: replacing a
| product with locked units, and its stock value coming through to the pesewa.
|
| "refuses dated stock" lands its dated crate through
| the gateway, as the old test did.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\ValidationError;
use App\Enums\AdjustmentReason;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Services\ProductReplaceService;
use App\Services\Reporting\OperationsService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const REPLACE_TODAY = '2026-11-04';

/** Milo, counted in pcs, wrongly set up as 12 to a carton. */
const REPLACE_MILO = [
    'name' => 'Milo 400g',
    'sku' => 'MILO-400',
    'barcode' => '6001234567890',
    'unit' => 'pcs',
    'altUnit' => 'Carton',
    'altFactor' => 12_000,
    'costPrice' => 500,
    'sellingPrice' => 800,
];

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->create = fn (array $input = REPLACE_MILO): int => CatalogService::createProduct($input, $this->actor);
    $this->stockIn = fn (int $id, int $units, int $cost, string $date = REPLACE_TODAY) => Shop::openingStock($this->actor, $id, $units * 1000, $cost, $date);
    $this->row = fn (int $id): object => Shop::productRow($id);
    // Replace as the form does: with the stock the page showed, here the current stock.
    $this->replace = fn (int $oldId, array $input, int $newUnits): array => ProductReplaceService::replaceProductUnit(
        $oldId,
        $input,
        ['expectedOldQty' => (int) Shop::productRow($oldId)->qty_on_hand_milli, 'newQty' => $newUnits * 1000],
        REPLACE_TODAY,
        $this->actor,
    );
    $this->milo24 = [...REPLACE_MILO, 'altFactor' => 24_000];
    // The till records a sale on an earlier day: dated then, taking only what had arrived by then.
    $this->sellOnTill = fn (int $id, string $date) => SaleService::createSale([
        'businessDate' => $date,
        'arrivedBy' => $date,
        'items' => [['productId' => $id, 'qty' => 1_000]],
        'tenders' => [['paymentAccountId' => Shop::paymentAccount('CASH'), 'amount' => 800]],
    ], $this->actor);
});

describe('replacing a product to change its units', function () {
    it('moves the stock across at the same value and archives the old product', function () {
        $oldId = ($this->create)();
        ($this->stockIn)($oldId, 48, 24_037); // an awkward total, so a rounded unit cost would show
        $inventoryBefore = Shop::balance(ChartOfAccounts::INVENTORY);

        $result = ($this->replace)($oldId, $this->milo24, 48);

        $fresh = CatalogService::getProduct($result['productId']);
        expect($fresh['qtyOnHand'])->toBe(48_000)
            ->and($fresh['stockValue'])->toBe(24_037)
            ->and($fresh['altFactor'])->toBe(24_000)
            ->and($fresh['isActive'])->toBeTrue();

        $old = ($this->row)($oldId);
        expect($old->qty_on_hand_milli)->toBe(0)
            ->and($old->stock_value_minor)->toBe(0)
            ->and((bool) $old->is_active)->toBeFalse()
            // The scanner keeps working: the codes moved to the new product.
            ->and($fresh['sku'])->toBe('MILO-400')
            ->and($fresh['barcode'])->toBe('6001234567890')
            ->and($old->sku)->toBeNull()
            ->and($old->barcode)->toBeNull()
            // Nothing was gained or lost, in the accounts or on the shelf.
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe($inventoryBefore)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY_SHRINKAGE))->toBe(0)
            ->and(InventoryService::getInventoryValue())->toBe(24_037)
            ->and(InventoryService::getInventoryValueFromLedger())->toBe(24_037)
            ->and(collect(InventoryService::verifyAllStock())->every(fn (array $c): bool => $c['ok']))->toBeTrue()
            ->and($result['adjustmentNo'])->not->toBeNull();
    });

    it('converts the quantity when the base unit itself changes, keeping the value', function () {
        $oldId = ($this->create)(['name' => 'Sardines', 'unit' => 'Carton', 'costPrice' => 6_000, 'sellingPrice' => 7_200]);
        ($this->stockIn)($oldId, 5, 30_000);

        $result = ($this->replace)($oldId, ['name' => 'Sardines', 'unit' => 'tin', 'altUnit' => 'Carton', 'altFactor' => 50_000, 'costPrice' => 120, 'sellingPrice' => 150], 250);

        $fresh = CatalogService::getProduct($result['productId']);
        expect($fresh['unit'])->toBe('tin')
            ->and($fresh['qtyOnHand'])->toBe(250_000)
            ->and($fresh['stockValue'])->toBe(30_000)
            ->and(InventoryService::getInventoryValueFromLedger())->toBe(30_000);
    });

    it('archives a product that holds no stock without writing an adjustment', function () {
        $oldId = ($this->create)();
        ($this->stockIn)($oldId, 1, 500);
        Shop::stockOut($this->actor, $oldId, 1_000, REPLACE_TODAY);
        $adjustmentsBefore = DB::table('stock_adjustments')->count();

        $result = ($this->replace)($oldId, $this->milo24, 0);

        expect($result['adjustmentNo'])->toBeNull()
            ->and(DB::table('stock_adjustments')->count())->toBe($adjustmentsBefore)
            ->and((bool) ($this->row)($oldId)->is_active)->toBeFalse();
    });

    it('refuses a product whose units are not locked, which can simply be edited', function () {
        $oldId = ($this->create)();
        expect(fn () => ($this->replace)($oldId, $this->milo24, 0))->toThrow(ValidationError::class);
    });

    it('refuses when the units are the same as before', function () {
        $oldId = ($this->create)();
        ($this->stockIn)($oldId, 10, 5_000);
        expect(fn () => ($this->replace)($oldId, REPLACE_MILO, 10))->toThrow(ValidationError::class);
    });

    it('refuses to move stock in as nothing, and leaves everything as it was', function () {
        $oldId = ($this->create)();
        ($this->stockIn)($oldId, 10, 5_000);
        $productCount = DB::table('products')->count();

        expect(fn () => ($this->replace)($oldId, $this->milo24, 0))->toThrow(ValidationError::class)
            ->and(DB::table('products')->count())->toBe($productCount)
            ->and((bool) ($this->row)($oldId)->is_active)->toBeTrue()
            ->and(($this->row)($oldId)->sku)->toBe('MILO-400')
            ->and(($this->row)($oldId)->qty_on_hand_milli)->toBe(10_000);
    });

    it('refuses dated stock, whose expiry dates the move would lose', function () {
        $oldId = ($this->create)();
        Shop::move([
            'productId' => $oldId, 'direction' => 'IN', 'qty' => 10_000, 'totalCost' => 5_000, 'movementType' => 'PURCHASE',
            'sourceType' => 'TEST', 'businessDate' => REPLACE_TODAY, 'occurredAt' => Clock::fromStamp(REPLACE_TODAY.' 08:00:00'),
            'batch' => ['kind' => 'NEW', 'expiryDate' => '2027-03-01'],
        ]);

        expect(fn () => ($this->replace)($oldId, $this->milo24, 10))->toThrow(ValidationError::class)
            ->and((bool) ($this->row)($oldId)->is_active)->toBeTrue();
    });

    it('refuses to void the move, which would strand the stock without its codes', function () {
        $oldId = ($this->create)();
        ($this->stockIn)($oldId, 10, 5_000);
        ($this->replace)($oldId, $this->milo24, 10);
        $move = DB::table('stock_adjustments')->where('reason', 'OTHER')->first();

        expect(fn () => StockAdjustmentService::voidStockAdjustment($move->id, 'Changed my mind', $this->actor))->toThrow(ConflictError::class)
            ->and(($this->row)($oldId)->qty_on_hand_milli)->toBe(0);
    });

    it('still voids an ordinary adjustment whose product was archived later', function () {
        $id = ($this->create)([...REPLACE_MILO, 'sku' => 'OTHER', 'barcode' => null]);
        ($this->stockIn)($id, 2, 1_000);
        $writeOff = StockAdjustmentService::createStockAdjustment([
            'businessDate' => REPLACE_TODAY,
            'reason' => AdjustmentReason::Other,
            'note' => 'Unit change: typed by hand, not a replacement',
            'items' => [['productId' => $id, 'direction' => 'OUT', 'qty' => 2_000]],
        ], $this->actor);
        DB::table('products')->where('id', $id)->update(['is_active' => false]);

        StockAdjustmentService::voidStockAdjustment($writeOff['adjustmentId'], 'Counted wrong', $this->actor);

        expect(($this->row)($id)->qty_on_hand_milli)->toBe(2_000)
            ->and(OperationsService::getStockValuation()['totalCostValue'])->toBe(Shop::balance(ChartOfAccounts::INVENTORY))
            ->and(CatalogService::getStockSummary()['totalStockValue'])->toBe(Shop::balance(ChartOfAccounts::INVENTORY));
    });

    it('refuses a replacement that does not track stock when there is stock to move', function () {
        $oldId = ($this->create)();
        ($this->stockIn)($oldId, 10, 5_000);
        expect(fn () => ($this->replace)($oldId, [...$this->milo24, 'trackInventory' => false], 10))->toThrow(ValidationError::class, 'must track stock')
            ->and(($this->row)($oldId)->sku)->toBe('MILO-400')
            ->and((bool) ($this->row)($oldId)->is_active)->toBeTrue();
    });

    it('refuses stock carried at a negative value, with a reason the owner can act on', function () {
        $oldId = ($this->create)();
        ($this->stockIn)($oldId, 10, 1_000);
        // Take half out at more than the whole stock is worth: 5 left at -500.
        Shop::move([
            'productId' => $oldId, 'direction' => 'OUT', 'qty' => 5_000, 'totalCost' => 1_500, 'movementType' => 'ADJUSTMENT_OUT',
            'sourceType' => 'TEST', 'businessDate' => REPLACE_TODAY, 'occurredAt' => Clock::fromStamp(REPLACE_TODAY.' 09:00:00'),
        ]);
        expect(($this->row)($oldId)->stock_value_minor)->toBeLessThan(0)
            ->and(fn () => ($this->replace)($oldId, $this->milo24, 5))->toThrow(ValidationError::class, 'valued below zero');
    });

    it('lets a backdated sale draw on the moved stock from the day it really arrived', function () {
        $oldId = ($this->create)();
        ($this->stockIn)($oldId, 10, 5_000, '2026-11-01');
        $result = ($this->replace)($oldId, $this->milo24, 10);

        // The till records a sale from 2 November, before the replacement on the 4th.
        ($this->sellOnTill)($result['productId'], '2026-11-02');
        expect(($this->row)($result['productId'])->qty_on_hand_milli)->toBe(9_000);
    });

    it('refuses when the stock moved after the page was loaded, so a sale cannot come back', function () {
        $oldId = ($this->create)();
        ($this->stockIn)($oldId, 10, 5_000);
        $shown = (int) ($this->row)($oldId)->qty_on_hand_milli;
        Shop::stockOut($this->actor, $oldId, 2_000, REPLACE_TODAY);
        $productCount = DB::table('products')->count();

        expect(fn () => ProductReplaceService::replaceProductUnit($oldId, $this->milo24, ['expectedOldQty' => $shown, 'newQty' => 10_000], REPLACE_TODAY, $this->actor))
            ->toThrow(ConflictError::class, 'Reload the page')
            ->and(DB::table('products')->count())->toBe($productCount)
            ->and((bool) ($this->row)($oldId)->is_active)->toBeTrue();
    });

    it('rolls back the freed SKU and barcode when the new product cannot be created', function () {
        $oldId = ($this->create)();
        ($this->stockIn)($oldId, 10, 5_000);
        ($this->create)([...REPLACE_MILO, 'name' => 'Other', 'sku' => 'TAKEN', 'barcode' => null]);

        expect(fn () => ($this->replace)($oldId, [...$this->milo24, 'sku' => 'TAKEN'], 10))->toThrow(ConflictError::class);
        $old = ($this->row)($oldId);
        expect($old->sku)->toBe('MILO-400')
            ->and($old->barcode)->toBe('6001234567890')
            ->and((bool) $old->is_active)->toBeTrue()
            ->and($old->qty_on_hand_milli)->toBe(10_000);
    });

    it('refuses a product showing negative stock', function () {
        Shop::setting('allow_negative_stock', true);
        $oldId = ($this->create)();
        ($this->stockIn)($oldId, 1, 500);
        Shop::stockOut($this->actor, $oldId, 3_000, REPLACE_TODAY, AdjustmentReason::Lost);
        $productCount = DB::table('products')->count();

        expect(fn () => ($this->replace)($oldId, $this->milo24, 1))->toThrow(ValidationError::class)
            ->and(DB::table('products')->count())->toBe($productCount);
    });

    it('refuses an archived product', function () {
        $oldId = ($this->create)();
        ($this->stockIn)($oldId, 1, 500);
        ($this->replace)($oldId, $this->milo24, 1);

        expect(fn () => ($this->replace)($oldId, [...REPLACE_MILO, 'altFactor' => 36_000], 0))->toThrow(ValidationError::class);
    });

    it('keeps the old base unit when the caller does not name one', function () {
        $oldId = ($this->create)(['name' => 'Rice', 'unit' => 'kg', 'altUnit' => 'Bag', 'altFactor' => 25_000, 'costPrice' => 900, 'sellingPrice' => 1_200]);
        ($this->stockIn)($oldId, 50, 45_000);

        // No 'unit': a service caller changing only the conversion.
        $result = ($this->replace)($oldId, ['name' => 'Rice', 'altUnit' => 'Bag', 'altFactor' => 50_000, 'costPrice' => 900, 'sellingPrice' => 1_200], 50);

        expect(CatalogService::getProduct($result['productId'])['unit'])->toBe('kg');
    });

    it('refuses a purchase saved after the replace that still names the old product', function () {
        $oldId = ($this->create)();
        ($this->stockIn)($oldId, 10, 5_000);
        $result = ($this->replace)($oldId, $this->milo24, 10);
        $supplierId = Shop::supplier($this->actor, 'Depot');

        // A purchase form opened before the replace still holds the old product id.
        expect(fn () => Shop::purchase($this->actor, $supplierId, [[$oldId, 5_000, 500]], [], ['businessDate' => REPLACE_TODAY]))
            ->toThrow(ValidationError::class, 'archived')
            ->and(($this->row)($oldId)->qty_on_hand_milli)->toBe(0)
            ->and(($this->row)($result['productId'])->qty_on_hand_milli)->toBe(10_000);
    });

    it('refuses any adjustment on an archived product, so a stale form moves nothing there', function () {
        $id = ($this->create)([...REPLACE_MILO, 'sku' => 'OTHER', 'barcode' => null]);
        ($this->stockIn)($id, 2, 1_000);
        DB::table('products')->where('id', $id)->update(['is_active' => false]);
        $adjust = fn (string $direction) => StockAdjustmentService::createStockAdjustment([
            'businessDate' => REPLACE_TODAY,
            'reason' => AdjustmentReason::CountCorrection,
            'items' => [['productId' => $id, 'direction' => $direction, 'qty' => 1_000, ...($direction === 'IN' ? ['totalCost' => 500] : [])]],
        ], $this->actor);

        expect(fn () => $adjust('IN'))->toThrow(ValidationError::class, 'archived')
            ->and(fn () => $adjust('OUT'))->toThrow(ValidationError::class, 'archived')
            ->and(($this->row)($id)->qty_on_hand_milli)->toBe(2_000);
    });

    it('keeps opening stock available to a backdated sale after it moves', function () {
        $oldId = ($this->create)();
        // Opening stock from before batches existed: dated the day the migration
        // ran, but on the shelf for any day.
        Shop::move([
            'productId' => $oldId, 'direction' => 'IN', 'qty' => 10_000, 'totalCost' => 5_000, 'movementType' => 'OPENING_STOCK',
            'sourceType' => 'OPENING', 'businessDate' => REPLACE_TODAY, 'occurredAt' => Clock::fromStamp(REPLACE_TODAY.' 08:00:00'),
            'batch' => ['kind' => 'NEW', 'expiryDate' => null],
        ]);
        $result = ($this->replace)($oldId, $this->milo24, 10);

        ($this->sellOnTill)($result['productId'], '2026-11-02');
        expect(($this->row)($result['productId'])->qty_on_hand_milli)->toBe(9_000);
    });

    it('refuses a different count when the base unit stays the same', function () {
        $oldId = ($this->create)();
        ($this->stockIn)($oldId, 120, 60_000);
        $productCount = DB::table('products')->count();

        // Only the carton size changes, so the count is still in pcs.
        expect(fn () => ($this->replace)($oldId, [...REPLACE_MILO, 'altFactor' => 6_000], 10))->toThrow(ValidationError::class, '120')
            ->and(DB::table('products')->count())->toBe($productCount)
            ->and((bool) ($this->row)($oldId)->is_active)->toBeTrue();
    });

    it('refuses a stale write-off on the replaced product, even with negative stock allowed', function () {
        Shop::setting('allow_negative_stock', true);
        $oldId = ($this->create)();
        ($this->stockIn)($oldId, 10, 5_000);
        $result = ($this->replace)($oldId, $this->milo24, 10);

        // A damage form opened before the replace still names the old product.
        expect(fn () => Shop::stockOut($this->actor, $oldId, 2_000, REPLACE_TODAY))->toThrow(ValidationError::class, 'archived')
            ->and(($this->row)($oldId)->qty_on_hand_milli)->toBe(0)
            ->and(($this->row)($result['productId'])->qty_on_hand_milli)->toBe(10_000);
    });

    it('will not restore a replaced product, which would split its stock across two', function () {
        $oldId = ($this->create)();
        ($this->stockIn)($oldId, 10, 5_000);
        ($this->replace)($oldId, $this->milo24, 10);

        expect(fn () => CatalogService::setProductActive($oldId, true, $this->actor))->toThrow(ConflictError::class, 'replaced')
            ->and((bool) ($this->row)($oldId)->is_active)->toBeFalse();
    });
});
