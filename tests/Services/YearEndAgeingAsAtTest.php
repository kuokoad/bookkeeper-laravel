<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/year-end-ageing-as-at.test.ts: a
| provisional year-end pack ages debts to the day it is prepared, not to a
| year end still months away. The old test relied on the real clock being
| inside 2026; here "today" is pinned to 1 September 2026, the day the old
| comment describes. The whole file is ported.
*/

use App\Services\Reporting\LedgerService;
use App\Services\Reporting\YearEndService;
use Illuminate\Support\Carbon;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    Carbon::setTestNow('2026-09-01 10:00:00');
    // An unpaid credit sale, so there is a debt to age: 10 bags at 96.00.
    $this->creditSale = function (string $date): void {
        $product = Shop::product($this->actor, 'Cement 50kg', 8_000, 9_600, ['unit' => 'bag']);
        Shop::openingStock($this->actor, $product, 100_000, 800_000, $date);
        Shop::sell($this->actor, [[$product, 10_000]], [], ['businessDate' => $date, 'customerId' => Shop::customer($this->actor, 'Adom Construction Ltd')]);
    };
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('the year-end pack ages debts from the day it is prepared', function () {
    it('does not age a provisional pack to a date in the future', function () {
        ($this->creditSale)('2026-08-20');
        $pack = YearEndService::getYearEndPack(2026);

        expect($pack['isProvisional'])->toBeTrue()
            ->and($pack['ageingAsAt'] < $pack['year']->end)->toBeTrue();
    });

    it('does not report a recent debt as more than 90 days overdue', function () {
        ($this->creditSale)('2026-08-20');
        $pack = YearEndService::getYearEndPack(2026);

        expect($pack['receivables'][0]['total'])->toBe(96_000)
            ->and($pack['receivables'][0]['over90'])->toBe(0)
            ->and(LedgerService::getReceivablesAgeing($pack['year']->end)[0]['over90'])->toBe(96_000);
    });

    it('agrees with the Who owes you screen, which ages to the same day', function () {
        ($this->creditSale)('2026-08-20');
        $pack = YearEndService::getYearEndPack(2026);

        expect($pack['receivables'])->toBe(LedgerService::getReceivablesAgeing($pack['ageingAsAt']));
    });

    it('ages a finished year to the year end, not to today', function () {
        ($this->creditSale)('2020-08-20');
        $pack = YearEndService::getYearEndPack(2020);

        expect($pack['isProvisional'])->toBeFalse()
            ->and($pack['ageingAsAt'])->toBe($pack['year']->end);
    });
});
