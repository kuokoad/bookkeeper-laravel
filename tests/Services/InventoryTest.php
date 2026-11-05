<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/inventory.test.ts. Account balances come
| from Shop::balance until the reports arrive (Phase 7).
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\InsufficientStockError;
use App\Domain\Errors\ValidationError;
use App\Enums\AdjustmentReason;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Services\StockAdjustmentService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const INV_TODAY = '2026-08-16';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->make = fn (string $name, int $cost = 500, int $selling = 800): int => Shop::product($this->actor, $name, $cost, $selling);
    $this->addStock = fn (int $id, int $units, int $cost): array => Shop::openingStock($this->actor, $id, $units * 1000, $cost, INV_TODAY);
    $this->adjust = fn (AdjustmentReason $reason, array $items, ?string $note = null): array => StockAdjustmentService::createStockAdjustment(
        ['businessDate' => INV_TODAY, 'reason' => $reason, 'note' => $note, 'items' => $items],
        $this->actor,
    );
});

describe('products', function () {
    it('always starts a new product with zero stock', function () {
        $product = CatalogService::getProduct(($this->make)('Coca-Cola 350ml'));
        expect($product['qtyOnHand'])->toBe(0)->and($product['stockValue'])->toBe(0);
    });

    it('rejects a duplicate SKU and barcode', function () {
        CatalogService::createProduct(['name' => 'Milo 400g', 'sku' => 'MILO400', 'barcode' => '6001234567890', 'costPrice' => 0, 'sellingPrice' => 0], $this->actor);

        expect(fn () => CatalogService::createProduct(['name' => 'Other', 'sku' => 'milo400', 'costPrice' => 0, 'sellingPrice' => 0], $this->actor))
            ->toThrow(ConflictError::class, 'already used')
            ->and(fn () => CatalogService::createProduct(['name' => 'Other', 'barcode' => '6001234567890', 'costPrice' => 0, 'sellingPrice' => 0], $this->actor))
            ->toThrow(ConflictError::class, 'already used');
    });

    it('allows many products with no SKU', function () {
        ($this->make)('Bread');
        ($this->make)('Bottled Water');
        expect(CatalogService::listProducts())->toHaveCount(2);
    });

    it('rejects negative prices', function () {
        expect(fn () => CatalogService::createProduct(['name' => 'Bad', 'costPrice' => -1, 'sellingPrice' => 0], $this->actor))
            ->toThrow(ValidationError::class);
    });

    it('links to a category and reports the name', function () {
        $categoryId = CatalogService::createCategory(['name' => 'Drinks'], $this->actor);
        $id = CatalogService::createProduct(['name' => 'Fanta', 'categoryId' => $categoryId, 'costPrice' => 300, 'sellingPrice' => 500], $this->actor);
        expect(CatalogService::getProduct($id)['categoryName'])->toBe('Drinks');
    });

    it('refuses a duplicate category name regardless of case', function () {
        CatalogService::createCategory(['name' => 'Drinks'], $this->actor);
        expect(fn () => CatalogService::createCategory(['name' => 'drinks'], $this->actor))->toThrow(ConflictError::class, 'already exists');
    });
});

describe('stock adjustments — the accounting effect', function () {
    it('opening stock increases inventory and posts a balanced entry', function () {
        $id = ($this->make)('Milo 400g');
        $result = ($this->addStock)($id, 10, 5_000); // 10 @ GHS 5.00 = GHS 50.00

        $product = CatalogService::getProduct($id);
        expect($product['qtyOnHand'])->toBe(10_000)
            ->and($product['stockValue'])->toBe(5_000)
            ->and($product['averageCost'])->toBe(500)
            // The general ledger agrees with the stock.
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(5_000)
            // Opening stock is capital introduced, not an expense.
            ->and(Shop::balance(ChartOfAccounts::OPENING_BALANCE_EQUITY))->toBe(5_000)
            ->and(Shop::trialBalanced())->toBeTrue()
            ->and($result['journalEntryId'])->toBeGreaterThan(0);
    });

    it('writes one ledger row carrying the running balance', function () {
        $id = ($this->make)('Milo 400g');
        ($this->addStock)($id, 10, 5_000);

        $rows = DB::table('stock_ledger')->where('product_id', $id)->get();
        expect($rows)->toHaveCount(1);
        $row = $rows[0];
        // Opening stock is tagged distinctly, never as a generic adjustment.
        expect($row->movement_type)->toBe('OPENING_STOCK')
            ->and($row->qty_in_milli)->toBe(10_000)
            ->and($row->qty_out_milli)->toBe(0)
            ->and($row->total_cost_minor)->toBe(5_000)
            ->and($row->balance_qty_milli)->toBe(10_000)
            ->and($row->balance_value_minor)->toBe(5_000)
            ->and($row->source_ref)->toStartWith('ADJ-');
    });

    it('re-averages when stock is added at a different price', function () {
        $id = ($this->make)('Milo 400g');
        ($this->addStock)($id, 10, 5_000); // 5.00 each
        ($this->addStock)($id, 10, 7_000); // 7.00 each -> average 6.00

        $product = CatalogService::getProduct($id);
        expect($product['qtyOnHand'])->toBe(20_000)
            ->and($product['stockValue'])->toBe(12_000)
            ->and($product['averageCost'])->toBe(600)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(12_000);
    });

    it('writing off damaged stock moves value to shrinkage at the average cost', function () {
        $id = ($this->make)('Milo 400g');
        ($this->addStock)($id, 10, 5_000);
        ($this->addStock)($id, 10, 7_000); // average 6.00

        ($this->adjust)(AdjustmentReason::Damaged, [['productId' => $id, 'direction' => 'OUT', 'qty' => 3_000]], 'Crate fell');

        $product = CatalogService::getProduct($id);
        expect($product['qtyOnHand'])->toBe(17_000)
            // 3 x 6.00 = 18.00 removed from 120.00
            ->and($product['stockValue'])->toBe(10_200)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(10_200)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY_SHRINKAGE))->toBe(1_800)
            ->and(Shop::trialBalanced())->toBeTrue();
    });

    it('tags a later found/adjustment movement separately from opening stock', function () {
        $id = ($this->make)('Milo 400g');
        ($this->addStock)($id, 10, 5_000);
        ($this->adjust)(AdjustmentReason::Found, [['productId' => $id, 'direction' => 'IN', 'qty' => 2_000, 'totalCost' => 1_000]]);

        expect(DB::table('stock_ledger')->where('product_id', $id)->orderBy('id')->pluck('movement_type')->all())
            ->toBe(['OPENING_STOCK', 'ADJUSTMENT_IN']);
    });

    it('posts internal use to an expense account, not shrinkage', function () {
        $id = ($this->make)('Bottled Water');
        ($this->addStock)($id, 10, 2_000);
        ($this->adjust)(AdjustmentReason::InternalUse, [['productId' => $id, 'direction' => 'OUT', 'qty' => 2_000]]);

        expect(Shop::balance('6900'))->toBe(400)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY_SHRINKAGE))->toBe(0)
            ->and(Shop::trialBalanced())->toBeTrue();
    });

    it('handles several products in one document with one journal entry', function () {
        $a = ($this->make)('Bread');
        $b = ($this->make)('Biscuits');

        $result = ($this->adjust)(AdjustmentReason::OpeningStock, [
            ['productId' => $a, 'direction' => 'IN', 'qty' => 20_000, 'totalCost' => 4_000],
            ['productId' => $b, 'direction' => 'IN', 'qty' => 50_000, 'totalCost' => 7_500],
        ]);

        expect(CatalogService::getProduct($a)['stockValue'])->toBe(4_000)
            ->and(CatalogService::getProduct($b)['stockValue'])->toBe(7_500)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(11_500);

        // One document, one balanced entry.
        $lines = DB::table('journal_lines')->where('entry_id', $result['journalEntryId'])->get();
        expect($lines)->toHaveCount(2)
            ->and($lines->sum('debit_minor'))->toBe($lines->sum('credit_minor'));
    });
});

describe('stock availability rules', function () {
    it('refuses to remove more stock than is on hand', function () {
        $id = ($this->make)('Milo 400g');
        ($this->addStock)($id, 5, 2_500);

        expect(fn () => ($this->adjust)(AdjustmentReason::Damaged, [['productId' => $id, 'direction' => 'OUT', 'qty' => 6_000]]))
            ->toThrow(InsufficientStockError::class);
    });

    it('allows it once the shop enables negative stock', function () {
        $id = ($this->make)('Milo 400g', 500);
        ($this->addStock)($id, 5, 2_500);
        Shop::setting('allow_negative_stock', true);

        ($this->adjust)(AdjustmentReason::Lost, [['productId' => $id, 'direction' => 'OUT', 'qty' => 6_000]]);

        expect(CatalogService::getProduct($id)['qtyOnHand'])->toBe(-1_000)
            ->and(Shop::trialBalanced())->toBeTrue();
    });

    it('rejects a document with no items', function () {
        expect(fn () => ($this->adjust)(AdjustmentReason::Damaged, []))->toThrow(ValidationError::class);
    });

    it('rejects adding stock without a cost', function () {
        $id = ($this->make)('Milo 400g');
        expect(fn () => ($this->adjust)(AdjustmentReason::Found, [['productId' => $id, 'direction' => 'IN', 'qty' => 1_000]]))
            ->toThrow(ValidationError::class);
    });
});

describe('atomicity', function () {
    it('leaves nothing behind when one line of a multi-line document fails', function () {
        $good = ($this->make)('Bread');
        $short = ($this->make)('Biscuits');
        ($this->addStock)($good, 10, 2_000);
        ($this->addStock)($short, 1, 500);

        $inventoryBefore = Shop::balance(ChartOfAccounts::INVENTORY);
        $ledgerRowsBefore = DB::table('stock_ledger')->count();
        $adjustmentsBefore = DB::table('stock_adjustments')->count();

        expect(fn () => ($this->adjust)(AdjustmentReason::Damaged, [
            ['productId' => $good, 'direction' => 'OUT', 'qty' => 2_000], // fine
            ['productId' => $short, 'direction' => 'OUT', 'qty' => 99_000], // fails
        ]))->toThrow(InsufficientStockError::class);

        // The first line's stock movement must NOT have survived.
        expect(CatalogService::getProduct($good)['qtyOnHand'])->toBe(10_000)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe($inventoryBefore)
            ->and(DB::table('stock_ledger')->count())->toBe($ledgerRowsBefore)
            ->and(DB::table('stock_adjustments')->count())->toBe($adjustmentsBefore)
            ->and(Shop::trialBalanced())->toBeTrue();
    });
});

describe('voiding an adjustment', function () {
    it('reverses the stock and the accounting without deleting anything', function () {
        $id = ($this->make)('Milo 400g');
        ($this->addStock)($id, 10, 5_000);
        $damage = ($this->adjust)(AdjustmentReason::Damaged, [['productId' => $id, 'direction' => 'OUT', 'qty' => 4_000]]);

        expect(CatalogService::getProduct($id)['qtyOnHand'])->toBe(6_000)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY_SHRINKAGE))->toBe(2_000);

        StockAdjustmentService::voidStockAdjustment($damage['adjustmentId'], 'Recorded by mistake', $this->actor);

        // Stock and accounts are back where they were.
        $product = CatalogService::getProduct($id);
        expect($product['qtyOnHand'])->toBe(10_000)
            ->and($product['stockValue'])->toBe(5_000)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY_SHRINKAGE))->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(5_000)
            ->and(Shop::trialBalanced())->toBeTrue();

        // The original document still exists, marked voided and linked.
        $original = DB::table('stock_adjustments')->where('id', $damage['adjustmentId'])->first();
        expect($original->status)->toBe('VOIDED')
            ->and($original->voided_by_adjustment_id)->toBeGreaterThan(0)
            ->and($original->void_reason)->toBe('Recorded by mistake');

        // Both the original and reversing entries survive: history is intact.
        expect(DB::table('journal_entries')->count())->toBeGreaterThanOrEqual(3)
            ->and(DB::table('journal_entries')->whereNotNull('reverses_entry_id')->exists())->toBeTrue();
    });

    it('refuses to void the same adjustment twice', function () {
        $id = ($this->make)('Milo 400g');
        ($this->addStock)($id, 10, 5_000);
        $damage = ($this->adjust)(AdjustmentReason::Damaged, [['productId' => $id, 'direction' => 'OUT', 'qty' => 1_000]]);

        StockAdjustmentService::voidStockAdjustment($damage['adjustmentId'], 'Mistake', $this->actor);
        expect(fn () => StockAdjustmentService::voidStockAdjustment($damage['adjustmentId'], 'Again', $this->actor))
            ->toThrow(ConflictError::class, 'already been voided');
    });

    it('requires a reason', function () {
        $id = ($this->make)('Milo 400g');
        ($this->addStock)($id, 10, 5_000);
        $damage = ($this->adjust)(AdjustmentReason::Damaged, [['productId' => $id, 'direction' => 'OUT', 'qty' => 1_000]]);

        expect(fn () => StockAdjustmentService::voidStockAdjustment($damage['adjustmentId'], 'x', $this->actor))->toThrow(ValidationError::class);
    });
});

describe('THE inventory invariant', function () {
    it('keeps the Inventory GL account equal to the stock ledger at every step', function () {
        $a = ($this->make)('Bread');
        $b = ($this->make)('Milo 400g');

        $steps = [
            fn () => ($this->addStock)($a, 20, 4_000),
            fn () => ($this->addStock)($b, 10, 5_000),
            fn () => ($this->addStock)($b, 10, 7_000),
            fn () => ($this->adjust)(AdjustmentReason::Damaged, [['productId' => $b, 'direction' => 'OUT', 'qty' => 3_000]]),
            fn () => ($this->adjust)(AdjustmentReason::Expired, [['productId' => $a, 'direction' => 'OUT', 'qty' => 5_000]]),
            fn () => ($this->adjust)(AdjustmentReason::Found, [['productId' => $a, 'direction' => 'IN', 'qty' => 2_000, 'totalCost' => 400]]),
        ];

        foreach ($steps as $index => $step) {
            $step();
            $label = 'after step '.($index + 1);

            // 1. Cached stock value == ledger replay, for every product.
            foreach ([$a, $b] as $productId) {
                expect(InventoryService::verifyProductStock($productId)['ok'])->toBeTrue("{$label}: product {$productId} drift");
            }
            // 2. Sum of all product stock values == the Inventory GL account.
            expect(InventoryService::getInventoryValue())->toBe(Shop::balance(ChartOfAccounts::INVENTORY), "{$label}: inventory vs GL")
                // 3. The books balance.
                ->and(Shop::trialBalanced())->toBeTrue("{$label}: trial balance");
        }
    });

    it('detects drift if the cache is tampered with directly', function () {
        $id = ($this->make)('Milo 400g');
        ($this->addStock)($id, 10, 5_000);
        expect(InventoryService::verifyProductStock($id)['ok'])->toBeTrue();

        // Simulate corruption that bypassed the service layer.
        DB::table('products')->where('id', $id)->update(['qty_on_hand_milli' => 99_000]);

        $verification = InventoryService::verifyProductStock($id);
        expect($verification['ok'])->toBeFalse()
            ->and($verification['qtyDrift'])->toBe(89_000)
            ->and($verification['ledgerQty'])->toBe(10_000);
    });
});

describe('categories', function () {
    it('archives rather than deletes, keeping product links intact', function () {
        $categoryId = CatalogService::createCategory(['name' => 'Drinks'], $this->actor);
        $productId = CatalogService::createProduct(['name' => 'Fanta', 'categoryId' => $categoryId, 'costPrice' => 300, 'sellingPrice' => 500], $this->actor);

        DB::table('categories')->where('id', $categoryId)->update(['is_active' => false]);

        // The product keeps its category even though the category is archived.
        expect(CatalogService::getProduct($productId)['categoryName'])->toBe('Drinks');
    });
});
