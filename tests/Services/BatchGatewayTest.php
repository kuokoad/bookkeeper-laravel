<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/batch-gateway.test.ts: every movement
| writes down which batch it touched, inside the gateway, and coverage holds
| (Σ batch quantities === the shelf) after any sequence of movements. The
| whole old file is ported; nothing is deferred.
*/

use App\Domain\Errors\ExpiredStockError;
use App\Enums\AdjustmentReason;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\StockAdjustmentService;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const GATEWAY_DAY = '2026-08-10';
const GATEWAY_LATER = '2026-08-12';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->make = fn (string $name = 'Rice 5kg'): int => Shop::product($this->actor, $name, 1_000, 3_000, ['unit' => 'bag']);
    $this->batchesOf = fn (int $id): array => DB::table('product_batches')->where('product_id', $id)->orderBy('id')->get()->all();
    // Stock in through the gateway directly, the only way to give it a directive.
    $this->moveIn = fn (int $id, int $units, int $cost, ?array $batch = null): array => Shop::move([
        'productId' => $id,
        'direction' => 'IN',
        'qty' => $units * 1000,
        'totalCost' => $cost,
        'movementType' => 'ADJUSTMENT_IN',
        'sourceType' => 'TEST',
        'businessDate' => GATEWAY_DAY,
        'occurredAt' => Clock::fromStamp(GATEWAY_DAY.' 09:00:00'),
        ...($batch === null ? [] : ['batch' => ['kind' => 'NEW', 'expiryDate' => $batch['expiryDate'] ?? null]]),
    ])['batchAllocations'];
    $this->moveOut = function (int $id, int $units, array $options = []): array {
        $date = $options['businessDate'] ?? GATEWAY_DAY;

        return Shop::move([
            'productId' => $id,
            'direction' => 'OUT',
            'qty' => $units * 1000,
            'movementType' => 'ADJUSTMENT_OUT',
            'sourceType' => 'TEST',
            'businessDate' => $date,
            'occurredAt' => Clock::fromStamp("{$date} 15:00:00"),
            'allowNegative' => $options['allowNegative'] ?? false,
            'batch' => ['kind' => 'PICK', 'allowExpired' => $options['allowExpired'] ?? false],
        ])['batchAllocations'];
    };
    $this->cash = Shop::paymentAccount('CASH');
    $this->supplier = Shop::supplier($this->actor, 'Kofi Wholesale');
    // A delivery paid in full in cash, as the old tests ran them.
    $this->deliver = fn (int $id, int $units, int $unitCost): array => Shop::purchase($this->actor, $this->supplier, [[$id, $units * 1000, $unitCost]], [['paymentAccountId' => $this->cash, 'amount' => $units * $unitCost]], ['businessDate' => GATEWAY_DAY]);
    $this->sellAt = fn (int $id, int $units, int $paid): array => Shop::sell($this->actor, [[$id, $units * 1000]], [['paymentAccountId' => $this->cash, 'amount' => $paid]], ['businessDate' => GATEWAY_DAY]);
    $this->expiryOf = function (int $id): array {
        return DB::table('product_batches')->where('product_id', $id)->pluck('expiry_date', 'id')->all();
    };
});

/** Coverage, per-batch backing, and the ledger split: all of it, everywhere. */
function gatewayIntact(string $label): void
{
    foreach (InventoryService::verifyBatchCoverage() as $row) {
        expect($row['ok'])->toBeTrue("{$label}: product {$row['productId']} coverage");
    }
    foreach (DB::table('product_batches')->distinct()->pluck('product_id') as $productId) {
        foreach (InventoryService::verifyProductBatches((int) $productId) as $check) {
            expect($check['ok'])->toBeTrue("{$label}: batch {$check['batchId']} backing");
        }
    }
    // Every ledger row's split adds back to its movement. True here because every
    // movement ran through the gateway; NOT true of imported history that predates batches.
    $splits = DB::table('stock_ledger_batches')->groupBy('ledger_id')
        ->selectRaw('ledger_id, SUM(qty_in_milli + qty_out_milli) AS moved')->pluck('moved', 'ledger_id')->all();
    foreach (DB::table('stock_ledger')->get() as $row) {
        expect((int) ($splits[$row->id] ?? 0))->toBe($row->qty_in_milli + $row->qty_out_milli, "{$label}: ledger {$row->id} split");
    }
}

describe('the paths the shop already uses, which asked for nothing', function () {
    it('lands a delivery in the undated batch, and takes a sale back out of it', function () {
        $rice = ($this->make)();
        ($this->deliver)($rice, 10, 1_000);

        $batch = ($this->batchesOf)($rice)[0];
        expect($batch->expiry_date)->toBeNull()
            ->and($batch->qty_milli)->toBe(10_000)
            ->and((bool) $batch->is_closed)->toBeFalse();

        ($this->sellAt)($rice, 4, 12_000);

        expect(($this->batchesOf)($rice))->toHaveCount(1)
            ->and(($this->batchesOf)($rice)[0]->qty_milli)->toBe(6_000);
        gatewayIntact('purchase then sale');
    });

    it('opens exactly one undated batch however many deliveries arrive', function () {
        $rice = ($this->make)();
        foreach ([1_000, 2_000, 1_500] as $price) {
            ($this->deliver)($rice, 5, $price);
        }

        expect(($this->batchesOf)($rice))->toHaveCount(1)
            ->and(($this->batchesOf)($rice)[0]->qty_milli)->toBe(15_000);
        gatewayIntact('three deliveries');
    });

    it('holds coverage through a void of a delivery that is partly sold', function () {
        $rice = ($this->make)();
        $first = ($this->deliver)($rice, 10, 1_000);
        ($this->deliver)($rice, 10, 2_000);
        ($this->sellAt)($rice, 5, 15_000);

        PurchaseService::voidPurchase($first['purchaseId'], 'Supplier invoiced us twice', $this->actor);

        expect(($this->batchesOf)($rice)[0]->qty_milli)->toBe(5_000);
        gatewayIntact('void of a partly-sold delivery');
    });

    it('holds coverage through a stock adjustment in both directions', function () {
        $rice = ($this->make)();
        Shop::openingStock($this->actor, $rice, 20_000, 20_000, GATEWAY_DAY);
        StockAdjustmentService::createStockAdjustment([
            'businessDate' => GATEWAY_DAY,
            'reason' => AdjustmentReason::Damaged,
            'items' => [['productId' => $rice, 'direction' => 'OUT', 'qty' => 3_000]],
        ], $this->actor);

        expect(($this->batchesOf)($rice)[0]->qty_milli)->toBe(17_000);
        gatewayIntact('adjustments both ways');
    });
});

describe('a batch that empties', function () {
    it('is closed, and reopens rather than being replaced when stock returns', function () {
        $rice = ($this->make)();
        ($this->moveIn)($rice, 5, 5_000);
        $opened = ($this->batchesOf)($rice)[0]->id;

        ($this->moveOut)($rice, 5);
        expect(($this->batchesOf)($rice)[0]->qty_milli)->toBe(0)
            ->and((bool) ($this->batchesOf)($rice)[0]->is_closed)->toBeTrue();

        ($this->moveIn)($rice, 2, 2_000);

        $after = ($this->batchesOf)($rice);
        expect($after)->toHaveCount(1)
            ->and($after[0]->id)->toBe($opened, 'the same batch, not a new one')
            ->and((bool) $after[0]->is_closed)->toBeFalse()
            ->and($after[0]->qty_milli)->toBe(2_000);
        gatewayIntact('emptied and refilled');
    });

    it('stays open while it is below zero, because that is a debt not an empty shelf', function () {
        $rice = ($this->make)();
        ($this->moveIn)($rice, 2, 2_000);
        ($this->moveOut)($rice, 5, ['allowNegative' => true]);

        $batch = ($this->batchesOf)($rice)[0];
        expect($batch->qty_milli)->toBe(-3_000)->and((bool) $batch->is_closed)->toBeFalse();
        gatewayIntact('oversold');
    });

    it('settles units sold before a dated delivery was entered, so the dated batch holds only what is left', function () {
        $rice = ($this->make)();
        ($this->moveOut)($rice, 5, ['allowNegative' => true]);

        $landed = ($this->moveIn)($rice, 10, 10_000, ['expiryDate' => '2027-01-31']);

        [$undated, $dated] = ($this->batchesOf)($rice);
        expect($landed)->toHaveCount(2)
            ->and($undated->qty_milli)->toBe(0)
            ->and($dated->expiry_date)->toBe('2027-01-31')
            ->and($dated->qty_milli)->toBe(5_000);
        gatewayIntact('dated delivery after an oversell');
    });

    it('opens no dated batch when the whole delivery was already sold', function () {
        $rice = ($this->make)();
        ($this->moveOut)($rice, 5, ['allowNegative' => true]);

        ($this->moveIn)($rice, 3, 3_000, ['expiryDate' => '2027-01-31']);

        $batches = ($this->batchesOf)($rice);
        expect($batches)->toHaveCount(1)->and($batches[0]->qty_milli)->toBe(-2_000);
        gatewayIntact('delivery smaller than the oversell');
    });

    it('names the same batch once, not twice, when an oversell drains it', function () {
        // The allocation drains the batch and then pushes the SAME one negative.
        $rice = ($this->make)();
        ($this->moveIn)($rice, 2, 2_000);

        $taken = ($this->moveOut)($rice, 5, ['allowNegative' => true]);
        expect($taken)->toHaveCount(1)->and($taken[0]['qtyMilli'])->toBe(5_000);
        gatewayIntact('oversell in one line');
    });
});

describe('first-expiry-first-out, reached through the gateway', function () {
    it('takes the tightest date first, then the next, then the undated', function () {
        $milk = ($this->make)('Evaporated Milk');
        ($this->moveIn)($milk, 5, 5_000); // undated
        ($this->moveIn)($milk, 5, 5_000, ['expiryDate' => '2026-12-31']);
        ($this->moveIn)($milk, 5, 5_000, ['expiryDate' => '2026-09-01']);

        $taken = ($this->moveOut)($milk, 12);
        $expiry = ($this->expiryOf)($milk);

        expect(array_map(fn (array $a): array => [$expiry[$a['batchId']], $a['qtyMilli']], $taken))->toBe([
            ['2026-09-01', 5_000],
            ['2026-12-31', 5_000],
            [null, 2_000],
        ]);
        gatewayIntact('fefo across three batches');
    });

    it('judges the date as at the shop day of the movement, not the wall clock', function () {
        // A sale entered late for a day when the goods were still good.
        $milk = ($this->make)('Evaporated Milk');
        ($this->moveIn)($milk, 5, 5_000, ['expiryDate' => GATEWAY_LATER]);
        ($this->moveIn)($milk, 5, 5_000);

        $taken = ($this->moveOut)($milk, 3, ['businessDate' => GATEWAY_DAY]);
        $dated = array_search(GATEWAY_LATER, ($this->expiryOf)($milk), true);
        expect(array_map(fn (array $a): array => [$a['batchId'], $a['qtyMilli']], $taken))->toBe([[$dated, 3_000]]);
    });

    it('walks past expired stock in silence while good stock covers the sale', function () {
        $milk = ($this->make)('Evaporated Milk');
        ($this->moveIn)($milk, 5, 5_000, ['expiryDate' => '2026-01-01']); // long gone
        ($this->moveIn)($milk, 5, 5_000, ['expiryDate' => '2026-12-31']);

        $taken = ($this->moveOut)($milk, 4);
        $good = array_search('2026-12-31', ($this->expiryOf)($milk), true);
        expect(array_map(fn (array $a): array => [$a['batchId'], $a['qtyMilli']], $taken))->toBe([[$good, 4_000]]);
        gatewayIntact('expired stock skipped');
    });

    it('refuses the movement when only expired stock is left', function () {
        $milk = ($this->make)('Evaporated Milk');
        ($this->moveIn)($milk, 5, 5_000, ['expiryDate' => '2026-01-01']);

        expect(fn () => ($this->moveOut)($milk, 2))->toThrow(ExpiredStockError::class);

        // And nothing moved: the throw happened inside the transaction.
        expect(($this->batchesOf)($milk)[0]->qty_milli)->toBe(5_000);
        gatewayIntact('refused movement');
    });

    it('takes expired stock when someone with the right says so, oldest first', function () {
        $milk = ($this->make)('Evaporated Milk');
        ($this->moveIn)($milk, 5, 5_000, ['expiryDate' => '2026-02-01']);
        ($this->moveIn)($milk, 5, 5_000, ['expiryDate' => '2026-01-01']);

        $taken = ($this->moveOut)($milk, 6, ['allowExpired' => true]);
        $expiry = ($this->expiryOf)($milk);

        expect(array_map(fn (array $a): array => [$expiry[$a['batchId']], $a['qtyMilli']], $taken))->toBe([
            ['2026-01-01', 5_000],
            ['2026-02-01', 1_000],
        ]);
        gatewayIntact('expired taken with approval');
    });

    it('lets a shop that does not want the block turn it off', function () {
        Shop::setting('expiry_blocks_sales', false);
        $milk = ($this->make)('Evaporated Milk');
        ($this->moveIn)($milk, 5, 5_000, ['expiryDate' => '2026-01-01']);

        $taken = ($this->moveOut)($milk, 2);
        expect(array_map(fn (array $a): array => [$a['batchId'], $a['qtyMilli']], $taken))
            ->toBe([[($this->batchesOf)($milk)[0]->id, 2_000]]);
        gatewayIntact('block turned off');
    });
});
