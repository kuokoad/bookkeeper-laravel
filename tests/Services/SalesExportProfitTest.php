<?php

declare(strict_types=1);

/*
| Ported from the old tests/app/sales-export-profit.test.ts: the downloaded
| sales file says what the screen says, tax is not profit. Through
| App\Reports\Csv\ListExport; the route itself is Phase 8.
*/

use App\Reports\Csv\ListExport;
use Tests\Support\Shop;

describe('sales CSV', function () {
    it('reports profit with the tax taken out', function () {
        $actor = Shop::owner();
        Shop::setSingleTax(2_000, true);
        $product = Shop::product($actor, 'Rice 5kg', 8_000, 12_000, ['unit' => 'bag']);
        Shop::openingStock($actor, $product, 5_000, 40_000, '2026-08-01');
        Shop::sell($actor, [[$product, 1_000]], [['paymentAccountId' => Shop::paymentAccount('CASH'), 'amount' => 12_000]], ['businessDate' => '2026-08-10']);

        $body = ListExport::download('sales', ['period' => 'all'], '2026-08-17')['body'];
        [$header, $row] = preg_split('/\r?\n/', preg_replace('/^\x{FEFF}/u', '', $body));
        $profitColumn = array_search('Profit', explode(',', $header), true);

        expect($profitColumn)->not->toBeFalse()
            ->and(explode(',', $row)[$profitColumn])->toBe('20.00');
    });
});
