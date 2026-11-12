<?php

declare(strict_types=1);

/*
 * Owner decision (fourth audit): no future-dated money. A stock adjustment
 * moves stock value in the books, so it is refused a future date like an
 * expense is, both when recorded and when edited.
 */

use App\Domain\Errors\ValidationError;
use App\Enums\AdjustmentReason;
use App\Services\StockAdjustmentService;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->product = Shop::product($this->actor, 'Milo 400g');
    Shop::openingStock($this->actor, $this->product, 10_000, 5_000, '2026-08-16');
    $this->now = Clock::fromStamp('2026-08-20 09:00:00');
    $this->input = fn (string $date): array => ['businessDate' => $date, 'reason' => AdjustmentReason::Damaged, 'items' => [['productId' => $this->product, 'direction' => 'OUT', 'qty' => 1_000]], 'occurredAt' => $this->now];
});

it('refuses a stock adjustment dated after today', function () {
    $before = DB::table('stock_adjustments')->count();

    expect(fn () => StockAdjustmentService::createStockAdjustment(($this->input)('2026-08-21'), $this->actor))
        ->toThrow(ValidationError::class, 'A stock adjustment cannot be dated in the future.');
    expect(DB::table('stock_adjustments')->count())->toBe($before);
});

it('still records a stock adjustment dated today', function () {
    $result = StockAdjustmentService::createStockAdjustment(($this->input)('2026-08-20'), $this->actor);

    expect(DB::table('stock_adjustments')->where('id', $result['adjustmentId'])->value('business_date'))->toBe('2026-08-20');
});

it('refuses an edit that moves a stock adjustment into the future', function () {
    $damage = StockAdjustmentService::createStockAdjustment(($this->input)('2026-08-18'), $this->actor);

    expect(fn () => StockAdjustmentService::editStockAdjustment($damage['adjustmentId'], ($this->input)('2026-08-21'), 'Wrong day', $this->actor, $this->now))
        ->toThrow(ValidationError::class, 'A stock adjustment cannot be dated in the future.');
    expect(DB::table('stock_adjustments')->where('id', $damage['adjustmentId'])->value('business_date'))->toBe('2026-08-18');
});
