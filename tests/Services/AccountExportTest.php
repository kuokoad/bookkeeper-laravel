<?php

declare(strict_types=1);

/*
| Ported from the old tests/app/account-export.regression-1.test.ts (ISSUE-003:
| a filtered account CSV called its subtotal the closing balance), through
| App\Reports\Csv\ListExport on real postings rather than mocked services. The
| route, its permission check and throttle are Phase 8.
*/

use App\Reports\Csv\ListExport;
use App\Services\PaymentAccountService;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    Shop::income($this->actor, '2026-10-13', PaymentAccountService::listIncomeCategories()[0]['id'], 2_779_700, 'Income');
    $this->csv = fn (array $query): string => ListExport::download('account', ['id' => (string) $this->cash, 'period' => 'year', ...$query], '2026-10-14')['body'];
});

describe('account statement export', function () {
    it('labels narrowed totals as filtered, like the screen', function (array $query) {
        $text = ($this->csv)($query);
        expect($text)->toContain('Filtered closing total')
            ->and($text)->toContain('Filtered running total')
            ->and($text)->not->toMatch('/^Closing balance,/m');
    })->with([
        'flow=in' => [['flow' => 'in']],
        'type=SALE' => [['type' => 'SALE']],
        'q=receipt' => [['q' => 'receipt']],
        'min=10' => [['min' => '10']],
    ]);

    it('keeps real balance wording when only the dates are narrowed', function () {
        $text = ($this->csv)([]);
        expect($text)->toMatch('/^Closing balance,/m')
            ->and($text)->not->toContain('Filtered')
            ->and($text)->toContain('2026-10-13,INCOME,')
            ->and($text)->toContain('Closing balance,,,,,,27797.00');
    });

    it('is not a file at all without an account id', function () {
        expect(ListExport::download('account', ['period' => 'year'], '2026-10-14'))->toBeNull();
    });
});
