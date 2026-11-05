<?php

declare(strict_types=1);

/*
| Ported from the old tests/domain/journal.test.ts and year-end-close.test.ts.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\DraftLine;
use App\Domain\Accounting\Journal;
use App\Domain\Accounting\YearEndClose;
use App\Domain\Errors\UnbalancedEntryError;
use App\Domain\Errors\ValidationError;
use App\Enums\AccountType;

const CASH = 1;
const SALES = 2;
const COGS = 3;
const INVENTORY = 4;
const RECEIVABLE = 5;
const RETAINED = 3200;

describe('line construction', function () {
    it('builds one-sided lines', function () {
        expect(Journal::debit(CASH, 50_000))->toEqual(new DraftLine(CASH, 50_000, 0))
            ->and(Journal::credit(SALES, 50_000))->toEqual(new DraftLine(SALES, 0, 50_000));
    });

    it('refuses negative amounts rather than flipping them silently', function () {
        expect(fn () => Journal::debit(CASH, -1))->toThrow(ValidationError::class)
            ->and(fn () => Journal::credit(SALES, -1))->toThrow(ValidationError::class);
    });

    it('routes a signed amount by its sign', function () {
        expect([Journal::postSigned(CASH, 500)->debit, Journal::postSigned(CASH, 500)->credit])->toBe([500, 0])
            ->and([Journal::postSigned(CASH, -500)->debit, Journal::postSigned(CASH, -500)->credit])->toBe([0, 500])
            ->and(Journal::postSigned(CASH, 0)->debit)->toBe(0);
    });

    it('carries optional metadata through', function () {
        $line = Journal::debit(CASH, 100, ['paymentAccountId' => 7, 'description' => 'Till']);
        expect([$line->paymentAccountId, $line->description])->toBe([7, 'Till']);
    });
});

describe('the balance check', function () {
    it('accepts a balanced cash sale and a multi-line credit sale with COGS', function () {
        $cash = [Journal::debit(CASH, 50_000), Journal::credit(SALES, 50_000)];
        Journal::assertBalanced($cash);
        // GHS 500 sale, 200 paid, 300 on credit, goods cost 320.
        Journal::assertBalanced([
            Journal::debit(CASH, 20_000), Journal::debit(RECEIVABLE, 30_000), Journal::credit(SALES, 50_000),
            Journal::debit(COGS, 32_000), Journal::credit(INVENTORY, 32_000),
        ]);
        expect([Journal::totalDebits($cash), Journal::totalCredits($cash)])->toBe([50_000, 50_000]);
    });

    it('rejects an entry that is off by a single pesewa', function () {
        Journal::assertBalanced([Journal::debit(CASH, 50_000), Journal::credit(SALES, 49_999)]);
    })->throws(UnbalancedEntryError::class);

    it('reports the actual totals on the error, and reassures the owner', function () {
        try {
            Journal::assertBalanced([Journal::debit(CASH, 100), Journal::credit(SALES, 90)]);
            $this->fail('should have thrown');
        } catch (UnbalancedEntryError $e) {
            expect([$e->details['totalDebit'], $e->details['totalCredit'], $e->details['difference']])->toBe([100, 90, 10])
                ->and($e->userMessage)->toContain('Nothing was changed');
        }
    });

    it('rejects empty, single-line and zero-value entries', function () {
        expect(fn () => Journal::assertBalanced([]))->toThrow(ValidationError::class)
            ->and(fn () => Journal::assertBalanced([Journal::debit(CASH, 100)]))->toThrow(ValidationError::class)
            ->and(fn () => Journal::assertBalanced([Journal::debit(CASH, 0), Journal::credit(SALES, 0)]))->toThrow(ValidationError::class);
    });

    it('rejects a line that is both a debit and a credit, or has no account', function () {
        expect(fn () => Journal::assertLinesWellFormed([new DraftLine(CASH, 100, 100)]))->toThrow(ValidationError::class)
            ->and(fn () => Journal::assertLinesWellFormed([new DraftLine(0, 100, 0)]))->toThrow(ValidationError::class);
    });
});

describe('dropping zero lines', function () {
    it('strips lines that move no money, and still enforces balance', function () {
        $lines = [Journal::debit(CASH, 100), Journal::debit(RECEIVABLE, 0), Journal::credit(SALES, 100)];
        expect(Journal::dropZeroLines($lines))->toHaveCount(2)
            ->and(Journal::finalise($lines))->toHaveCount(2)
            ->and(fn () => Journal::finalise([Journal::debit(CASH, 100), Journal::credit(SALES, 90), Journal::credit(INVENTORY, 0)]))->toThrow(UnbalancedEntryError::class);
    });
});

describe('signed balances', function () {
    it('present each account positively on its natural side', function () {
        expect(Journal::signedBalance(AccountType::Asset, 50_000, 20_000))->toBe(30_000)
            ->and(Journal::signedBalance(AccountType::Expense, 8_000, 0))->toBe(8_000)
            ->and(Journal::signedBalance(AccountType::Cogs, 32_000, 0))->toBe(32_000)
            ->and(Journal::signedBalance(AccountType::Liability, 0, 60_000))->toBe(60_000)
            ->and(Journal::signedBalance(AccountType::Revenue, 0, 50_000))->toBe(50_000)
            ->and(Journal::signedBalance(AccountType::Equity, 1_000, 60_000))->toBe(59_000)
            ->and(Journal::signedBalance(AccountType::ContraEquity, 20_000, 0))->toBe(20_000)
            ->and(Journal::signedBalance(AccountType::ContraRevenue, 5_000, 0))->toBe(5_000)
            ->and(Journal::signedBalance(AccountType::Asset, 10_000, 15_000))->toBe(-5_000);
    });
});

describe('reversals', function () {
    it('mirror every line so the pair nets to zero', function () {
        $original = [Journal::debit(CASH, 50_000), Journal::credit(SALES, 50_000)];
        $reversal = Journal::reverse($original);
        expect([$reversal[0]->accountId, $reversal[0]->debit, $reversal[0]->credit])->toBe([CASH, 0, 50_000])
            ->and([$reversal[1]->accountId, $reversal[1]->debit, $reversal[1]->credit])->toBe([SALES, 50_000, 0]);
        Journal::assertBalanced($reversal);
        $combined = [...$original, ...$reversal];
        expect(Journal::signedBalance(AccountType::Asset, Journal::totalDebits($combined), Journal::totalCredits($combined)))->toBe(0);
    });

    it('keep payment-account links and label the description, leaving none as none', function () {
        $reversal = Journal::reverse([Journal::debit(CASH, 100, ['paymentAccountId' => 3, 'description' => 'Cash sale']), Journal::credit(SALES, 100)]);
        expect([$reversal[0]->paymentAccountId, $reversal[0]->description, $reversal[1]->description])->toBe([3, 'Reversal: Cash sale', null]);
    });

    it('round-trip a complex entry', function () {
        $original = [Journal::debit(CASH, 20_000), Journal::debit(RECEIVABLE, 30_000), Journal::credit(SALES, 50_000), Journal::debit(COGS, 32_000), Journal::credit(INVENTORY, 32_000)];
        Journal::assertBalanced(Journal::reverse($original));
        expect(Journal::reverse(Journal::reverse($original)))->toEqual($original);
    });
});

describe('chart of accounts', function () {
    it('has unique, stable codes, each one defined, and classifies every type once', function () {
        $codes = array_column(ChartOfAccounts::systemAccounts(), 'code');
        expect($codes)->toBe(array_values(array_unique($codes)));
        foreach ((new ReflectionClass(ChartOfAccounts::class))->getConstants() as $code) {
            expect($codes)->toContain($code);
        }
        foreach (AccountType::cases() as $type) {
            expect(ChartOfAccounts::isBalanceSheetAccount($type))->toBe(! ChartOfAccounts::isProfitAndLossAccount($type));
        }
        expect(ChartOfAccounts::isBalanceSheetAccount(AccountType::Asset))->toBeTrue()
            ->and(ChartOfAccounts::isProfitAndLossAccount(AccountType::Revenue))->toBeTrue()
            ->and(ChartOfAccounts::isProfitAndLossAccount(AccountType::Cogs))->toBeTrue();
    });
});

/*
| Year-end close.
*/

function acct(int $accountId, string $name, int $balance): array
{
    return ['accountId' => $accountId, 'code' => (string) $accountId, 'name' => $name, 'balance' => $balance];
}

function closeYear(array $revenue, array $expenses = [], array $drawings = [], array $contra = []): array
{
    $result = YearEndClose::buildClosingEntry($revenue, $contra, $expenses, $drawings, RETAINED);
    // Every closing entry must balance, whatever the shape of the year.
    Journal::assertBalanced(Journal::finalise($result['lines']));

    return $result;
}

function lineFor(array $result, int $accountId): ?DraftLine
{
    foreach ($result['lines'] as $line) {
        if ($line->accountId === $accountId) {
            return $line;
        }
    }

    return null;
}

it('closes a profitable year into retained earnings', function () {
    $r = closeYear([acct(4000, 'Sales', 100_000)], [acct(5000, 'Cost of goods sold', 60_000), acct(6010, 'Rent', 10_000)]);
    expect([$r['totalRevenue'], $r['totalExpenses'], $r['profit']])->toBe([100_000, 70_000, 30_000])
        ->and(lineFor($r, 4000)->debit)->toBe(100_000)
        ->and(lineFor($r, 5000)->credit)->toBe(60_000)
        ->and(lineFor($r, 6010)->credit)->toBe(10_000)
        ->and(lineFor($r, RETAINED)->credit)->toBe(30_000);
});

it('debits retained earnings for a loss, showing the sign', function () {
    $r = closeYear([acct(4000, 'Sales', 40_000)], [acct(6010, 'Rent', 55_000)]);
    expect($r['profit'])->toBe(-15_000)->and(lineFor($r, RETAINED)->debit)->toBe(15_000);
});

it('closes drawings alongside profit, even when they exceed it', function () {
    $r = closeYear([acct(4000, 'Sales', 100_000)], [acct(6010, 'Rent', 40_000)], [acct(3100, "Owner's Drawings", 25_000)]);
    expect([$r['profit'], $r['totalDrawings'], $r['netToRetainedEarnings']])->toBe([60_000, 25_000, 35_000])
        ->and(lineFor($r, 3100)->credit)->toBe(25_000)
        ->and(lineFor($r, RETAINED)->credit)->toBe(35_000);

    $over = closeYear([acct(4000, 'Sales', 50_000)], [acct(6010, 'Rent', 20_000)], [acct(3100, "Owner's Drawings", 45_000)]);
    expect([$over['profit'], $over['netToRetainedEarnings']])->toBe([30_000, -15_000])
        ->and(lineFor($over, RETAINED)->debit)->toBe(15_000)
        ->and(lineFor($over, RETAINED)->description)->toBe('Loss for the year, plus drawings');
});

describe('awkward but real shapes', function () {
    it('skips accounts already at zero', function () {
        $r = closeYear([acct(4000, 'Sales', 50_000), acct(4200, 'Other income', 0)], [acct(6010, 'Rent', 20_000), acct(6020, 'Electricity', 0)]);
        expect(lineFor($r, 4200))->toBeNull()->and(lineFor($r, 6020))->toBeNull();
    });

    it('closes an account sitting on the wrong side of itself', function () {
        $r = closeYear([acct(4000, 'Sales', 80_000), acct(4150, 'Sales returns', -5_000)], [acct(6010, 'Rent', 20_000)]);
        expect(lineFor($r, 4150)->credit)->toBe(5_000)->and([$r['totalRevenue'], $r['profit']])->toBe([75_000, 55_000]);
    });

    it('balances a year that broke exactly even, moving nothing to retained earnings', function () {
        $r = closeYear([acct(4000, 'Sales', 50_000)], [acct(6010, 'Rent', 50_000)]);
        expect($r['profit'])->toBe(0)->and(lineFor($r, RETAINED))->toBeNull()->and($r['lines'])->toHaveCount(2);
    });

    it('refuses a year with nothing in it', function () {
        YearEndClose::buildClosingEntry([acct(4000, 'Sales', 0)], [], [], [], RETAINED);
    })->throws(ValidationError::class);
});

describe('discounts and returns', function () {
    it('takes contra-revenue off the takings, closing it with a credit', function () {
        $r = closeYear([acct(4000, 'Sales', 100_000)], [acct(6010, 'Rent', 40_000)], contra: [acct(4100, 'Sales discounts', 10_000)]);
        expect([$r['grossRevenue'], $r['totalContraRevenue'], $r['totalRevenue'], $r['profit']])->toBe([100_000, 10_000, 90_000, 50_000])
            ->and([lineFor($r, 4100)->credit, lineFor($r, 4100)->debit])->toBe([10_000, 0]);
    });

    it('handles a contra account on its own wrong side', function () {
        $r = closeYear([acct(4000, 'Sales', 100_000)], contra: [acct(4100, 'Sales discounts', -2_000)]);
        expect(lineFor($r, 4100)->debit)->toBe(2_000)->and($r['totalRevenue'])->toBe(102_000);
    });
});
