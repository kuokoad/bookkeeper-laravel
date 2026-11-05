<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/stock-ledger-dates.test.ts.
|
| Every row carries two dates: business_date (the day the stock moved) and
| occurred_at (when the row was written). The list filters on the first, so it
| must show and sort by the first. These write movements whose two dates differ
| on purpose, the only condition under which the old bug was visible.
*/

use App\Enums\AdjustmentReason;
use App\Services\InventoryService;
use App\Services\StockAdjustmentService;
use App\Support\Clock;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->make = fn (string $name, string $unit): int => Shop::product($this->actor, $name, 100, 150, ['unit' => $unit]);
    // Written at $writtenAt, but traded on $businessDate.
    $this->movement = fn (int $id, string $businessDate, string $writtenAt, int $units) => StockAdjustmentService::createStockAdjustment([
        'businessDate' => $businessDate,
        'occurredAt' => Clock::fromStamp($writtenAt),
        'reason' => AdjustmentReason::OpeningStock,
        'items' => [['productId' => $id, 'direction' => 'IN', 'qty' => $units * 1000, 'totalCost' => $units * 100]],
    ], $this->actor);
});

describe('the date on a stock movement', function () {
    it('is the day the stock moved, not the day the row was written', function () {
        $product = ($this->make)('Cement 50kg', 'bag');

        // Keyed in on 31 August; the cement actually arrived on 18 June.
        ($this->movement)($product, '2026-06-18', '2026-08-31 13:19:00', 600);

        $row = InventoryService::getStockLedger()[0];
        expect($row['businessDate'])->toBe('2026-06-18')
            // occurred_at is still the truth about when it was typed.
            ->and($row['occurredAt']->equalTo(Clock::fromStamp('2026-08-31 13:19:00')))->toBeTrue();
    });

    it('never returns a row dated outside the range that selected it', function () {
        $product = ($this->make)('Iron rod 12mm', 'length');
        ($this->movement)($product, '2026-06-18', '2026-08-31 13:19:00', 100);
        ($this->movement)($product, '2026-07-16', '2026-08-31 13:19:00', 200);
        ($this->movement)($product, '2026-08-22', '2026-08-31 13:19:00', 300);

        $rows = InventoryService::getStockLedger(['from' => '2026-06-01', 'to' => '2026-07-31']);

        expect($rows)->toHaveCount(2);
        foreach ($rows as $row) {
            expect($row['businessDate'] >= '2026-06-01')->toBeTrue()
                ->and($row['businessDate'] <= '2026-07-31')->toBeTrue();
        }
    });

    it('lists the newest trading day first, wherever the row was written', function () {
        $product = ($this->make)('PVC pipe 4in', 'length');

        // The 1 August movement is keyed in last, the way a converted quote is.
        ($this->movement)($product, '2026-08-30', '2026-08-31 13:19:01', 10);
        ($this->movement)($product, '2026-07-21', '2026-08-31 13:19:02', 20);
        ($this->movement)($product, '2026-08-01', '2026-08-31 13:19:03', 30);

        expect(array_column(InventoryService::getStockLedger(), 'businessDate'))->toBe(['2026-08-30', '2026-08-01', '2026-07-21']);
    });

    it('keeps same-day movements in the order the cost chain was built', function () {
        $product = ($this->make)('Binding wire', 'roll');

        // These three share an occurred_at to the millisecond, so only the id separates them.
        ($this->movement)($product, '2026-08-12', '2026-08-31 13:19:00', 10);
        ($this->movement)($product, '2026-08-12', '2026-08-31 13:19:00', 20);
        ($this->movement)($product, '2026-08-12', '2026-08-31 13:19:00', 30);

        // Newest first, so the balances count down the chain.
        expect(array_column(InventoryService::getStockLedger(), 'balanceQty'))->toBe([60_000, 30_000, 10_000]);
    });
});
