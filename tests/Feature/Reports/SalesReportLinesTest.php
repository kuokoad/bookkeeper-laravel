<?php

declare(strict_types=1);

/*
| The sales report reads its lines once per page or export and shares them
| between the stock item, stock group, and (under a product or category
| filter) the day and customer tables. It used to read every line of the
| period up to four times per render.
*/

use App\Services\Reporting\OperationsService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    $actor = Shop::owner();
    $this->ownerId = $actor->id;
    $this->rice = Shop::product($actor, 'Rice 5kg', 1_000, 1_500);
    $milo = Shop::product($actor, 'Milo 400g', 3_200, 4_500);
    Shop::openingStock($actor, $this->rice, 10_000, 10_000);
    Shop::openingStock($actor, $milo, 10_000, 32_000);
    Shop::sell($actor, [[$this->rice, 2_000], [$milo, 1_000]], [['paymentAccountId' => Shop::paymentAccount('CASH'), 'amount' => 7_500]]);
    $this->lineReads = 0;
    DB::listen(function ($query): void {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from `sale_items` inner join `sales`')) {
            $this->lineReads++;
        }
    });
});

it('reads the sale lines once for a page render, with and without a product filter', function (string $query) {
    signedIn($this->ownerId)->get('/reports/sales?period=all'.$query)->assertOk()->assertSee('Rice 5kg');

    expect($this->lineReads)->toBe(1);
})->with(['no filter' => '', 'a product filter' => fn () => '&product='.$this->rice]);

it('reads the sale lines once for the CSV export', function () {
    signedIn($this->ownerId)->get('/export/report/sales?period=all&product='.$this->rice)->assertOk();

    expect($this->lineReads)->toBe(1);
});

it('shares the earnings of each receipt out over its lines, an invoice discount included', function () {
    // A second receipt: one rice at 15.00 and one Milo at 45.00, less 1.00 off the invoice.
    $actor = Shop::owner('ama');
    $milo = (int) DB::table('products')->where('name', 'Milo 400g')->value('id');
    Shop::sell($actor, [[$this->rice, 1_000], [$milo, 1_000]], [['paymentAccountId' => Shop::paymentAccount('CASH'), 'amount' => 5_900]], ['invoiceDiscount' => 100, 'allowPriceOverride' => true]);
    $query = ['from' => '2026-08-01', 'to' => '2026-08-31'];

    $lines = OperationsService::earnedLines($query);

    // 59.00 net split 15 : 45, so 14.75 and 44.25; the first receipt keeps 30.00 and 45.00.
    expect(array_map(fn (array $l): array => [$l['productName'], $l['earned']], $lines))
        ->toBe([['Rice 5kg', 3_000], ['Milo 400g', 4_500], ['Rice 5kg', 1_475], ['Milo 400g', 4_425]])
        ->and(array_column(OperationsService::getSalesByProduct($query, $lines), 'revenue', 'productName'))->toBe(['Milo 400g' => 8_925, 'Rice 5kg' => 4_475]);
});
