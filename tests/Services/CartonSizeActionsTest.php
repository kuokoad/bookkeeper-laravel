<?php

declare(strict_types=1);

/*
| Ported from the old tests/app/carton-size-actions.test.ts, at the service
| level: the write-off refuses a carton line entered at a size that has since
| changed, names the new size, and moves nothing.
|
| Deferred: everything that drives a screen's own action (the till, delivery
| and quote screens, a payload missing the size, a size that cannot be read)
| waits for those screens (after Phase 5). The till and quote services' own
| refusals are in UnitsTest; the delivery's waits for purchases (Phase 5). The parsing rule the actions share is
| Quantity::parseAltEntry, already tested in tests/Domain/QuantityTest.php.
*/

use App\Domain\Errors\StaleUnitError;
use App\Enums\AdjustmentReason;
use App\Services\CatalogService;
use App\Services\StockAdjustmentService;
use App\Services\UnitsService;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    UnitsService::createUnit(['symbol' => 'tin', 'name' => 'Tin', 'decimalPlaces' => 0], $this->actor);
    UnitsService::createUnit(['symbol' => 'Carton', 'name' => 'Carton', 'decimalPlaces' => 0], $this->actor);
    $this->milk = CatalogService::createProduct([
        'name' => 'Evaporated Milk', 'unit' => 'tin', 'altUnit' => 'Carton', 'altFactor' => 24_000,
        'costPrice' => 400, 'sellingPrice' => 500, 'altSellingPrice' => 11_000,
    ], $this->actor);
    Shop::openingStock($this->actor, $this->milk, 200_000, 80_000, Clock::today());
    // Everything a refused entry must leave exactly as it was.
    $this->books = fn (): array => [
        'qty' => (int) Shop::productRow($this->milk)->qty_on_hand_milli,
        'value' => (int) Shop::productRow($this->milk)->stock_value_minor,
        'ledgerRows' => DB::table('stock_ledger')->count(),
        'journalEntries' => DB::table('journal_entries')->count(),
    ];
    $this->writeOff = fn (int $shownFactor) => StockAdjustmentService::createStockAdjustment([
        'businessDate' => Clock::today(),
        'reason' => AdjustmentReason::Damaged,
        'items' => [['productId' => $this->milk, 'direction' => 'OUT', 'qty' => 1_000, 'alt' => true, 'altFactor' => $shownFactor]],
    ], $this->actor);
});

describe('a carton size that changed while the screen was open', function () {
    it('is refused on the write-off with the new size, not thrown', function () {
        DB::table('products')->where('id', $this->milk)->update(['alt_factor_milli' => 48_000]);
        $before = ($this->books)();

        try {
            ($this->writeOff)(24_000);
            $this->fail('A stale carton size was accepted.');
        } catch (StaleUnitError $error) {
            expect($error->userMessage)->toContain('is now 48 tin, not 24')
                // What the till needs to correct a line in place.
                ->and($error->current)->toBe([
                    'productId' => $this->milk,
                    'productName' => 'Evaporated Milk',
                    'unit' => 'tin',
                    'altUnit' => 'Carton',
                    'altFactorMilli' => 48_000,
                    'altSellingPriceMinor' => 11_000,
                    'shownFactorMilli' => 24_000,
                ]);
        }
        expect(($this->books)())->toBe($before);
    });

    it('still writes off at the size the screen showed when it is current', function () {
        ($this->writeOff)(24_000);
        expect(($this->books)()['qty'])->toBe(176_000);
    });
});
