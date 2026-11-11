<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/year-end-close.test.ts: closing and
| reopening a financial year (YearEndCloseService), with every refusal, the
| books lock, discounts and returns, and the integrity check afterwards.
| "Today" is 15 June 2027, as the old test set the system clock. The whole
| file is ported; nothing is deferred.
*/

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Domain\Errors\ValidationError;
use App\Enums\JournalSourceType;
use App\Services\JournalService;
use App\Services\PeriodLockService;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\FinancialService;
use App\Services\Reporting\LedgerService;
use App\Services\YearEndCloseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

function ycPost(string $date, string $debitCode, string $creditCode, int $amount): void
{
    Tx::run(fn () => JournalService::post($date, JournalSourceType::OpeningBalance, [
        Journal::debit(Shop::accountId($debitCode), $amount),
        Journal::credit(Shop::accountId($creditCode), $amount),
    ], memo: 'test entry', isOpening: true));
}

function ycSale(string $date, int $amount): void
{
    ycPost($date, '1001', ChartOfAccounts::SALES_REVENUE, $amount);
}

function ycExpense(string $date, int $amount): void
{
    ycPost($date, '6010', '1001', $amount);
}

/** A year that traded: 100,000 in, 40,000 out, 60,000 profit. */
function ycTradeIn(int $year): void
{
    ycSale("{$year}-03-01", 100_000);
    ycExpense("{$year}-04-01", 40_000);
}

function ycLockDate(): ?string
{
    $value = DB::table('business_settings')->where('id', 1)->value('books_locked_before');

    return $value === null ? null : (string) $value;
}

function ycYear(int $year): array
{
    return ['from' => "{$year}-01-01", 'to' => "{$year}-12-31"];
}

beforeEach(function () {
    $this->actor = Shop::owner();
    // Every test closes a year in the past; "today" must be after it.
    Carbon::setTestNow('2027-06-15 10:00:00');
    $this->close = fn (int $year): array => YearEndCloseService::closeFinancialYear($year, $this->actor);
    $this->reopen = fn (int $year) => YearEndCloseService::reopenFinancialYear($year, $this->actor);
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('closing a year', function () {
    it('posts one balanced entry and reports the profit', function () {
        ycTradeIn(2025);
        expect(($this->close)(2025)['profit'])->toBe(60_000)
            ->and(LedgerService::checkBooksIntegrity()['trialBalanced'])->toBeTrue();
    });

    it('leaves the year\'s Profit & Loss unchanged', function () {
        ycTradeIn(2025);
        $before = FinancialService::getProfitAndLoss(ycYear(2025));
        ($this->close)(2025);
        $after = FinancialService::getProfitAndLoss(ycYear(2025));

        expect($after['netProfit'])->toBe($before['netProfit'])
            ->and($after['netProfit'])->toBe(60_000)
            ->and($after['netSales'])->toBe(100_000);
    });

    it('carries the profit into retained earnings without changing total equity', function () {
        ycPost('2025-01-02', '1001', ChartOfAccounts::OWNERS_CAPITAL, 200_000);
        ycTradeIn(2025);

        $before = FinancialService::getBalanceSheet('2025-12-31');
        ($this->close)(2025);
        $after = FinancialService::getBalanceSheet('2025-12-31');

        expect($after['totalEquity'])->toBe($before['totalEquity'])
            ->and($after['totalAssets'])->toBe($before['totalAssets'])
            ->and($after['balances'])->toBeTrue()
            ->and($after['retainedEarnings'])->toBe(60_000);
    });

    it('zeroes the trading accounts, which is what fixes the trial balance', function () {
        ycTradeIn(2025);
        ($this->close)(2025);

        $trial = BalancesService::getTrialBalance(['to' => '2025-12-31']);
        $codes = array_column($trial['lines'], 'code');
        expect($codes)->not->toContain(ChartOfAccounts::SALES_REVENUE)
            ->and($codes)->not->toContain('6010')
            ->and($trial['balanced'])->toBeTrue();
    });

    it('closes drawings too, so the next year starts clean', function () {
        ycPost('2025-01-02', '1001', ChartOfAccounts::OWNERS_CAPITAL, 200_000);
        ycTradeIn(2025);
        ycPost('2025-08-01', ChartOfAccounts::OWNERS_DRAWINGS, '1001', 25_000);

        expect(($this->close)(2025)['drawings'])->toBe(25_000);

        $after = FinancialService::getBalanceSheet('2025-12-31');
        expect($after['drawings'])->toBe(0)
            ->and($after['retainedEarnings'])->toBe(35_000)
            ->and($after['balances'])->toBeTrue();
    });

    it('locks the books to the year end', function () {
        ycTradeIn(2025);
        ($this->close)(2025);
        expect(ycLockDate())->toBe('2025-12-31');
    });

    it('refuses a new transaction dated inside the closed year', function () {
        ycTradeIn(2025);
        ($this->close)(2025);
        expect(fn () => ycSale('2025-07-01', 5_000))->toThrow(Exception::class);
    });
});

describe('refusing to close when it would mislead', function () {
    it('refuses a year that has not finished', function () {
        Carbon::setTestNow('2026-06-15 10:00:00');
        ycTradeIn(2026);
        expect(fn () => ($this->close)(2026))->toThrow(Exception::class, 'has not finished');
    });

    it('refuses to close the same year twice', function () {
        ycTradeIn(2025);
        ($this->close)(2025);
        expect(fn () => ($this->close)(2025))->toThrow(Exception::class, 'already closed');
    });

    it('refuses to close out of order', function () {
        ycTradeIn(2024);
        ycTradeIn(2025);
        expect(fn () => ($this->close)(2025))->toThrow(Exception::class, 'Close 2024 first');
    });

    it('refuses a year with nothing in it', function () {
        ycTradeIn(2024);
        ($this->close)(2024);
        expect(fn () => ($this->close)(2025))->toThrow(Exception::class, 'nothing to close');
    });

    it('leaves nothing behind when it refuses', function () {
        ycTradeIn(2024);
        ycTradeIn(2025);
        expect(fn () => ($this->close)(2025))->toThrow(Exception::class);

        expect(YearEndCloseService::isYearClosed(2025))->toBeFalse()
            ->and(ycLockDate())->toBeNull()
            ->and(LedgerService::checkBooksIntegrity()['trialBalanced'])->toBeTrue();
    });
});

describe('closing several years in a row', function () {
    it('keeps each year\'s profit separate and the books balanced', function () {
        ycTradeIn(2024);
        ycSale('2025-03-01', 50_000);
        ycExpense('2025-04-01', 20_000);
        ($this->close)(2024);
        ($this->close)(2025);

        $sheet = FinancialService::getBalanceSheet('2025-12-31');
        expect(FinancialService::getProfitAndLoss(ycYear(2024))['netProfit'])->toBe(60_000)
            ->and(FinancialService::getProfitAndLoss(ycYear(2025))['netProfit'])->toBe(30_000)
            ->and($sheet['retainedEarnings'])->toBe(90_000)
            ->and($sheet['balances'])->toBeTrue()
            ->and(LedgerService::checkBooksIntegrity()['trialBalanced'])->toBeTrue();
    });
});

describe('reopening a year', function () {
    it('reverses the close rather than deleting it', function () {
        ycTradeIn(2025);
        ($this->close)(2025);
        ($this->reopen)(2025);

        $closings = DB::table('year_end_closings')->get();
        expect(YearEndCloseService::isYearClosed(2025))->toBeFalse()
            ->and($closings)->toHaveCount(1)
            ->and($closings[0]->reversed_at)->not->toBeNull()
            ->and(LedgerService::checkBooksIntegrity()['trialBalanced'])->toBeTrue();
    });

    it('puts the balance sheet back exactly where it was', function () {
        ycPost('2025-01-02', '1001', ChartOfAccounts::OWNERS_CAPITAL, 200_000);
        ycTradeIn(2025);

        $before = FinancialService::getBalanceSheet('2025-12-31');
        ($this->close)(2025);
        ($this->reopen)(2025);
        $after = FinancialService::getBalanceSheet('2025-12-31');

        expect($after['totalEquity'])->toBe($before['totalEquity'])
            ->and($after['retainedEarnings'])->toBe($before['retainedEarnings'])
            ->and($after['balances'])->toBeTrue();
    });

    it('does not double-count the profit in the Profit & Loss', function () {
        ycTradeIn(2025);
        ($this->close)(2025);
        ($this->reopen)(2025);

        expect(FinancialService::getProfitAndLoss(ycYear(2025))['netProfit'])->toBe(60_000);
    });

    it('moves the books lock back', function () {
        ycTradeIn(2024);
        ycTradeIn(2025);
        ($this->close)(2024);
        ($this->close)(2025);
        expect(ycLockDate())->toBe('2025-12-31');

        ($this->reopen)(2025);
        expect(ycLockDate())->toBe('2024-12-31');

        ($this->reopen)(2024);
        expect(ycLockDate())->toBeNull();
    });

    it('refuses to reopen underneath a later closed year', function () {
        ycTradeIn(2024);
        ycTradeIn(2025);
        ($this->close)(2024);
        ($this->close)(2025);

        expect(fn () => ($this->reopen)(2024))->toThrow(Exception::class, 'Reopen 2025 first');
    });

    it('refuses to reopen a year that is not closed', function () {
        expect(fn () => ($this->reopen)(2025))->toThrow(Exception::class, 'not closed');
    });

    it('allows the year to be closed again afterwards', function () {
        ycTradeIn(2025);
        ($this->close)(2025);
        ($this->reopen)(2025);
        ycSale('2025-09-01', 10_000);

        expect(($this->close)(2025)['profit'])->toBe(70_000)
            ->and(FinancialService::getBalanceSheet('2025-12-31')['balances'])->toBeTrue()
            ->and(DB::table('year_end_closings')->count())->toBe(2);
    });
});

describe('discounts and returns at year end', function () {
    it('takes discounts OFF the year rather than adding them on', function () {
        ycSale('2025-03-01', 100_000);
        ycPost('2025-03-01', ChartOfAccounts::SALES_DISCOUNTS, '1001', 10_000);
        ycExpense('2025-04-01', 40_000);

        expect(($this->close)(2025)['profit'])->toBe(50_000);
    });

    it('leaves the discounts account at zero afterwards', function () {
        ycSale('2025-03-01', 100_000);
        ycPost('2025-03-01', ChartOfAccounts::SALES_DISCOUNTS, '1001', 10_000);
        ($this->close)(2025);

        $discounts = collect(BalancesService::getTrialBalance()['lines'])->firstWhere('code', ChartOfAccounts::SALES_DISCOUNTS);
        expect(($discounts['debit'] ?? 0) - ($discounts['credit'] ?? 0))->toBe(0);
    });

    it('still balances, and the books stay sound', function () {
        ycSale('2025-03-01', 100_000);
        ycPost('2025-03-01', ChartOfAccounts::SALES_DISCOUNTS, '1001', 10_000);
        ycPost('2025-03-15', ChartOfAccounts::SALES_RETURNS, '1001', 4_000);
        ycExpense('2025-04-01', 40_000);
        ($this->close)(2025);

        expect(LedgerService::checkBooksIntegrity()['trialBalanced'])->toBeTrue();
    });
});

describe('the integrity check after a close', function () {
    it('reports nothing untraced after closing a year', function () {
        ycTradeIn(2025);
        ($this->close)(2025);
        expect(LedgerService::checkBooksIntegrity()['untracedEntries'])->toBe(0);
    });

    it('reports nothing untraced after reopening one either', function () {
        ycTradeIn(2025);
        ($this->close)(2025);
        ($this->reopen)(2025);
        expect(LedgerService::checkBooksIntegrity()['untracedEntries'])->toBe(0);
    });
});

describe('the books lock and the year-end close (third audit 4 and 5)', function () {
    it('keeps a later lock the owner set when a year is closed and reopened', function () {
        ycTradeIn(2025);
        Shop::setting('books_locked_before', '2027-03-31');

        ($this->close)(2025);
        expect(ycLockDate())->toBe('2027-03-31');
        ($this->reopen)(2025);
        expect(ycLockDate())->toBe('2027-03-31');
    });

    it('refuses to move the lock before a closed year ends, or to remove it', function () {
        ycTradeIn(2025);
        ($this->close)(2025);

        expect(fn () => PeriodLockService::setLock('2025-06-30', $this->actor))->toThrow(ValidationError::class, 'is closed')
            ->and(fn () => PeriodLockService::setLock(null, $this->actor))->toThrow(ValidationError::class, 'is closed')
            ->and(ycLockDate())->toBe('2025-12-31');
        PeriodLockService::setLock('2026-01-31', $this->actor);
        expect(ycLockDate())->toBe('2026-01-31');
    });

    it('closes the first trading year when the year before holds only the opening capital', function () {
        ycPost('2024-12-20', '1001', ChartOfAccounts::OWNERS_CAPITAL, 200_000);
        ycTradeIn(2025);

        expect(($this->close)(2025)['profit'])->toBe(60_000)
            ->and(YearEndCloseService::isYearClosed(2025))->toBeTrue();
    });
});
