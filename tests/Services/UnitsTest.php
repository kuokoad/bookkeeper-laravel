<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/units.test.ts: buy by the carton, sell by
| the carton or the tin, and the shelf, the money and the books agree in tins.
|
| Deferred until their services exist (they drive a sale, purchase, quote or
| return): "selling by the carton" (5 tests), "buying by the carton",
| "turns a quote for 2 cartons into a sale of 2 cartons", "returning part of a
| carton sale", "takes a voided carton purchase back off the shelf and the
| books", and from "a carton size changed while a screen was open" the till,
| delivery and quote cases (6 tests). Sales and quotes are Phase 4, purchases
| Phase 5.
*/

use App\Database\Tx;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\ValidationError;
use App\Enums\AdjustmentReason;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Services\StockAdjustmentService;
use App\Services\UnitsService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const UNITS_TODAY = '2026-08-16';

/** Evaporated milk: counted in tins, also sold by the carton of 24 at 110.00. */
const UNITS_MILK = [
    'name' => 'Evaporated Milk',
    'unit' => 'tin',
    'altUnit' => 'Carton',
    'altFactor' => 24_000,
    'costPrice' => 400,
    'sellingPrice' => 500,
    'altSellingPrice' => 11_000,
];

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->unitOnce = function (string $symbol, int $decimals): void {
        if (! in_array($symbol, array_column(UnitsService::listUnits(), 'symbol'), true)) {
            UnitsService::createUnit(['symbol' => $symbol, 'name' => $symbol, 'decimalPlaces' => $decimals], $this->actor);
        }
    };
    $this->makeMilk = function (array $overrides = []): int {
        ($this->unitOnce)('tin', 0);
        ($this->unitOnce)('Carton', 0);

        return CatalogService::createProduct([...UNITS_MILK, ...$overrides], $this->actor);
    };
    // 100 tins at 4.00 each.
    $this->stockUp = fn (int $id) => Shop::openingStock($this->actor, $id, 100_000, 40_000, UNITS_TODAY);
    $this->qty = fn (int $id): int => (int) Shop::productRow($id)->qty_on_hand_milli;
    // Write off one tin, so the product has moved by more than opening stock.
    $this->writeOffOne = fn (int $id) => Shop::stockOut($this->actor, $id, 1_000, UNITS_TODAY);
    $this->cartonOpening = fn (int $id) => StockAdjustmentService::createStockAdjustment([
        'businessDate' => UNITS_TODAY,
        'reason' => AdjustmentReason::OpeningStock,
        // 2 cartons of 24 = 48 tins, counted in by the carton.
        'items' => [['productId' => $id, 'direction' => 'IN', 'qty' => 2_000, 'alt' => true, 'totalCost' => 19_200]],
    ], $this->actor);
    $this->cartonWriteOff = fn (int $id, ?int $shown = null) => StockAdjustmentService::createStockAdjustment([
        'businessDate' => UNITS_TODAY,
        'reason' => AdjustmentReason::Damaged,
        'items' => [['productId' => $id, 'direction' => 'OUT', 'qty' => 1_000, 'alt' => true, 'altFactor' => $shown]],
    ], $this->actor);
});

describe('quoting and adjusting by the carton', function () {
    it('writes off a damaged carton as 24 tins', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);

        ($this->cartonWriteOff)($id);

        expect(($this->qty)($id))->toBe(76_000);
        Shop::assertBooksHealthy('carton write-off');
    });
});

describe('the units of a product that has traded', function () {
    it('cannot change once stock has moved beyond opening stock', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);
        ($this->writeOffOne)($id);

        expect(fn () => CatalogService::updateProduct($id, [...UNITS_MILK, 'altFactor' => 12_000], $this->actor))->toThrow(ValidationError::class)
            ->and(fn () => CatalogService::updateProduct($id, [...UNITS_MILK, 'altUnit' => null], $this->actor))->toThrow(ValidationError::class, 'bought, sold or adjusted');
        // Prices are not units, and still change freely.
        CatalogService::updateProduct($id, [...UNITS_MILK, 'altSellingPrice' => 10_500], $this->actor);
        expect(CatalogService::getProduct($id)['altSellingPrice'])->toBe(10_500);
    });

    it('can change unit, alternate unit and conversion while nothing has moved', function () {
        $id = ($this->makeMilk)();
        ($this->unitOnce)('pcs', 0);
        ($this->unitOnce)('Dozen', 0);

        // What the edit page asks before it greys the unit fields out.
        expect(CatalogService::productUnitsLocked($id))->toBeFalse();

        CatalogService::updateProduct($id, [...UNITS_MILK, 'unit' => 'pcs', 'altUnit' => 'Dozen', 'altFactor' => 12_000], $this->actor);
        $saved = CatalogService::getProduct($id);
        expect($saved['unit'])->toBe('pcs')
            ->and($saved['altUnit'])->toBe('Dozen')
            ->and($saved['altFactor'])->toBe(12_000);

        // And the alternate unit can be dropped altogether.
        CatalogService::updateProduct($id, [...UNITS_MILK, 'unit' => 'pcs', 'altUnit' => null, 'altFactor' => null], $this->actor);
        expect(CatalogService::getProduct($id)['altUnit'])->toBeNull()
            ->and(CatalogService::productUnitsLocked($id))->toBeFalse();
    });

    it('lets a wrong carton size be corrected while only opening stock is in', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);
        $valueBefore = InventoryService::getInventoryValue();

        // The edit page greys out the base unit, but not the carton.
        expect(CatalogService::productUnitsLocked($id))->toBeTrue()
            ->and(CatalogService::productAltUnitLocked($id))->toBeFalse();

        CatalogService::updateProduct($id, [...UNITS_MILK, 'altFactor' => 12_000], $this->actor);
        expect(CatalogService::getProduct($id)['altFactor'])->toBe(12_000)
            // The 100 tins counted in are still 100 tins, worth what they were.
            ->and(($this->qty)($id))->toBe(100_000)
            ->and(InventoryService::getInventoryValue())->toBe($valueBefore)
            ->and(InventoryService::verifyProductStock($id)['ok'])->toBeTrue();

        // The base unit stays fixed: 100 tins must not become 100 of something else.
        ($this->unitOnce)('pcs', 0);
        expect(fn () => CatalogService::updateProduct($id, [...UNITS_MILK, 'unit' => 'pcs'], $this->actor))->toThrow(ValidationError::class, 'unit cannot change');
    });

    it('keeps cartons counted in at the old size when the carton size is corrected', function () {
        $id = ($this->makeMilk)();
        $opening = ($this->cartonOpening)($id);
        $valueBefore = InventoryService::getInventoryValue();

        CatalogService::updateProduct($id, [...UNITS_MILK, 'altFactor' => 12_000], $this->actor);

        // The tins on the shelf and their value do not move with the label.
        expect(($this->qty)($id))->toBe(48_000)
            ->and(InventoryService::getInventoryValue())->toBe($valueBefore);
        // The opening document still says what was counted: 2 cartons of 24.
        $line = DB::table('stock_adjustment_items')->where('adjustment_id', $opening['adjustmentId'])->first();
        expect($line->entry_qty_milli)->toBe(2_000)->and($line->entry_factor_milli)->toBe(24_000);
        Shop::assertBooksHealthy('carton size corrected');

        // Voiding the opening takes out exactly the 48 tins it put in.
        StockAdjustmentService::voidStockAdjustment($opening['adjustmentId'], 'Counted wrong', $this->actor);
        expect(($this->qty)($id))->toBe(0);
        Shop::assertBooksHealthy('opening voided after the correction');
        expect(CatalogService::productAltUnitLocked($id))->toBeTrue();
    });

    it('keeps a carton opening whole when the carton is dropped altogether', function () {
        $id = ($this->makeMilk)();
        $opening = ($this->cartonOpening)($id);
        $valueBefore = InventoryService::getInventoryValue();

        CatalogService::updateProduct($id, [...UNITS_MILK, 'altUnit' => null, 'altFactor' => null, 'altSellingPrice' => null], $this->actor);

        expect(CatalogService::getProduct($id)['altUnit'])->toBeNull()
            ->and(($this->qty)($id))->toBe(48_000)
            ->and(InventoryService::getInventoryValue())->toBe($valueBefore);
        Shop::assertBooksHealthy('carton dropped');

        // The opening still reverses to exactly the 48 tins it put in.
        StockAdjustmentService::voidStockAdjustment($opening['adjustmentId'], 'Counted wrong', $this->actor);
        expect(($this->qty)($id))->toBe(0);
        Shop::assertBooksHealthy('opening voided after the carton was dropped');
    });

    it('locks the carton too once the product has been adjusted', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);
        ($this->writeOffOne)($id);
        expect(CatalogService::productUnitsLocked($id))->toBeTrue()
            ->and(CatalogService::productAltUnitLocked($id))->toBeTrue();
    });
});

describe('the Units list', function () {
    it('carries a rename to every product using the unit', function () {
        $id = ($this->makeMilk)();
        UnitsService::updateUnit('Carton', ['symbol' => 'Ctn', 'name' => 'Carton', 'decimalPlaces' => 0], $this->actor);
        expect(CatalogService::getProduct($id)['altUnit'])->toBe('Ctn');
    });

    it('will not delete a unit a product uses', function () {
        ($this->makeMilk)();
        expect(fn () => UnitsService::deleteUnit('Carton', $this->actor))->toThrow(ConflictError::class);
        UnitsService::createUnit(['symbol' => 'Dozen', 'name' => 'Dozen', 'decimalPlaces' => 0], $this->actor);
        UnitsService::deleteUnit('Dozen', $this->actor);
        expect(array_column(UnitsService::listUnits(), 'symbol'))->not->toContain('Dozen');
    });

    it('treats pcs and PCS as the same unit', function () {
        expect(fn () => UnitsService::createUnit(['symbol' => 'PCS', 'name' => 'Pieces', 'decimalPlaces' => 0], $this->actor))->toThrow(ConflictError::class);
    });
});

describe('narrowing a unit that already holds fractions', function () {
    it('refuses to make kg whole while 2.5 kg of rice is on the shelf', function () {
        ($this->unitOnce)('kg', 3);
        $rice = CatalogService::createProduct(['name' => 'Rice', 'unit' => 'kg', 'costPrice' => 1_400, 'sellingPrice' => 1_900], $this->actor);
        Shop::openingStock($this->actor, $rice, 2_500, 3_500, UNITS_TODAY);
        $decimalsOfKg = fn (): int => collect(UnitsService::listUnits())->firstWhere('symbol', 'kg')->decimal_places;

        // The last half kilo could never be sold or written off again.
        expect(fn () => UnitsService::updateUnit('kg', ['symbol' => 'kg', 'name' => 'Kilogram', 'decimalPlaces' => 0], $this->actor))->toThrow(ValidationError::class, 'Rice')
            ->and($decimalsOfKg())->toBe(3);

        // One decimal still fits 2.5, so that is allowed.
        UnitsService::updateUnit('kg', ['symbol' => 'kg', 'name' => 'Kilogram', 'decimalPlaces' => 1], $this->actor);
        expect($decimalsOfKg())->toBe(1);
    });
});

describe('a product form naming a unit that is not on the list', function () {
    it('is refused, rather than quietly adding the unit', function () {
        $before = count(UnitsService::listUnits());

        // A stale tab still showing a unit that has since been renamed.
        expect(fn () => CatalogService::createProduct([...UNITS_MILK, 'altUnit' => null, 'altFactor' => null, 'altSellingPrice' => null, 'unit' => 'Tinz', 'onlyListedUnits' => true], $this->actor))
            ->toThrow(ValidationError::class, 'Choose a unit from the list')
            ->and(count(UnitsService::listUnits()))->toBe($before);

        $id = ($this->makeMilk)();
        expect(fn () => CatalogService::updateProduct($id, [...UNITS_MILK, 'altUnit' => 'Crate', 'onlyListedUnits' => true], $this->actor))
            ->toThrow(ValidationError::class, 'Choose a unit from the list')
            ->and(array_column(UnitsService::listUnits(), 'symbol'))->not->toContain('Crate');
    });
});

describe('voiding documents taken in cartons', function () {
    it('puts a voided carton write-off back on the shelf', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);
        $writeOff = ($this->cartonWriteOff)($id);

        $reversal = StockAdjustmentService::voidStockAdjustment($writeOff['adjustmentId'], 'Found it unbroken', $this->actor);

        expect(($this->qty)($id))->toBe(100_000);
        $mirror = StockAdjustmentService::getStockAdjustment($reversal['adjustmentId'])['items'][0];
        expect($mirror->qty_milli)->toBe(24_000)
            ->and($mirror->entry_unit)->toBe('Carton')
            ->and($mirror->entry_qty_milli)->toBe(1_000);
        Shop::assertBooksHealthy('carton write-off void');
    });
});

describe('a carton size changed while a screen was open', function () {
    it('refuses a write-off entered at the old size, and moves nothing', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);
        // The owner corrects the carton from 24 to 48 tins after the screen loaded.
        DB::table('products')->where('id', $id)->update(['alt_factor_milli' => 48_000]);
        $snapshot = fn (): array => [
            'qty' => (int) Shop::productRow($id)->qty_on_hand_milli,
            'value' => (int) Shop::productRow($id)->stock_value_minor,
            'ledgerRows' => DB::table('stock_ledger')->count(),
            'journalEntries' => DB::table('journal_entries')->count(),
        ];
        $before = $snapshot();

        expect(fn () => ($this->cartonWriteOff)($id, 24_000))->toThrow(ConflictError::class)
            ->and($snapshot())->toBe($before);
    });
});

describe('renaming a unit and racing another till', function () {
    it('renames a unit by case alone, taking its products with it', function () {
        $actor = Shop::owner('caser');
        UnitsService::createUnit(['symbol' => 'tin', 'name' => 'Tin', 'decimalPlaces' => 0], $actor);
        $product = Shop::product($actor, 'Sardines', extra: ['unit' => 'tin']);

        UnitsService::updateUnit('tin', ['symbol' => 'Tin', 'name' => 'Tin', 'decimalPlaces' => 0], $actor);

        expect(DB::table('units')->where('symbol', 'Tin')->exists())->toBeTrue()
            ->and(DB::table('products')->where('id', $product)->value('unit'))->toBe('Tin');
    });

    it('turns a duplicate key from a lost race into a conflict the person can act on', function () {
        // Both tills passed the "is it taken?" check; the unique index caught the second insert.
        Tx::run(fn () => DB::table('units')->insert(['symbol' => 'PCS', 'name' => 'Pieces again']));
    })->throws(ConflictError::class, 'Someone else saved the same code or name at the same moment.');
});
