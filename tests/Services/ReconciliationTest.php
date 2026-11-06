<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/reconciliation.test.ts: what the books
| expect, agreeing counts, shortages, surpluses, history never rewritten,
| voids, the books lock, the overview and the audit row. The worked
| example's Profit & Loss is the real report (FinancialService).
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\PeriodLockedError;
use App\Domain\Errors\ValidationError;
use App\Services\PaymentAccountService;
use App\Services\PeriodLockService;
use App\Services\ReconciliationService;
use App\Services\Reporting\FinancialService;
use App\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const REC_TODAY = '2026-08-17';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->momo = Shop::paymentAccount('MOBILE_MONEY');
    // Put a known amount of real money into an account.
    $this->fund = fn (int $accountId, int $amount, string $date = '2026-08-01') => Shop::capital($this->actor, $accountId, $amount, $date);
    $this->count = fn (array $input): array => ReconciliationService::createReconciliation(
        ['paymentAccountId' => $this->cash, 'businessDate' => REC_TODAY, 'adjust' => true, ...$input],
        $this->actor,
    );
});

describe('what the books expect', function () {
    it('reports the balance as at the day being counted, not today', function () {
        ($this->fund)($this->cash, 100_000, '2026-08-01');
        ($this->fund)($this->cash, 50_000, '2026-08-10');

        expect(ReconciliationService::getReconciliationContext($this->cash, '2026-08-05')['expected'])->toBe(100_000)
            ->and(ReconciliationService::getReconciliationContext($this->cash, '2026-08-17')['expected'])->toBe(150_000);
    });

    it('has no previous count to begin with', function () {
        ($this->fund)($this->cash, 100_000);
        expect(ReconciliationService::getReconciliationContext($this->cash, REC_TODAY)['lastReconciliation'])->toBeNull();
    });

    it('remembers the previous count', function () {
        ($this->fund)($this->cash, 100_000);
        ($this->count)(['businessDate' => '2026-08-10', 'actual' => 100_000, 'adjust' => false]);

        $previous = ReconciliationService::getReconciliationContext($this->cash, REC_TODAY)['lastReconciliation'];
        expect($previous['businessDate'])->toBe('2026-08-10')
            ->and($previous['actual'])->toBe(100_000)
            ->and($previous['difference'])->toBe(0);
    });
});

describe('a count that agrees', function () {
    it('records the count and posts nothing', function () {
        ($this->fund)($this->cash, 245_000);

        $result = ($this->count)(['actual' => 245_000]);

        expect($result['expected'])->toBe(245_000)
            ->and($result['difference'])->toBe(0)
            ->and($result['adjusted'])->toBeFalse()
            ->and($result['journalEntryId'])->toBeNull()
            // Nothing moved; there was nothing to correct.
            ->and(PaymentAccountService::getPaymentAccountBalance($this->cash))->toBe(245_000)
            ->and(Shop::balance(ChartOfAccounts::CASH_OVER_SHORT))->toBe(0)
            ->and(Shop::trialBalanced())->toBeTrue();
    });

    it('does not require an explanation when there is no difference', function () {
        ($this->fund)($this->cash, 100_000);
        expect(($this->count)(['actual' => 100_000])['difference'])->toBe(0);
    });
});

describe('a shortage', function () {
    it('handles the brief’s worked example: books 2,450 but only 2,400 counted', function () {
        ($this->fund)($this->cash, 245_000);

        $result = ($this->count)(['actual' => 240_000, 'explanation' => 'Short after the market run — possibly wrong change given']);

        expect($result['expected'])->toBe(245_000)
            ->and($result['actual'])->toBe(240_000)
            ->and($result['difference'])->toBe(-5_000) // GHS -50.00
            ->and($result['adjusted'])->toBeTrue()
            // The books now agree with the drawer...
            ->and(PaymentAccountService::getPaymentAccountBalance($this->cash))->toBe(240_000)
            // ...and the missing money is VISIBLE as a cost, not hidden.
            ->and(Shop::balance(ChartOfAccounts::CASH_OVER_SHORT))->toBe(5_000)
            ->and(Shop::trialBalanced())->toBeTrue();

        // It reduces profit: Dr 5910, Cr the till, on the count's own date.
        $lines = DB::table('journal_lines')->where('entry_id', $result['journalEntryId'])->orderBy('line_no')->get();
        expect([(int) $lines[0]->account_id, (int) $lines[0]->debit_minor, $lines[0]->description])->toBe([Shop::accountId('5910'), 5_000, 'REC-00001 cash short'])
            ->and([(int) $lines[1]->account_id, (int) $lines[1]->credit_minor, (int) $lines[1]->payment_account_id, $lines[1]->description])->toBe([Shop::accountId('1001'), 5_000, $this->cash, 'REC-00001 shortage'])
            ->and(DB::table('journal_entries')->where('id', $result['journalEntryId'])->value('entry_date'))->toBe(REC_TODAY)
            ->and(DB::table('accounts')->where('code', '5910')->value('name'))->toBe('Cash Over / Short');

        // It reduces profit, which is the honest treatment.
        $pl = FinancialService::getProfitAndLoss(['from' => '2026-08-01', 'to' => '2026-08-31']);
        expect(collect($pl['expenses'])->contains('name', 'Cash Over / Short'))->toBeTrue()
            ->and($pl['totalExpenses'])->toBe(5_000);
    });

    it('can be recorded WITHOUT adjusting, leaving the money to be looked for', function () {
        ($this->fund)($this->cash, 245_000);

        $result = ($this->count)(['actual' => 240_000, 'explanation' => 'Will check the till roll tomorrow', 'adjust' => false]);

        expect($result['difference'])->toBe(-5_000)
            ->and($result['adjusted'])->toBeFalse()
            ->and($result['journalEntryId'])->toBeNull()
            // The books are UNCHANGED: the discrepancy is recorded, not absorbed.
            ->and(PaymentAccountService::getPaymentAccountBalance($this->cash))->toBe(245_000)
            ->and(Shop::balance(ChartOfAccounts::CASH_OVER_SHORT))->toBe(0);

        // And it shows as still unresolved.
        $overview = collect(ReconciliationService::getReconciliationOverview())->firstWhere('paymentAccountId', $this->cash);
        expect($overview['unresolvedDifference'])->toBe(-5_000);
    });

    it('refuses a difference with no explanation', function () {
        ($this->fund)($this->cash, 245_000);
        expect(fn () => ($this->count)(['actual' => 240_000]))->toThrow(ValidationError::class, 'Explain what you think happened');

        // Nothing was written.
        expect(DB::table('reconciliations')->count())->toBe(0);
    });
});

describe('a surplus', function () {
    it('increases the account and records a gain', function () {
        ($this->fund)($this->momo, 100_000);

        $result = ($this->count)(['paymentAccountId' => $this->momo, 'actual' => 102_500, 'explanation' => 'A MoMo deposit was not recorded at the time']);

        expect($result['difference'])->toBe(2_500)
            ->and(PaymentAccountService::getPaymentAccountBalance($this->momo))->toBe(102_500)
            // A surplus is a negative cost: it increases profit.
            ->and(Shop::balance(ChartOfAccounts::CASH_OVER_SHORT))->toBe(-2_500)
            ->and(Shop::trialBalanced())->toBeTrue();

        $lines = DB::table('journal_lines')->where('entry_id', $result['journalEntryId'])->orderBy('line_no')->get();
        expect([(int) $lines[0]->account_id, (int) $lines[0]->debit_minor, $lines[0]->description])->toBe([Shop::accountId('1011'), 2_500, 'REC-00001 surplus found'])
            ->and([(int) $lines[1]->account_id, (int) $lines[1]->credit_minor, $lines[1]->description])->toBe([Shop::accountId('5910'), 2_500, 'REC-00001 cash over']);
    });
});

describe('history is never rewritten', function () {
    it('leaves every original transaction exactly as it was', function () {
        $productId = Shop::product($this->actor, 'Milo', 500, 1_000);
        Shop::openingStock($this->actor, $productId, 100_000, 50_000, '2026-08-01');
        $sale = SaleService::createSale([
            'businessDate' => '2026-08-05',
            'items' => [['productId' => $productId, 'qty' => 10_000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 10_000]],
        ], $this->actor);
        $before = DB::table('sales')->where('id', $sale['saleId'])->first(['total_minor', 'cogs_minor']);

        ($this->count)(['actual' => 9_500, 'explanation' => 'Fifty pesewas short']);

        // The sale is byte-for-byte what it was. The correction is a NEW entry.
        expect(DB::table('sales')->where('id', $sale['saleId'])->first(['total_minor', 'cogs_minor']))->toEqual($before)
            ->and(Shop::balance(ChartOfAccounts::CASH_OVER_SHORT))->toBe(500);
    });

    it('keeps the snapshot of what the books said at the time', function () {
        ($this->fund)($this->cash, 100_000);
        $result = ($this->count)(['actual' => 95_000, 'explanation' => 'Short', 'adjust' => false]);

        // More money arrives afterwards.
        ($this->fund)($this->cash, 50_000, REC_TODAY);

        // The count still records what was expected WHEN IT WAS TAKEN.
        $record = DB::table('reconciliations')->where('id', $result['reconciliationId'])->first();
        expect((int) $record->expected_minor)->toBe(100_000)
            ->and((int) $record->actual_minor)->toBe(95_000)
            ->and((int) $record->difference_minor)->toBe(-5_000);
    });
});

describe('voiding a count', function () {
    it('reverses the adjustment and keeps the record', function () {
        ($this->fund)($this->cash, 245_000);
        $result = ($this->count)(['actual' => 240_000, 'explanation' => 'Miscounted']);
        expect(PaymentAccountService::getPaymentAccountBalance($this->cash))->toBe(240_000);

        ReconciliationService::voidReconciliation($result['reconciliationId'], 'The float was in the other drawer', $this->actor);

        // The books go back to what they said before.
        expect(PaymentAccountService::getPaymentAccountBalance($this->cash))->toBe(245_000)
            ->and(Shop::balance(ChartOfAccounts::CASH_OVER_SHORT))->toBe(0)
            ->and(Shop::trialBalanced())->toBeTrue();

        // But the count itself is still on record.
        $record = DB::table('reconciliations')->where('id', $result['reconciliationId'])->first();
        expect($record->status)->toBe('VOIDED')
            ->and((int) $record->actual_minor)->toBe(240_000)
            ->and($record->void_reason)->toBe('The float was in the other drawer');
    });

    it('refuses to void twice or without a reason', function () {
        ($this->fund)($this->cash, 100_000);
        $result = ($this->count)(['actual' => 100_000]);

        expect(fn () => ReconciliationService::voidReconciliation($result['reconciliationId'], 'x', $this->actor))->toThrow(ValidationError::class);
        ReconciliationService::voidReconciliation($result['reconciliationId'], 'Wrong account', $this->actor);
        expect(fn () => ReconciliationService::voidReconciliation($result['reconciliationId'], 'Again', $this->actor))->toThrow(ConflictError::class);
    });
});

describe('interaction with the books lock', function () {
    it('refuses an adjusting count dated inside a closed period', function () {
        ($this->fund)($this->cash, 100_000, '2026-07-01');
        PeriodLockService::setLock('2026-07-31', $this->actor);

        expect(fn () => ($this->count)(['businessDate' => '2026-07-15', 'actual' => 95_000, 'explanation' => 'Short']))->toThrow(PeriodLockedError::class);

        // Nothing partial survived.
        expect(DB::table('reconciliations')->count())->toBe(0);
    });

    it('allows counting an open period after the lock', function () {
        ($this->fund)($this->cash, 100_000, '2026-07-01');
        PeriodLockService::setLock('2026-07-31', $this->actor);

        expect(($this->count)(['businessDate' => '2026-08-05', 'actual' => 95_000, 'explanation' => 'Short'])['adjusted'])->toBeTrue();
    });
});

describe('overview and history', function () {
    it('summarises every account', function () {
        ($this->fund)($this->cash, 100_000);
        ($this->fund)($this->momo, 50_000);
        ($this->count)(['actual' => 100_000]);

        $overview = collect(ReconciliationService::getReconciliationOverview());
        $cash = $overview->firstWhere('paymentAccountId', $this->cash);
        $momo = $overview->firstWhere('paymentAccountId', $this->momo);

        expect($cash['lastCountedOn'])->toBe(REC_TODAY)
            ->and($cash['lastDifference'])->toBe(0)
            ->and($cash['countsRecorded'])->toBe(1)
            // Never counted.
            ->and($momo['lastCountedOn'])->toBeNull()
            ->and($momo['countsRecorded'])->toBe(0)
            ->and($momo['currentBalance'])->toBe(50_000);
    });

    it('lists counts newest first', function () {
        ($this->fund)($this->cash, 100_000, '2026-08-01');
        ($this->count)(['businessDate' => '2026-08-10', 'actual' => 100_000]);
        ($this->count)(['businessDate' => '2026-08-15', 'actual' => 100_000]);

        $list = ReconciliationService::listReconciliations($this->cash);
        expect($list)->toHaveCount(2)
            ->and($list[0]['businessDate'])->toBe('2026-08-15')
            ->and(ReconciliationService::getReconciliation($list[1]['id'])['businessDate'])->toBe('2026-08-10');
    });

    it('records the count in the audit log', function () {
        ($this->fund)($this->cash, 245_000);
        ($this->count)(['actual' => 240_000, 'explanation' => 'Short by fifty']);

        $entry = DB::table('audit_logs')->where('action', 'RECONCILE')->first();
        $metadata = json_decode($entry->metadata, true);
        expect($entry->summary)->toBe('REC-00001: Cash short by GHS 50.00 (adjusted)')
            ->and($entry->username)->toBe('kwame')
            ->and($metadata['expectedMinor'])->toBe(245_000)
            ->and($metadata['actualMinor'])->toBe(240_000)
            ->and($metadata['adjusted'])->toBeTrue();
    });
});
