<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/batch-reporting.test.ts: how long the
| stock has left, the recall trail, and correcting the date on a crate.
|
| Deferred: the recall-trail tests that run a purchase, sale or void ("lists
| the delivery in and every sale out", "shows a void as its own line", "follows
| a voided delivery back out of the crate it filled") wait for purchases
| (Phase 5): each fills its crate with a delivery. "finding a batch by its
| reference" (3 tests) waits for the search service (Phase 8).
*/

use App\Domain\BusinessDate;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const REPORTING_TODAY = '2026-08-25';
const REPORTING_SOON = '2026-09-10';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->make = fn (string $name = 'Evaporated Milk'): int => Shop::product($this->actor, $name, 300, 500, ['unit' => 'tin']);
    // Stock in, into a batch with the given date. Returns the batch id.
    $this->deliver = fn (int $id, int $units, ?string $expiryDate): int => Shop::move([
        'productId' => $id,
        'direction' => 'IN',
        'qty' => $units * 1000,
        'totalCost' => 300 * $units,
        'movementType' => 'PURCHASE',
        'sourceType' => 'TEST',
        'businessDate' => REPORTING_TODAY,
        'occurredAt' => Clock::fromStamp(REPORTING_TODAY.' 08:00:00'),
        'userId' => $this->actor->id,
        'batch' => ['kind' => 'NEW', 'expiryDate' => $expiryDate],
    ])['batchAllocations'][0]['batchId'];
    $this->byBucket = fn (): array => collect(InventoryService::getExpiryAgeing(REPORTING_TODAY))->keyBy('bucket')->all();
    $this->expiryOf = fn (int $batchId): ?string => DB::table('product_batches')->where('id', $batchId)->value('expiry_date');
});

describe('how long the stock has left', function () {
    it('puts every batch in exactly one bucket, adding up to the shelf', function () {
        $milk = ($this->make)();
        ($this->deliver)($milk, 3, BusinessDate::addDays(REPORTING_TODAY, -1)); // expired
        ($this->deliver)($milk, 5, BusinessDate::addDays(REPORTING_TODAY, 3)); // within 7
        ($this->deliver)($milk, 7, BusinessDate::addDays(REPORTING_TODAY, 20)); // within 30
        ($this->deliver)($milk, 11, BusinessDate::addDays(REPORTING_TODAY, 60)); // within 90
        ($this->deliver)($milk, 13, BusinessDate::addDays(REPORTING_TODAY, 400)); // later
        ($this->deliver)($milk, 17, null); // undated

        $ageing = InventoryService::getExpiryAgeing(REPORTING_TODAY);
        $byBucket = ($this->byBucket)();

        expect($byBucket['expired']['qtyMilli'])->toBe(3_000)
            ->and($byBucket['within7']['qtyMilli'])->toBe(5_000)
            ->and($byBucket['within30']['qtyMilli'])->toBe(7_000)
            ->and($byBucket['within90']['qtyMilli'])->toBe(11_000)
            ->and($byBucket['later']['qtyMilli'])->toBe(13_000)
            ->and($byBucket['undated']['qtyMilli'])->toBe(17_000)
            // The whole point of buckets: they account for everything, once.
            ->and(array_sum(array_column($ageing, 'qtyMilli')))->toBe(Shop::productRow($milk)->qty_on_hand_milli)
            ->and(array_sum(array_column($ageing, 'batchCount')))->toBe(6);
    });

    it('never files undated stock under "later", because unknown is not distant', function () {
        $bread = ($this->make)('Tea Bread');
        ($this->deliver)($bread, 20, null);

        $byBucket = ($this->byBucket)();
        expect($byBucket['later']['qtyMilli'])->toBe(0)->and($byBucket['undated']['qtyMilli'])->toBe(20_000);
    });

    it('counts the last good day as still good', function () {
        $milk = ($this->make)();
        ($this->deliver)($milk, 4, REPORTING_TODAY);

        $byBucket = ($this->byBucket)();
        expect($byBucket['expired']['qtyMilli'])->toBe(0)->and($byBucket['within7']['qtyMilli'])->toBe(4_000);
    });

    it('returns every bucket even when the shop has no stock at all', function () {
        // A report with rows missing reads as a report that failed.
        $ageing = InventoryService::getExpiryAgeing(REPORTING_TODAY);
        expect(array_column($ageing, 'bucket'))->toBe(InventoryService::EXPIRY_BUCKETS)
            ->and(collect($ageing)->every(fn (array $row): bool => $row['qtyMilli'] === 0 && $row['batchCount'] === 0))->toBeTrue();
    });

    it('drops a batch out once it is emptied', function () {
        $milk = ($this->make)();
        ($this->deliver)($milk, 4, BusinessDate::addDays(REPORTING_TODAY, 3));
        Shop::stockOut($this->actor, $milk, 4_000, REPORTING_TODAY);

        expect(collect(InventoryService::getExpiryAgeing(REPORTING_TODAY))->every(fn (array $row): bool => $row['batchCount'] === 0))->toBeTrue();
    });
});

describe('tracing a delivery to the people who took it home', function () {
    it('has nothing to show for a batch the migration opened', function () {
        // An opening batch was never delivered by anybody.
        $milk = ($this->make)();
        $batchId = DB::table('product_batches')->insertGetId([
            'product_id' => $milk, 'batch_ref' => 'BAT-OPEN-00001', 'expiry_date' => null, 'received_date' => REPORTING_TODAY,
            'qty_milli' => 5_000, 'opening_qty_milli' => 5_000, 'source_type' => 'OPENING',
        ]);

        $history = InventoryService::getBatchHistory($batchId);
        expect($history['entries'])->toBe([])
            ->and($history['batch']['openingQtyMilli'])->toBe(5_000)
            ->and($history['batch']['supplierName'])->toBeNull();
    });

    it('refuses a batch that does not exist', function () {
        expect(fn () => InventoryService::getBatchHistory(9_999))->toThrow(NotFoundError::class);
    });
});

describe('correcting the date on a crate', function () {
    it('gives a date to stock that arrived without one', function () {
        $batchId = ($this->deliver)(($this->make)(), 10, null);

        InventoryService::setBatchExpiry($batchId, BusinessDate::addDays(REPORTING_TODAY, 5), $this->actor);

        expect(($this->expiryOf)($batchId))->toBe(BusinessDate::addDays(REPORTING_TODAY, 5));
    });

    it('changes which crate the till reaches for next', function () {
        $milk = ($this->make)();
        $undated = ($this->deliver)($milk, 10, null);
        $dated = ($this->deliver)($milk, 10, BusinessDate::addDays(REPORTING_TODAY, 60));

        expect(array_column(InventoryService::listProductBatches($milk, REPORTING_TODAY), 'id'))->toBe([$dated, $undated]);

        InventoryService::setBatchExpiry($undated, BusinessDate::addDays(REPORTING_TODAY, 3), $this->actor);

        expect(array_column(InventoryService::listProductBatches($milk, REPORTING_TODAY), 'id'))->toBe([$undated, $dated]);
    });

    it('records what it was before, not just what it is now', function () {
        $batchId = ($this->deliver)(($this->make)(), 10, BusinessDate::addDays(REPORTING_TODAY, 30));

        InventoryService::setBatchExpiry($batchId, BusinessDate::addDays(REPORTING_TODAY, 2), $this->actor);

        $entry = DB::table('audit_logs')->where('entity_type', 'product_batch')->first();
        expect($entry)->not->toBeNull('changing a date is audited');
        $metadata = json_decode($entry->metadata, true);
        expect($metadata['before']['expiryDate'])->toBe(BusinessDate::addDays(REPORTING_TODAY, 30))
            ->and($metadata['after']['expiryDate'])->toBe(BusinessDate::addDays(REPORTING_TODAY, 2));
    });

    it('writes nothing when the date has not actually changed', function () {
        $batchId = ($this->deliver)(($this->make)(), 10, BusinessDate::addDays(REPORTING_TODAY, 30));

        InventoryService::setBatchExpiry($batchId, BusinessDate::addDays(REPORTING_TODAY, 30), $this->actor);

        expect(DB::table('audit_logs')->where('entity_type', 'product_batch')->count())->toBe(0);
    });

    it('can take a date away again', function () {
        $batchId = ($this->deliver)(($this->make)(), 10, BusinessDate::addDays(REPORTING_TODAY, 30));

        InventoryService::setBatchExpiry($batchId, null, $this->actor);

        expect(($this->expiryOf)($batchId))->toBeNull();
    });

    it('refuses a date that is not a date', function () {
        $batchId = ($this->deliver)(($this->make)(), 10, null);
        expect(fn () => InventoryService::setBatchExpiry($batchId, '31/12/2027', $this->actor))->toThrow(ValidationError::class);
    });

    it('refuses a batch that does not exist', function () {
        expect(fn () => InventoryService::setBatchExpiry(9_999, REPORTING_TODAY, $this->actor))->toThrow(NotFoundError::class);
    });

    it('does not touch the quantity, which only a movement may change', function () {
        $batchId = ($this->deliver)(($this->make)(), 10, null);

        InventoryService::setBatchExpiry($batchId, BusinessDate::addDays(REPORTING_TODAY, 5), $this->actor);

        expect(DB::table('product_batches')->where('id', $batchId)->value('qty_milli'))->toBe(10_000);
    });
});

describe('whether the shop has anything dated', function () {
    it('is false in a shop that has never entered a date', function () {
        ($this->deliver)(($this->make)(), 20, null);
        expect(CatalogService::hasDatedStock())->toBeFalse();
    });

    it('is true the moment one delivery carries a date', function () {
        $milk = ($this->make)();
        ($this->deliver)($milk, 20, null);
        ($this->deliver)($milk, 5, REPORTING_SOON);

        expect(CatalogService::hasDatedStock())->toBeTrue();
    });

    it('is false again once the dated stock is gone', function () {
        $batch = ($this->deliver)(($this->make)(), 5, REPORTING_SOON);
        DB::table('product_batches')->where('id', $batch)->update(['qty_milli' => 0, 'is_closed' => true]);

        expect(CatalogService::hasDatedStock())->toBeFalse();
    });
});
