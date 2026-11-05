<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/units.test.ts: buy by the carton, sell by
| the carton or the tin, and the shelf, the money and the books agree in tins.
| The whole old file is ported; nothing is deferred.
*/

use App\Database\Tx;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\ForbiddenError;
use App\Domain\Errors\ValidationError;
use App\Enums\AdjustmentReason;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\QuotationService;
use App\Services\ReturnsService;
use App\Services\SaleService;
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
    $this->sell = fn (array $items, int $cash): array => SaleService::createSale(['businessDate' => UNITS_TODAY, 'items' => $items, 'tenders' => [['paymentAccountId' => Shop::paymentAccount('CASH'), 'amount' => $cash]]], $this->actor);
    $this->quote = fn (array $lines): array => QuotationService::createQuotation(['businessDate' => UNITS_TODAY, 'validUntil' => '2026-09-15', 'customerName' => 'Ama', 'lines' => $lines], $this->actor);
    $this->convert = fn (int $quoteId, int $cash): array => QuotationService::convertQuotation($quoteId, ['businessDate' => UNITS_TODAY, 'tenders' => [['paymentAccountId' => Shop::paymentAccount('CASH'), 'amount' => $cash]]], $this->actor);
    // 3 cartons at 200.00 each, from the Depot, paid in cash; altFactor is the size the screen showed.
    $this->buyCartons = fn (int $id, ?int $shown = null): array => Shop::purchase($this->actor, Shop::supplier($this->actor, 'Depot'), [
        ['productId' => $id, 'qty' => 3_000, 'alt' => true, 'altFactor' => $shown, 'unitCost' => 20_000],
    ], [['paymentAccountId' => Shop::paymentAccount('CASH'), 'amount' => 60_000]], ['businessDate' => UNITS_TODAY]);
    $this->cartonWriteOff = fn (int $id, ?int $shown = null) => StockAdjustmentService::createStockAdjustment([
        'businessDate' => UNITS_TODAY,
        'reason' => AdjustmentReason::Damaged,
        'items' => [['productId' => $id, 'direction' => 'OUT', 'qty' => 1_000, 'alt' => true, 'altFactor' => $shown]],
    ], $this->actor);
});

describe('selling by the carton', function () {
    it('takes 48 tins off the shelf for 2 cartons, at the carton price', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);

        $sale = ($this->sell)([['productId' => $id, 'qty' => 2_000, 'alt' => true]], 22_000);

        expect($sale['total'])->toBe(22_000) // 2 x 110.00, not 2 x 5.00
            ->and($sale['cogs'])->toBe(19_200) // 48 tins at 4.00
            ->and(($this->qty)($id))->toBe(52_000)
            ->and((int) DB::table('stock_ledger')->where('source_type', 'SALE')->where('source_id', $sale['saleId'])->value('qty_out_milli'))->toBe(48_000);

        // The receipt shows what the customer asked for.
        $line = SaleService::getSale($sale['saleId'])['items'][0];
        expect($line['entryUnit'])->toBe('Carton')
            ->and($line['entryQtyMilli'])->toBe(2_000)
            ->and($line['qtyMilli'])->toBe(48_000)
            ->and($line['unit'])->toBe('tin');
        Shop::assertBooksHealthy('carton sale');
    });

    it('defaults a carton to 24 tins at the tin price when no carton price is set', function () {
        $id = ($this->makeMilk)(['altSellingPrice' => null]);
        ($this->stockUp)($id);

        expect(($this->sell)([['productId' => $id, 'qty' => 1_000, 'alt' => true]], 12_000)['total'])->toBe(12_000);
    });

    it('refuses a cashier pricing a carton at the tin price', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);

        expect(fn () => ($this->sell)([['productId' => $id, 'qty' => 1_000, 'alt' => true, 'unitPrice' => 500]], 500))->toThrow(ForbiddenError::class);
    });

    it('refuses half a tin, because tins are counted whole', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);

        expect(fn () => ($this->sell)([['productId' => $id, 'qty' => 1_500]], 750))->toThrow(ValidationError::class, 'whole numbers')
            ->and(($this->qty)($id))->toBe(100_000);
    });

    it('puts the cartons back on the shelf when the sale is voided', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);
        $sale = ($this->sell)([['productId' => $id, 'qty' => 2_000, 'alt' => true]], 22_000);

        SaleService::voidSale($sale['saleId'], 'Rang up twice', $this->actor);

        $mirror = DB::table('sale_items')->where('sale_id', '<>', $sale['saleId'])->first();
        expect(($this->qty)($id))->toBe(100_000)
            ->and((int) $mirror->entry_qty_milli)->toBe(-2_000)
            ->and((int) $mirror->qty_milli)->toBe(-48_000);
        Shop::assertBooksHealthy('carton void');
    });
});

describe('buying by the carton', function () {
    it('adds 72 tins and 600.00 of stock for 3 cartons at 200.00', function () {
        $id = ($this->makeMilk)();
        ($this->buyCartons)($id);

        $product = CatalogService::getProduct($id);
        expect($product['qtyOnHand'])->toBe(72_000)
            ->and($product['stockValue'])->toBe(60_000);

        $line = DB::table('purchase_items')->first();
        expect($line->qty_milli)->toBe(72_000)
            ->and($line->entry_qty_milli)->toBe(3_000)
            ->and($line->unit_cost_minor)->toBe(20_000);
        Shop::assertBooksHealthy('carton purchase');
    });
});

describe('quoting and adjusting by the carton', function () {
    it('turns a quote for 2 cartons into a sale of 2 cartons', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);

        $quote = ($this->quote)([['productId' => $id, 'qty' => 2_000, 'alt' => true]]);
        expect($quote['total'])->toBe(22_000);

        $converted = ($this->convert)($quote['quotationId'], 22_000);
        $line = SaleService::getSale($converted['saleId'])['items'][0];
        expect($line['entryUnit'])->toBe('Carton')
            ->and($line['entryQtyMilli'])->toBe(2_000)
            ->and(($this->qty)($id))->toBe(52_000);
    });

    it('writes off a damaged carton as 24 tins', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);

        ($this->cartonWriteOff)($id);

        expect(($this->qty)($id))->toBe(76_000);
        Shop::assertBooksHealthy('carton write-off');
    });
});

describe('returning part of a carton sale', function () {
    it('takes back 5 tins and refunds 5/48 of the line', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);
        $sale = ($this->sell)([['productId' => $id, 'qty' => 2_000, 'alt' => true]], 22_000);
        $item = SaleService::getSale($sale['saleId'])['items'][0];

        $result = ReturnsService::createCustomerReturn($sale['saleId'], [
            'businessDate' => UNITS_TODAY,
            'items' => [['itemId' => $item['id'], 'qty' => 5_000]],
            'refunds' => [['paymentAccountId' => Shop::paymentAccount('CASH'), 'amount' => 2_292]],
        ], $this->actor);

        // 22,000 x 5 / 48 = 2,291.67, rounded to the pesewa.
        expect($result['refunded'])->toBe(2_292)
            ->and(($this->qty)($id))->toBe(57_000);
        Shop::assertBooksHealthy('partial return');
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
    it('takes a voided carton purchase back off the shelf and the books', function () {
        $id = ($this->makeMilk)();
        $purchase = ($this->buyCartons)($id);

        $reversalPurchaseId = PurchaseService::voidPurchase($purchase['purchaseId'], 'Wrong supplier', $this->actor)['reversalPurchaseId'];

        expect(($this->qty)($id))->toBe(0)
            ->and(CatalogService::getProduct($id)['stockValue'])->toBe(0);
        $mirror = DB::table('purchase_items')->where('purchase_id', $reversalPurchaseId)->first();
        expect($mirror->qty_milli)->toBe(-72_000)
            ->and($mirror->entry_unit)->toBe('Carton')
            ->and($mirror->entry_qty_milli)->toBe(-3_000);
        Shop::assertBooksHealthy('carton purchase void');
    });

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
    beforeEach(function () {
        // The owner corrects the carton from 24 to 48 tins after the screen loaded.
        $this->cartonBecomes48 = fn (int $id) => DB::table('products')->where('id', $id)->update(['alt_factor_milli' => 48_000]);
        // Everything a refused entry must leave exactly as it was.
        $this->snapshot = fn (int $id): array => [
            'qty' => (int) Shop::productRow($id)->qty_on_hand_milli,
            'value' => (int) Shop::productRow($id)->stock_value_minor,
            'ledgerRows' => DB::table('stock_ledger')->count(),
            'journalEntries' => DB::table('journal_entries')->count(),
        ];
    });

    it('refuses a till sale of "2 Carton" rung up at the old size, and moves nothing', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);
        ($this->cartonBecomes48)($id);
        $before = ($this->snapshot)($id);

        expect(fn () => ($this->sell)([['productId' => $id, 'qty' => 2_000, 'alt' => true, 'altFactor' => 24_000]], 22_000))->toThrow(ConflictError::class)
            ->and(($this->snapshot)($id))->toBe($before);
    });

    it('still sells when the till showed the current size', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);
        ($this->cartonBecomes48)($id);

        ($this->sell)([['productId' => $id, 'qty' => 1_000, 'alt' => true, 'altFactor' => 48_000]], 11_000);

        expect(($this->qty)($id))->toBe(52_000);
        Shop::assertBooksHealthy('sale at the current carton size');
    });

    it('refuses a delivery entered at the old size, and moves nothing', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);
        ($this->cartonBecomes48)($id);
        $before = ($this->snapshot)($id);

        expect(fn () => ($this->buyCartons)($id, 24_000))->toThrow(ConflictError::class)
            ->and(($this->snapshot)($id))->toBe($before);
    });

    it('refuses a write-off entered at the old size, and moves nothing', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);
        ($this->cartonBecomes48)($id);
        $before = ($this->snapshot)($id);

        expect(fn () => ($this->cartonWriteOff)($id, 24_000))->toThrow(ConflictError::class)
            ->and(($this->snapshot)($id))->toBe($before);
    });

    it('refuses saving a quote at the old size', function () {
        $id = ($this->makeMilk)();
        ($this->cartonBecomes48)($id);

        expect(fn () => ($this->quote)([['productId' => $id, 'qty' => 2_000, 'alt' => true, 'altFactor' => 24_000]]))->toThrow(ConflictError::class);
    });

    it('sends a quote back for editing rather than selling today’s carton at the quoted price', function () {
        $id = ($this->makeMilk)();
        ($this->stockUp)($id);
        $quote = ($this->quote)([['productId' => $id, 'qty' => 2_000, 'alt' => true]]);
        ($this->cartonBecomes48)($id);
        $before = ($this->snapshot)($id);

        // Quoted 2 cartons of 24 for 220.00: converting must not take 96 tins for it.
        expect(fn () => ($this->convert)($quote['quotationId'], 22_000))->toThrow(ConflictError::class, 'Edit quote')
            ->and(($this->snapshot)($id))->toBe($before);
    });

    it('sends a quote back when its base unit changed, even at the same number', function () {
        $id = ($this->makeMilk)();
        $quote = ($this->quote)([['productId' => $id, 'qty' => 2_000, 'alt' => true]]);
        // Before any stock moved, the owner re-set the product to count bags, still 24 a carton.
        ($this->unitOnce)('bag', 0);
        DB::table('products')->where('id', $id)->update(['unit' => 'bag']);
        ($this->stockUp)($id);
        $before = ($this->snapshot)($id);

        // Quoted 48 tins; converting must not sell 48 bags at that price.
        expect(fn () => ($this->convert)($quote['quotationId'], 22_000))->toThrow(ConflictError::class, 'Edit quote')
            ->and(($this->snapshot)($id))->toBe($before);
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
