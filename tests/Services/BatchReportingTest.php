<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/batch-reporting.test.ts: how long the
| stock has left, the recall trail, and correcting the date on a crate.
|
| Deferred: "finding a batch by its reference" (3 tests) waits for the search
| service (Phase 8).
*/

use App\Domain\BusinessDate;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\SaleService;
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
    $this->cash = Shop::paymentAccount('CASH');
    $this->supplier = Shop::supplier($this->actor, 'Kofi Wholesale');
    // A real delivery at 3.00 a tin, dated 30 days out, paid in cash.
    $this->buy = fn (int $id, int $units): array => Shop::purchase($this->actor, $this->supplier, [
        ['productId' => $id, 'qty' => $units * 1000, 'unitCost' => 300, 'expiryDate' => BusinessDate::addDays(REPORTING_TODAY, 30)],
    ], [['paymentAccountId' => $this->cash, 'amount' => 300 * $units]], ['businessDate' => REPORTING_TODAY]);
    $this->sellAt = fn (int $id, int $units, int $paid, array $extra = []): array => Shop::sell($this->actor, [[$id, $units * 1000]], [['paymentAccountId' => $this->cash, 'amount' => $paid]], ['businessDate' => REPORTING_TODAY, ...$extra]);
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
    it('lists the delivery in and every sale out, in order', function () {
        $milk = ($this->make)();
        $ama = Shop::customer($this->actor, 'Ama Serwaa');
        ($this->buy)($milk, 10);
        $batchId = (int) DB::table('product_batches')->orderBy('id')->value('id');

        ($this->sellAt)($milk, 3, 1_500, ['customerId' => $ama]);
        ($this->sellAt)($milk, 2, 1_000);

        $history = InventoryService::getBatchHistory($batchId);

        expect($history['batch']['batchRef'])->toMatch('/^BAT-\d{5}$/')
            ->and($history['batch']['productName'])->toBe('Evaporated Milk')
            ->and($history['batch']['supplierName'])->toBe('Kofi Wholesale')
            ->and($history['batch']['qtyMilli'])->toBe(5_000)
            ->and(array_map(fn (array $e): array => [$e['sourceType'], $e['qtyInMilli'], $e['qtyOutMilli']], $history['entries']))->toBe([
                ['PURCHASE', 10_000, 0],
                ['SALE', 0, 3_000],
                ['SALE', 0, 2_000],
            ])
            // The names are the point. A walk-in is a real answer, not a missing one.
            ->and(array_column($history['entries'], 'partyName'))->toBe(['Kofi Wholesale', 'Ama Serwaa', 'Walk-in customer'])
            ->and(collect($history['entries'])->every(fn (array $e): bool => $e['sourceRef'] !== null))->toBeTrue();
    });

    it('shows a void as its own line rather than erasing the sale', function () {
        $milk = ($this->make)();
        ($this->buy)($milk, 10);
        $batchId = (int) DB::table('product_batches')->orderBy('id')->value('id');

        $sale = ($this->sellAt)($milk, 4, 2_000);
        SaleService::voidSale($sale['saleId'], 'Rang it up twice', $this->actor);

        $history = InventoryService::getBatchHistory($batchId);
        expect(array_column($history['entries'], 'sourceType'))->toBe(['PURCHASE', 'SALE', 'SALE_VOID'])
            ->and($history['batch']['qtyMilli'])->toBe(10_000);
    });

    it('follows a voided delivery back out of the crate it filled', function () {
        $milk = ($this->make)();
        $purchase = ($this->buy)($milk, 6);
        $batchId = (int) DB::table('product_batches')->orderBy('id')->value('id');

        PurchaseService::voidPurchase($purchase['purchaseId'], 'Never arrived', $this->actor);

        $history = InventoryService::getBatchHistory($batchId);
        expect(array_column($history['entries'], 'sourceType'))->toBe(['PURCHASE', 'PURCHASE_VOID'])
            ->and($history['entries'][1]['partyName'])->toBe('Kofi Wholesale')
            ->and($history['batch']['qtyMilli'])->toBe(0)
            ->and($history['batch']['isClosed'])->toBeTrue();
    });

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

it('keeps kilograms and tins apart in the expiry ageing instead of adding them up', function () {
    $actor = $this->actor;
    $rice = Shop::product($actor, 'Rice loose', 500, 800, ['unit' => 'kg']);
    $tins = Shop::product($actor, 'Tomato paste', 300, 500, ['unit' => 'tin']);
    foreach ([[$rice, 12_500], [$tins, 30_000]] as [$product, $qty]) {
        Shop::move(['productId' => $product, 'direction' => 'IN', 'qty' => $qty, 'totalCost' => 1_000, 'movementType' => 'PURCHASE', 'sourceType' => 'TEST',
            'businessDate' => REPORTING_TODAY, 'occurredAt' => Clock::fromStamp(REPORTING_TODAY.' 08:00:00'), 'batch' => ['kind' => 'NEW', 'expiryDate' => '2027-06-30']]);
    }

    $later = collect(InventoryService::getExpiryAgeing(REPORTING_TODAY))->firstWhere('bucket', 'later');
    expect($later['byUnit'])->toBe(['kg' => 12_500, 'tin' => 30_000]);
});

it('reads the expiry outlook of one product without the rest of the shop, for the till adding a line', function () {
    $rice = Shop::product($this->actor, 'Rice 5kg', 1_000, 3_000);
    $beans = Shop::product($this->actor, 'Beans 5kg', 1_000, 3_000);
    foreach ([$rice, $beans] as $id) {
        Shop::move(['productId' => $id, 'direction' => 'IN', 'qty' => 5_000, 'totalCost' => 5_000, 'movementType' => 'ADJUSTMENT_IN', 'sourceType' => 'TEST',
            'businessDate' => REPORTING_TODAY, 'occurredAt' => Clock::fromStamp(REPORTING_TODAY.' 08:00:00'), 'batch' => ['kind' => 'NEW', 'expiryDate' => '2027-06-30']]);
    }

    $one = InventoryService::getExpiryOutlook(REPORTING_TODAY, $beans);
    expect(array_keys($one))->toBe([$beans])
        ->and($one[$beans])->toBe(InventoryService::getExpiryOutlook(REPORTING_TODAY)[$beans]);
});
