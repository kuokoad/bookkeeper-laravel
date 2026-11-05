<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/account-statement.test.ts. The rule
| throughout: opening + money in − money out = closing, and the running balance
| on the newest row in the window equals closing.
*/

use App\Domain\Errors\NotFoundError;
use App\Services\PaymentAccountService;
use Tests\Support\Shop;

const STMT_WINDOW = ['from' => '2026-08-01', 'to' => '2026-08-31'];

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $expenseCategory = PaymentAccountService::listExpenseCategories()[0]['id'];
    $incomeCategory = PaymentAccountService::listIncomeCategories()[0]['id'];
    $this->spend = fn (string $date, int $amount, string $description = 'Taxi') => Shop::expense($this->actor, $date, $expenseCategory, $amount, $description);
    $this->receive = fn (string $date, int $amount, string $description = 'Commission') => Shop::income($this->actor, $date, $incomeCategory, $amount, $description);
    $this->statement = fn (array $query = STMT_WINDOW): array => PaymentAccountService::getAccountStatement($this->cash, $query);
});

describe('opening and closing balances', function () {
    // The bug this replaced: a running balance from zero over the filtered
    // rows, so a cash account holding GHS 2,092.70 closed at MINUS 907.30.
    it('carries the balance from before the window rather than restarting at zero', function () {
        ($this->receive)('2026-07-10', 300_000); // 3,000.00 in, before the window
        ($this->spend)('2026-08-05', 45_000); // 450.00 out, inside it
        ($this->receive)('2026-08-06', 2_200); // 22.00 in, inside it

        $statement = ($this->statement)();
        expect($statement['opening'])->toBe(300_000)
            ->and($statement['moneyIn'])->toBe(2_200)
            ->and($statement['moneyOut'])->toBe(45_000)
            ->and($statement['closing'])->toBe(300_000 + 2_200 - 45_000)
            // And the newest row's running balance IS the closing figure.
            ->and($statement['movements'][0]['runningBalance'])->toBe($statement['closing']);
    });

    it('opens at nothing when the window starts before the account was ever used', function () {
        ($this->receive)('2026-08-06', 2_200);

        $statement = ($this->statement)(['from' => '2026-01-01', 'to' => '2026-12-31']);
        expect($statement['opening'])->toBe(0)
            ->and($statement['closing'])->toBe(2_200);
    });

    it('closes at the account balance when the window ends today', function () {
        ($this->receive)('2026-07-10', 300_000);
        ($this->spend)('2026-08-05', 45_000);

        // MySQL's DATE starts at 1000-01-01; the old test's 0000-01-01 is the same "from the beginning".
        expect(($this->statement)(['from' => '1000-01-01', 'to' => '2099-12-31'])['closing'])->toBe(PaymentAccountService::getPaymentAccount($this->cash)['balance']);
    });

    it('does not work the opening balance backwards from today', function () {
        ($this->receive)('2026-06-01', 100_000);
        ($this->spend)('2026-09-01', 40_000); // AFTER the window: must not touch its opening

        $statement = ($this->statement)(['from' => '2026-07-01', 'to' => '2026-07-31']);

        // The account holds 60,000 today. July opened at 100,000 and nothing moved.
        expect(PaymentAccountService::getPaymentAccount($this->cash)['balance'])->toBe(60_000)
            ->and($statement['opening'])->toBe(100_000)
            ->and($statement['closing'])->toBe(100_000)
            ->and($statement['movements'])->toBe([]);
    });

    it('includes a movement on the last day of the window', function () {
        ($this->spend)('2026-08-31', 1_000);
        $statement = ($this->statement)();
        expect($statement['total'])->toBe(1)
            ->and($statement['moneyOut'])->toBe(1_000);
    });

    it('excludes a movement one day past the window', function () {
        ($this->spend)('2026-09-01', 1_000);
        $statement = ($this->statement)();
        expect($statement['total'])->toBe(0)
            ->and($statement['moneyOut'])->toBe(0);
    });
});

describe('the running balance across pages', function () {
    // Page two carries on from where page one stopped.
    it('continues from one page to the next', function () {
        ($this->receive)('2026-07-31', 100_000);
        for ($day = 1; $day <= 12; $day++) {
            ($this->spend)(sprintf('2026-08-%02d', $day), 1_000);
        }

        $first = ($this->statement)([...STMT_WINDOW, 'limit' => 5, 'offset' => 0]);
        $second = ($this->statement)([...STMT_WINDOW, 'limit' => 5, 'offset' => 5]);
        expect($first['movements'])->toHaveCount(5)
            ->and($second['movements'])->toHaveCount(5);

        // Newest first: the oldest row of page one sits directly above the
        // newest row of page two, and they differ by exactly that movement.
        $oldestOnPageOne = $first['movements'][4];
        $newestOnPageTwo = $second['movements'][0];
        expect($oldestOnPageOne['runningBalance'])->toBe($newestOnPageTwo['runningBalance'] + $oldestOnPageOne['inMinor'] - $oldestOnPageOne['outMinor'])
            ->and($first['opening'])->toBe($second['opening'])
            ->and($first['closing'])->toBe($second['closing'])
            ->and($first['total'])->toBe(12);
    });
});

describe('the other filters', function () {
    it('separates money in from money out', function () {
        ($this->receive)('2026-08-02', 5_000);
        ($this->spend)('2026-08-03', 2_000);

        expect(($this->statement)([...STMT_WINDOW, 'flow' => 'in'])['total'])->toBe(1)
            ->and(($this->statement)([...STMT_WINDOW, 'flow' => 'out'])['total'])->toBe(1);
    });

    it('narrows to one kind of transaction', function () {
        ($this->receive)('2026-08-02', 5_000);
        ($this->spend)('2026-08-03', 2_000);

        expect(($this->statement)([...STMT_WINDOW, 'sourceType' => 'EXPENSE'])['total'])->toBe(1)
            ->and(($this->statement)([...STMT_WINDOW, 'sourceType' => 'INCOME'])['total'])->toBe(1)
            // A type this account has never seen matches nothing rather than erroring.
            ->and(($this->statement)([...STMT_WINDOW, 'sourceType' => 'NONSENSE'])['total'])->toBe(0);
    });

    it('offers only the transaction kinds this account has actually seen', function () {
        ($this->receive)('2026-08-02', 5_000);
        ($this->spend)('2026-08-03', 2_000);

        expect(PaymentAccountService::listAccountSourceTypes($this->cash))->toContain('EXPENSE', 'INCOME')
            ->not->toContain('PURCHASE');
    });

    it('searches the entry number, memo and line description', function () {
        ($this->spend)('2026-08-03', 2_000, 'Taxi to Madina market');

        expect(($this->statement)([...STMT_WINDOW, 'search' => 'madina'])['total'])->toBe(1)
            ->and(($this->statement)([...STMT_WINDOW, 'search' => 'JE-'])['total'])->toBe(1)
            ->and(($this->statement)([...STMT_WINDOW, 'search' => 'nothing'])['total'])->toBe(0)
            // Wildcards are literal (filter rule 4): "%" does not match every row.
            ->and(($this->statement)([...STMT_WINDOW, 'search' => '%'])['total'])->toBe(0);
    });

    it('narrows to a band of amounts', function () {
        ($this->spend)('2026-08-02', 1_000);
        ($this->spend)('2026-08-03', 50_000);

        expect(($this->statement)([...STMT_WINDOW, 'minAmount' => 10_000])['total'])->toBe(1)
            ->and(($this->statement)([...STMT_WINDOW, 'maxAmount' => 10_000])['total'])->toBe(1);
    });

    // The opening balance is what the account HELD, not a subtotal of the rows a search box matches.
    it('leaves the opening balance alone whatever else is filtered', function () {
        ($this->receive)('2026-07-10', 300_000);
        ($this->spend)('2026-08-05', 45_000);
        ($this->receive)('2026-08-06', 2_200);

        $all = ($this->statement)();
        $outOnly = ($this->statement)([...STMT_WINDOW, 'flow' => 'out']);
        expect($outOnly['opening'])->toBe($all['opening'])
            ->and($outOnly['moneyIn'])->toBe(0)
            ->and($outOnly['moneyOut'])->toBe(45_000);
    });

    it('counts the same movements the statement returns', function () {
        for ($day = 1; $day <= 7; $day++) {
            ($this->spend)("2026-08-0{$day}", 1_000);
        }
        expect(PaymentAccountService::countAccountMovements($this->cash, STMT_WINDOW))->toBe(7)
            ->and(($this->statement)([...STMT_WINDOW, 'limit' => 3])['total'])->toBe(7);
    });
});

describe('an account that does not exist', function () {
    it('is reported as not found rather than as an empty statement', function () {
        expect(fn () => PaymentAccountService::getAccountStatement(9_999))->toThrow(NotFoundError::class)
            ->and(fn () => PaymentAccountService::countAccountMovements(9_999))->toThrow(NotFoundError::class);
    });
});
