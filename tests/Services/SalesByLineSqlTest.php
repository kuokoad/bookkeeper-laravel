<?php

declare(strict_types=1);

/*
| Sales by product and by category are summed in SQL for receipts without an
| invoice discount and shared out line by line only for the rest. They must
| match, to the pesewa and in order, what tallying every earned line in PHP
| gives (the old path, still used under a product or category filter): a
| seeded run of many receipts with invoice discounts, returns and voids.
*/

use App\Services\Reporting\OperationsService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

it('gives the same stock item and stock group tables as tallying every earned line', function (string $tax) {
    mt_srand(4242);
    match ($tax) {
        'exclusive' => Shop::setGhanaTaxes(),
        'inclusive' => Shop::setGhanaTaxes(inclusive: true),
        default => Shop::setNoTax(),
    };
    $actor = Shop::owner();
    $cash = Shop::paymentAccount('CASH');
    $momo = Shop::paymentAccount('MOBILE_MONEY');
    $customer = Shop::customer($actor, 'Mensah Provisions');
    DB::table('categories')->insert([['name' => 'Grains', 'created_at' => now(), 'updated_at' => now()], ['name' => 'Drinks', 'created_at' => now(), 'updated_at' => now()]]);
    $categories = DB::table('categories')->pluck('id')->all();
    $products = [];
    foreach (['Rice', 'Milo', 'Oil', 'Sugar', 'Salt', 'Soap'] as $i => $name) {
        $products[] = $id = Shop::product($actor, $name, 300 + $i * 170, 700 + $i * 333, ['unit' => 'kg']);
        Shop::openingStock($actor, $id, 2_000_000, 600_000 + $i * 340_000, '2026-08-01');
        if ($i < 4) {
            DB::table('products')->where('id', $id)->update(['category_id' => $categories[$i % 2]]);
        }
    }

    $sales = [];
    for ($n = 0; $n < 150; $n++) {
        $lines = [];
        foreach (array_slice($products, mt_rand(0, 2), mt_rand(1, 4)) as $p) {
            $lines[] = ['productId' => $p, 'qty' => mt_rand(1, 7) * 500];
        }
        $discount = mt_rand(0, 2) === 0 ? mt_rand(1, 333) : 0;
        // The first 12, on credit, are returned or voided below.
        $sale = Shop::sell($actor, $lines, $n < 12 ? [] : [['paymentAccountId' => $n % 3 === 0 ? $momo : $cash, 'amount' => 10_000_000]], [
            'businessDate' => '2026-08-'.sprintf('%02d', mt_rand(2, 28)),
            'customerId' => $n < 12 || mt_rand(0, 1) === 0 ? $customer : null,
            ...($discount > 0 ? ['invoiceDiscount' => $discount, 'allowPriceOverride' => true] : []),
        ]);
        $sales[] = $sale['saleId'];
    }
    foreach (array_slice($sales, 0, 12) as $i => $saleId) {
        $i % 3 === 0
            ? SaleService::voidSale($saleId, 'Rung up twice', $actor)
            : ReturnsService::createCustomerReturn($saleId, ['businessDate' => '2026-08-29', 'items' => [['itemId' => SaleService::getSale($saleId)['items'][0]['id'], 'qty' => 500]], 'refunds' => []], $actor);
    }
    expect(DB::table('sales')->where('discount_minor', '>', 0)->count())->toBeGreaterThan(20);

    $queries = [
        ['from' => '2026-08-01', 'to' => '2026-08-31'],
        ['from' => '2026-08-10', 'to' => '2026-08-20'],
        ['from' => '2026-08-01', 'to' => '2026-08-31', 'customerId' => $customer],
        ['from' => '2026-08-01', 'to' => '2026-08-31', 'paymentAccountId' => $momo],
        ['from' => '2026-08-01', 'to' => '2026-08-31', 'productId' => $products[1]],
        ['from' => '2026-08-01', 'to' => '2026-08-31', 'categoryId' => (int) $categories[0]],
        // Over a quarter: the other join plan.
        ['from' => '2026-06-01', 'to' => '2026-09-30'],
        ['from' => '2026-06-01', 'to' => '2026-09-30', 'categoryId' => (int) $categories[1]],
        // Across a year end: read a calendar year at a time.
        ['from' => '2025-12-01', 'to' => '2026-08-20'],
    ];
    foreach ($queries as $query) {
        $lines = OperationsService::earnedLines($query);
        expect(OperationsService::getSalesByProduct($query))->toBe(OperationsService::getSalesByProduct($query, $lines), json_encode($query))
            ->and(OperationsService::getSalesByCategory($query))->toBe(OperationsService::getSalesByCategory($query, $lines), json_encode($query));
    }
})->with(['no tax' => 'none', 'tax on top of the price' => 'exclusive', 'tax inside the price' => 'inclusive']);
