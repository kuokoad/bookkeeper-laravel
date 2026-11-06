<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/transfers.test.ts, plus the service-level
| parts of tests/app/transfer-action.test.ts (what is stored, what is refused
| and the words the refusal uses). The action's own state, field errors,
| redirect and permission checks wait for the screens (Phase 8).
|
| A transfer changes WHERE the money is and nothing else: the profit and the
| cash flow are read through the real reports (FinancialService).
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\PeriodLockedError;
use App\Domain\Errors\ValidationError;
use App\Services\PaymentAccountService;
use App\Services\PeriodLockService;
use App\Services\Reporting\FinancialService;
use App\Services\TransferService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->bank = Shop::paymentAccount('BANK');
    $this->momo = Shop::paymentAccount('MOBILE_MONEY');
    $this->balanceOf = fn (int $id): int => PaymentAccountService::getPaymentAccountBalance($id);
    $this->totalHeld = fn (): int => ($this->balanceOf)($this->cash) + ($this->balanceOf)($this->bank) + ($this->balanceOf)($this->momo);
    $this->bankCash = fn (int $amount, string $date = '2026-08-10', array $extra = []): array => TransferService::recordTransfer(
        ['businessDate' => $date, 'fromAccountId' => $this->cash, 'toAccountId' => $this->bank, 'amount' => $amount, ...$extra],
        $this->actor,
    );
    $this->voidAt = Carbon::parse('2026-08-20 09:00', 'Africa/Accra');

    // GHS 1,000.00 in the drawer to start with.
    Shop::capital($this->actor, $this->cash, 100_000, '2026-08-01');
});

describe('banking cash', function () {
    it('takes it out of Cash and puts it in the Bank', function () {
        ($this->bankCash)(60_000);

        expect(($this->balanceOf)($this->cash))->toBe(40_000)
            ->and(($this->balanceOf)($this->bank))->toBe(60_000);
    });

    it('leaves the total money held exactly where it was', function () {
        $before = ($this->totalHeld)();
        ($this->bankCash)(60_000);

        expect(($this->totalHeld)())->toBe($before);
    });

    it('touches neither the profit nor the owner’s capital and drawings', function () {
        $capital = Shop::balance(ChartOfAccounts::OWNERS_CAPITAL);
        $drawings = Shop::balance(ChartOfAccounts::OWNERS_DRAWINGS);

        ($this->bankCash)(60_000);

        expect(FinancialService::getProfitAndLoss(['from' => '2026-08-01', 'to' => '2026-08-31'])['netProfit'])->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::OWNERS_CAPITAL))->toBe($capital)
            ->and(Shop::balance(ChartOfAccounts::OWNERS_DRAWINGS))->toBe($drawings)
            ->and(Shop::trialBalanced())->toBeTrue();
    });

    it('is posted on the day the money moved, and numbered', function () {
        $result = ($this->bankCash)(60_000, '2026-08-10');

        $entry = DB::table('journal_entries')->where('id', $result['journalEntryId'])->first();
        expect($result['transferNo'])->toBe('TRF-00001')
            ->and($entry->entry_date)->toBe('2026-08-10')
            ->and($entry->source_type)->toBe('TRANSFER');
    });

    it('posts Dr the to-account, Cr the from-account, each line tagged with its account', function () {
        $result = ($this->bankCash)(60_000, '2026-08-10', ['reference' => 'Slip 4471']);

        $entry = DB::table('journal_entries')->where('id', $result['journalEntryId'])->first();
        $lines = DB::table('journal_lines')->where('entry_id', $result['journalEntryId'])->orderBy('line_no')->get();
        expect($entry->memo)->toBe('TRF-00001 Cash to Bank Account — Slip 4471')
            ->and([(int) $lines[0]->account_id, (int) $lines[0]->debit_minor, (int) $lines[0]->payment_account_id, $lines[0]->description])
            ->toBe([Shop::accountId('1021'), 60_000, $this->bank, 'TRF-00001 from Cash'])
            ->and([(int) $lines[1]->account_id, (int) $lines[1]->credit_minor, (int) $lines[1]->payment_account_id, $lines[1]->description])
            ->toBe([Shop::accountId('1001'), 60_000, $this->cash, 'TRF-00001 to Bank Account'])
            ->and(DB::table('audit_logs')->where('entity_type', 'account_transfer')->value('summary'))->toBe('TRF-00001: Cash to Bank Account');
    });

    it('shows on both statements: out of Cash, into the Bank', function () {
        ($this->bankCash)(60_000);

        $cashOut = collect(PaymentAccountService::getAccountStatement($this->cash)['movements'])->firstWhere('sourceType', 'TRANSFER');
        $bankIn = collect(PaymentAccountService::getAccountStatement($this->bank)['movements'])->firstWhere('sourceType', 'TRANSFER');
        expect($cashOut['outMinor'])->toBe(60_000)
            ->and($bankIn['inMinor'])->toBe(60_000);
    });

    it('is not money in or out of the shop on the cash flow, only between its accounts', function () {
        ['id' => $id] = ($this->bankCash)(60_000);
        // A void posts a second TRANSFER entry; that is not money moving either.
        TransferService::voidTransfer($id, 'Wrong amount', $this->actor, $this->voidAt);
        ($this->bankCash)(25_000, '2026-08-21');

        $shop = FinancialService::getCashFlow(['from' => '2026-08-01', 'to' => '2026-08-31']);
        expect(collect($shop['lines'])->firstWhere('sourceType', 'TRANSFER'))->toBeNull()
            // Only the owner's GHS 1,000 actually came into the business.
            ->and($shop['totalIn'])->toBe(100_000)
            ->and($shop['totalOut'])->toBe(0)
            ->and($shop['netMovement'])->toBe(100_000)
            // reconciles: the net movement is what the accounts hold now.
            ->and($shop['netMovement'])->toBe(($this->totalHeld)());

        // Each account on its own still shows the money arriving or leaving.
        $bankOnly = FinancialService::getCashFlow(['from' => '2026-08-01', 'to' => '2026-08-31'], $this->bank);
        expect(collect($bankOnly['lines'])->firstWhere('sourceType', 'TRANSFER')['inMinor'])->toBe(85_000)
            ->and($bankOnly['netMovement'])->toBe(25_000)
            ->and(FinancialService::getCashFlow(['from' => '2026-08-01', 'to' => '2026-08-31'], $this->cash)['netMovement'])->toBe(75_000);
    });
});

describe('what a transfer refuses', function () {
    it('the same account on both sides', function () {
        expect(fn () => TransferService::recordTransfer(['businessDate' => '2026-08-10', 'fromAccountId' => $this->cash, 'toAccountId' => $this->cash, 'amount' => 100], $this->actor))
            ->toThrow(ValidationError::class, 'two different accounts');
        expect(DB::table('account_transfers')->count())->toBe(0);
    });

    it('an amount of nothing', function () {
        expect(fn () => ($this->bankCash)(0))->toThrow(ValidationError::class, 'greater than zero');
    });

    it('an archived account', function () {
        PaymentAccountService::setPaymentAccountActive($this->momo, false, $this->actor);

        expect(fn () => TransferService::recordTransfer(['businessDate' => '2026-08-10', 'fromAccountId' => $this->cash, 'toAccountId' => $this->momo, 'amount' => 100], $this->actor))
            ->toThrow(ValidationError::class, 'archived');
    });

    it('a date inside the books lock, and records nothing', function () {
        PeriodLockService::setLock('2026-08-15', $this->actor);

        expect(fn () => ($this->bankCash)(60_000, '2026-08-15'))->toThrow(PeriodLockedError::class);
        expect(DB::table('account_transfers')->count())->toBe(0)
            ->and(($this->balanceOf)($this->cash))->toBe(100_000);

        // The day after the lock is open.
        ($this->bankCash)(60_000, '2026-08-16');
        expect(($this->balanceOf)($this->bank))->toBe(60_000);
    });

    it('explains a date inside the books lock in words (the transfer form’s message)', function () {
        DB::table('business_settings')->where('id', 1)->update(['books_locked_before' => '2026-08-15']);

        try {
            ($this->bankCash)(60_000, '2026-08-12');
            $this->fail('A transfer inside the lock was recorded.');
        } catch (PeriodLockedError $e) {
            expect($e->userMessage)->toContain('The books are closed up to 15 Aug 2026');
        }
        expect(DB::table('account_transfers')->count())->toBe(0);
    });

    it('stores the amount and reference the form sent (GHS 600.00, "Slip 4471")', function () {
        ($this->bankCash)(60_000, '2026-08-10', ['reference' => ' Slip 4471 ']);

        $row = DB::table('account_transfers')->first();
        expect((int) $row->amount_minor)->toBe(60_000)
            ->and($row->reference)->toBe('Slip 4471');
    });
});

describe('voiding a transfer', function () {
    it('puts both balances back and keeps the record', function () {
        ['id' => $id] = ($this->bankCash)(60_000);

        TransferService::voidTransfer($id, 'Wrong amount', $this->actor, $this->voidAt);

        $row = TransferService::listTransfers()[0];
        expect(($this->balanceOf)($this->cash))->toBe(100_000)
            ->and(($this->balanceOf)($this->bank))->toBe(0)
            ->and($row['status'])->toBe('VOIDED')
            ->and($row['voidReason'])->toBe('Wrong amount')
            ->and(Shop::trialBalanced())->toBeTrue();
    });

    it('posts the reversal on the day of the void, not the day of the transfer', function () {
        ['id' => $id, 'journalEntryId' => $entryId] = ($this->bankCash)(60_000, '2026-08-10');

        TransferService::voidTransfer($id, 'Wrong amount', $this->actor, $this->voidAt);

        $original = DB::table('journal_entries')->where('id', $entryId)->first();
        $reversal = DB::table('journal_entries')->where('id', $original->reversed_by_entry_id)->first();
        expect($reversal->entry_date)->toBe('2026-08-20')
            ->and($reversal->source_type)->toBe('TRANSFER');
    });

    it('works on a transfer inside a closed period, without reopening it', function () {
        ['id' => $id] = ($this->bankCash)(60_000, '2026-08-10');
        PeriodLockService::setLock('2026-08-15', $this->actor);

        TransferService::voidTransfer($id, 'Banked twice', $this->actor, $this->voidAt);

        expect(($this->balanceOf)($this->bank))->toBe(0)
            // Up to the lock date the books still say the money was banked.
            ->and(PaymentAccountService::getPaymentAccountBalance($this->bank, '2026-08-15'))->toBe(60_000);
    });

    it('cannot be done twice', function () {
        ['id' => $id] = ($this->bankCash)(60_000);
        TransferService::voidTransfer($id, 'Wrong amount', $this->actor);

        expect(fn () => TransferService::voidTransfer($id, 'Again', $this->actor))->toThrow(ConflictError::class, 'already been voided');
        expect(($this->balanceOf)($this->cash))->toBe(100_000);
    });

    it('needs a reason, and leaves the transfer alone (also the form’s "no")', function () {
        ['id' => $id] = ($this->bankCash)(60_000);

        expect(fn () => TransferService::voidTransfer($id, '', $this->actor))->toThrow(ValidationError::class, 'reason')
            ->and(fn () => TransferService::voidTransfer($id, 'no', $this->actor))->toThrow(ValidationError::class, 'reason');
        expect(($this->balanceOf)($this->bank))->toBe(60_000)
            ->and(DB::table('account_transfers')->where('id', $id)->value('status'))->toBe('POSTED');
    });
});
