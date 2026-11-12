<?php

declare(strict_types=1);

/*
 * Owner decision (fourth audit, extended 2026-11-12): no future-dated money.
 * A purchase puts stock on the shelf and a debt to the supplier in the books,
 * so it is refused a future date like a supplier payment is, both when
 * recorded and when edited.
 */

use App\Domain\Errors\ValidationError;
use App\Services\PurchaseService;
use App\Services\SupplierService;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->product = Shop::product($this->actor, 'Milo 400g');
    $this->supplier = Shop::supplier($this->actor);
    $this->now = Clock::fromStamp('2026-08-20 09:00:00');
    // 10 tins at GHS 5.00 on credit.
    $this->input = fn (string $date): array => ['supplierId' => $this->supplier, 'businessDate' => $date,
        'items' => [['productId' => $this->product, 'qty' => 10_000, 'unitCost' => 500]], 'tenders' => [], 'occurredAt' => $this->now];
});

it('refuses a purchase dated after today', function () {
    expect(fn () => PurchaseService::createPurchase(($this->input)('2026-08-21'), $this->actor))
        ->toThrow(ValidationError::class, 'A purchase cannot be dated in the future.');
    expect(DB::table('purchases')->count())->toBe(0)
        ->and(SupplierService::getSupplierBalance($this->supplier))->toBe(0);
});

it('still records a purchase dated today', function () {
    $purchase = PurchaseService::createPurchase(($this->input)('2026-08-20'), $this->actor);

    expect(DB::table('purchases')->where('id', $purchase['purchaseId'])->value('business_date'))->toBe('2026-08-20')
        ->and(SupplierService::getSupplierBalance($this->supplier))->toBe(5_000);
});

it('refuses an edit that moves a purchase into the future', function () {
    $purchase = PurchaseService::createPurchase(($this->input)('2026-08-18'), $this->actor);

    expect(fn () => PurchaseService::editPurchase($purchase['purchaseId'], ($this->input)('2026-08-21'), 'Wrong day', $this->actor, $this->now))
        ->toThrow(ValidationError::class, 'A purchase cannot be dated in the future.');
    expect(DB::table('purchases')->where('id', $purchase['purchaseId'])->value('business_date'))->toBe('2026-08-18')
        ->and(SupplierService::getSupplierBalance($this->supplier))->toBe(5_000);
});
