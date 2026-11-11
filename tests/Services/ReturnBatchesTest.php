<?php

declare(strict_types=1);

/*
| Third audit finding 11: each partial return re-split the whole line across
| its batches, ignoring what earlier returns had already put back, so three
| one-unit returns of a three-unit sale left one batch holding more than it
| ever gave. Each batch must end up holding exactly what it gave the sale.
*/

use App\Services\ReturnsService;
use App\Services\SaleService;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const RB_DAY = '2026-08-10';

it('puts every unit of a sale returned one at a time back in the batch it came from', function () {
    Shop::setNoTax();
    $actor = Shop::owner();
    $cash = Shop::paymentAccount('CASH');
    $milk = Shop::product($actor, 'Fresh milk 1L', 500, 800);
    $deliver = fn (int $units, string $expiry): int => Shop::move([
        'productId' => $milk, 'direction' => 'IN', 'qty' => $units * 1000, 'totalCost' => $units * 500,
        'movementType' => 'PURCHASE', 'sourceType' => 'TEST', 'businessDate' => RB_DAY,
        'occurredAt' => Clock::fromStamp(RB_DAY.' 08:00:00'), 'batch' => ['kind' => 'NEW', 'expiryDate' => $expiry],
    ])['batchAllocations'][0]['batchId'];
    $soon = $deliver(2, '2026-12-01');
    $later = $deliver(1, '2027-01-01');

    $sale = SaleService::createSale(['businessDate' => RB_DAY, 'items' => [['productId' => $milk, 'qty' => 3_000]], 'tenders' => [['paymentAccountId' => $cash, 'amount' => 2_400]]], $actor);
    $itemId = (int) DB::table('sale_items')->where('sale_id', $sale['saleId'])->value('id');
    foreach ([1, 2, 3] as $n) {
        ReturnsService::createCustomerReturn($sale['saleId'], ['businessDate' => RB_DAY, 'items' => [['itemId' => $itemId, 'qty' => 1_000]],
            'refunds' => [['paymentAccountId' => $cash, 'amount' => 800]], 'reason' => "Return {$n}"], $actor);
    }

    $held = DB::table('product_batches')->where('product_id', $milk)->pluck('qty_milli', 'id')->map(fn ($q): int => (int) $q)->all();
    expect($held)->toBe([$soon => 2_000, $later => 1_000]);
});
