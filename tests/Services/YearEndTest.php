<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/year-end.test.ts: the year-end pack
| (reporting/year-end.service), its period, figures, the movement in the
| owner's stake, its self-checks, and the pack after a year-end close. The
| whole file is ported; nothing is deferred.
*/

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Enums\JournalSourceType;
use App\Services\JournalService;
use App\Services\Reporting\YearEndService;
use App\Services\SettingsService;
use App\Services\YearEndCloseService;
use App\Support\Clock;
use Tests\Support\Shop;

/** A balanced entry on a given day. */
function yePost(string $date, string $debitCode, string $creditCode, int $amount): void
{
    Tx::run(fn () => JournalService::post($date, JournalSourceType::OpeningBalance, [
        Journal::debit(Shop::accountId($debitCode), $amount),
        Journal::credit(Shop::accountId($creditCode), $amount),
    ], memo: 'test entry', isOpening: true));
}

function yeCapital(string $date, int $amount): void
{
    yePost($date, '1001', ChartOfAccounts::OWNERS_CAPITAL, $amount);
}

function yeSale(string $date, int $amount): void
{
    yePost($date, '1001', ChartOfAccounts::SALES_REVENUE, $amount);
}

/** 6010 is Rent, a postable leaf under the heading. */
function yeExpense(string $date, int $amount): void
{
    yePost($date, '6010', '1001', $amount);
}

function yeDrawing(string $date, int $amount): void
{
    yePost($date, ChartOfAccounts::OWNERS_DRAWINGS, '1001', $amount);
}

beforeEach(function () {
    $this->actor = Shop::owner();
});

describe('the period covered', function () {
    it('uses the calendar year when the year starts in January', function () {
        $pack = YearEndService::getYearEndPack(2025);
        expect($pack['year']->start)->toBe('2025-01-01')
            ->and($pack['year']->end)->toBe('2025-12-31')
            ->and($pack['previous']->label)->toBe('2024');
    });

    it('follows the shop\'s own financial year', function () {
        SettingsService::update(Shop::settingsInput(['financialYearStartMonth' => 4]), $this->actor);
        $pack = YearEndService::getYearEndPack(2025);
        expect($pack['year']->start)->toBe('2025-04-01')
            ->and($pack['year']->end)->toBe('2026-03-31')
            ->and($pack['year']->label)->toBe('2025/26');
    });

    it('offers only years the shop actually traded in', function () {
        yeSale('2024-06-01', 10_000);
        yeSale('2026-02-01', 10_000);

        expect(array_map(fn ($y) => $y->label, YearEndService::availableFinancialYears()))->toBe(['2026', '2025', '2024']);
    });

    it('marks a year that has not finished as provisional', function () {
        $future = (int) substr(Clock::today(), 0, 4) + 1;
        expect(YearEndService::getYearEndPack($future)['isProvisional'])->toBeTrue()
            ->and(YearEndService::getYearEndPack(2020)['isProvisional'])->toBeFalse();
    });
});

describe('the figures', function () {
    it('counts only what falls inside the year', function () {
        yeSale('2024-12-31', 50_000);
        yeSale('2025-01-01', 10_000);
        yeSale('2025-12-31', 20_000);
        yeSale('2026-01-01', 70_000);

        $pack = YearEndService::getYearEndPack(2025);
        expect($pack['profitAndLoss']['netSales'])->toBe(30_000)
            ->and($pack['entryCount'])->toBe(2);
    });

    it('gives the prior year as a comparative', function () {
        yeSale('2024-06-01', 40_000);
        yeSale('2025-06-01', 60_000);

        $pack = YearEndService::getYearEndPack(2025);
        expect($pack['profitAndLoss']['netSales'])->toBe(60_000)
            ->and($pack['previousProfitAndLoss']['netSales'])->toBe(40_000);
    });

    it('opens where the previous year closed, with no gap or overlap', function () {
        yeCapital('2024-03-01', 100_000);
        yeSale('2024-06-01', 40_000);

        $pack = YearEndService::getYearEndPack(2025);
        expect($pack['previousBalanceSheet']['asAt'])->toBe('2024-12-31')
            ->and($pack['balanceSheet']['asAt'])->toBe('2025-12-31')
            ->and($pack['equity']['openingEquity'])->toBe($pack['previousBalanceSheet']['totalEquity']);
    });
});

describe('the movement in the owner\'s stake', function () {
    it('ties opening, capital, drawings and profit back to closing', function () {
        yeCapital('2024-01-10', 100_000);
        yeSale('2024-05-01', 30_000);
        yeCapital('2025-02-01', 50_000);
        yeSale('2025-03-01', 80_000);
        yeExpense('2025-04-01', 20_000);
        yeDrawing('2025-05-01', 15_000);

        $equity = YearEndService::getYearEndPack(2025)['equity'];
        expect($equity['capitalIntroduced'])->toBe(50_000)
            ->and($equity['drawings'])->toBe(15_000)
            ->and($equity['profitForYear'])->toBe(60_000)
            ->and($equity['openingEquity'] + $equity['capitalIntroduced'] - $equity['drawings'] + $equity['profitForYear'])->toBe($equity['closingEquity'])
            ->and($equity['reconciles'])->toBeTrue()
            ->and($equity['difference'])->toBe(0);
    });

    it('reconciles even when nothing happened all year', function () {
        yeCapital('2024-01-10', 100_000);
        $equity = YearEndService::getYearEndPack(2025)['equity'];

        expect($equity['profitForYear'])->toBe(0)
            ->and($equity['capitalIntroduced'])->toBe(0)
            ->and($equity['closingEquity'])->toBe($equity['openingEquity'])
            ->and($equity['reconciles'])->toBeTrue();
    });

    it('accounts for opening balances brought in during the year', function () {
        yeCapital('2024-01-10', 100_000);
        yePost('2025-03-01', '1200', ChartOfAccounts::OPENING_BALANCE_EQUITY, 45_000);
        yeSale('2025-06-01', 20_000);

        $equity = YearEndService::getYearEndPack(2025)['equity'];
        expect($equity['openingBalancesRecognised'])->toBe(45_000)
            ->and($equity['openingEquity'] + $equity['capitalIntroduced'] - $equity['drawings'] + $equity['openingBalancesRecognised'] + $equity['profitForYear'])->toBe($equity['closingEquity'])
            ->and($equity['reconciles'])->toBeTrue();
    });

    it('reconciles when the year made a loss', function () {
        yeCapital('2024-01-10', 100_000);
        yeExpense('2025-06-01', 30_000);

        $equity = YearEndService::getYearEndPack(2025)['equity'];
        expect($equity['profitForYear'])->toBe(-30_000)
            ->and($equity['reconciles'])->toBeTrue()
            ->and($equity['closingEquity'])->toBe($equity['openingEquity'] - 30_000);
    });
});

describe('what the pack asserts about itself', function () {
    it('reports the books as balanced and intact', function () {
        yeSale('2025-06-01', 25_000);
        $pack = YearEndService::getYearEndPack(2025);

        expect($pack['integrity']['trialBalanced'])->toBeTrue()
            ->and($pack['balanceSheet']['balances'])->toBeTrue()
            ->and($pack['trialBalance']['balanced'])->toBeTrue();
    });

    it('says whether the year is closed to further entries', function () {
        yeSale('2025-06-01', 25_000);
        expect(YearEndService::getYearEndPack(2025)['isLocked'])->toBeFalse();
    });

    it('carries the shop details the accountant needs to identify it', function () {
        $pack = YearEndService::getYearEndPack(2025);
        expect($pack['shop']['name'])->not->toBeEmpty()
            ->and($pack['shop']['currencyCode'])->toBe('GHS');
    });
});

describe('the year-end pack after the year has been closed', function () {
    beforeEach(function () {
        yeCapital('2025-01-02', 200_000);
        yeSale('2025-03-01', 100_000);
        yeExpense('2025-04-01', 40_000);
        yeDrawing('2025-08-01', 25_000);
    });

    it('still shows what the owner took out', function () {
        expect(YearEndService::getYearEndPack(2025)['equity']['drawings'])->toBe(25_000);

        YearEndCloseService::closeFinancialYear(2025, $this->actor);

        expect(YearEndService::getYearEndPack(2025)['equity']['drawings'])->toBe(25_000);
    });

    it('still reconciles', function () {
        YearEndCloseService::closeFinancialYear(2025, $this->actor);

        $pack = YearEndService::getYearEndPack(2025);
        expect($pack['equity']['reconciles'])->toBeTrue()
            ->and($pack['equity']['difference'])->toBe(0);
    });
});
