<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/year-end-check-line.test.ts: the pack's
| trial balance totals NET balances as at the year end, the integrity check
| totals every line ever posted, and the two must not be confused. The whole
| file is ported.
*/

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Enums\JournalSourceType;
use App\Services\JournalService;
use App\Services\Reporting\YearEndService;
use Tests\Support\Shop;

beforeEach(function () {
    $post = fn (string $date, string $debitCode, string $creditCode, int $amount) => Tx::run(fn () => JournalService::post($date, JournalSourceType::OpeningBalance, [
        Journal::debit(Shop::accountId($debitCode), $amount),
        Journal::credit(Shop::accountId($creditCode), $amount),
    ], memo: 'test entry', isOpening: true));
    $post('2026-03-04', '1001', ChartOfAccounts::OWNERS_CAPITAL, 70_000_00);
    $post('2026-05-19', ChartOfAccounts::OWNERS_CAPITAL, '1001', 12_000_00);
});

describe('the year-end pack trial balance', function () {
    it('balances on both measures', function () {
        $pack = YearEndService::getYearEndPack(2026);

        expect($pack['trialBalance']['balanced'])->toBeTrue()
            ->and($pack['trialBalance']['totalDebit'])->toBe($pack['trialBalance']['totalCredit'])
            ->and($pack['integrity']['trialBalanced'])->toBeTrue()
            ->and($pack['integrity']['totalDebit'])->toBe($pack['integrity']['totalCredit']);
    });

    it('nets to a different figure from the whole-ledger gross total', function () {
        $pack = YearEndService::getYearEndPack(2026);

        expect($pack['trialBalance']['totalDebit'])->toBe(58_000_00)
            ->and($pack['integrity']['totalDebit'])->toBe(82_000_00)
            ->and($pack['trialBalance']['totalDebit'])->not->toBe($pack['integrity']['totalDebit']);
    });
});
