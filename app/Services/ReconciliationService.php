<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Domain\BusinessDate;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Domain\JsCompat;
use App\Domain\Money;
use App\Enums\AuditAction;
use App\Enums\DocType;
use App\Enums\JournalSourceType;
use App\Support\Clock;
use App\Support\Row;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Counting the till, the MoMo wallet or the bank against the books. History is
 * never edited to make the numbers agree: a difference is recorded, must be
 * explained, and only when the owner accepts it is an adjusting entry posted
 * to Cash Over / Short (5910). A port of the old reconciliation.service.ts.
 */
final class ReconciliationService
{
    /**
     * Everything needed to start a count: the book balance as at the day being
     * counted (not today), and the previous count on or before it.
     *
     * @return array{paymentAccountId: int, accountName: string, kind: string, expected: int, lastReconciliation: ?array}
     */
    public static function getReconciliationContext(int $paymentAccountId, string $asAt): array
    {
        $account = DB::table('payment_accounts')->where('id', $paymentAccountId)->first() ?? throw new NotFoundError('Payment account', $paymentAccountId);

        $previous = DB::table('reconciliations')
            ->where('reconciliations.payment_account_id', $paymentAccountId)
            ->where('reconciliations.status', 'POSTED')
            ->where('reconciliations.business_date', '<=', $asAt)
            ->orderByDesc('reconciliations.business_date')->orderByDesc('reconciliations.id')
            ->first();

        return [
            'paymentAccountId' => $paymentAccountId,
            'accountName' => $account->name,
            'kind' => $account->kind,
            'expected' => PaymentAccountService::getPaymentAccountBalance($paymentAccountId, $asAt),
            'lastReconciliation' => $previous === null ? null : [
                'id' => (int) $previous->id,
                'reconciliationNo' => $previous->reconciliation_no,
                'businessDate' => $previous->business_date,
                'actual' => (int) $previous->actual_minor,
                'difference' => (int) $previous->difference_minor,
                'adjusted' => (bool) $previous->adjusted,
            ],
        ];
    }

    /**
     * Input: ['paymentAccountId', 'businessDate', 'actual', 'explanation'?,
     * 'adjust' => bool, 'occurredAt'? => Carbon, 'isDemo'?].
     *
     * @return array{reconciliationId: int, reconciliationNo: string, expected: int, actual: int, difference: int, adjusted: bool, journalEntryId: ?int}
     */
    public static function createReconciliation(array $input, Actor $actor): array
    {
        return Tx::run(function () use ($input, $actor): array {
            $occurredAt = $input['occurredAt'] ?? Clock::now();
            // The latest count is picked by date: a future one would hide today's real shortage.
            BusinessDate::assertNotFuture($input['businessDate'], Clock::dateOf($occurredAt), 'count');
            $at = Clock::stamp($occurredAt);
            $accountId = $input['paymentAccountId'];

            // Locked before the balance is read: two counts of one till take
            // turns, and the cashbook's writers (share-locking the account)
            // cannot move it between this snapshot and the adjustment.
            $account = PaymentAccountService::lockAccounts([$accountId], exclusive: true)[$accountId] ?? throw new NotFoundError('Payment account', $accountId);
            if (! $account->is_active) {
                throw new ValidationError("\"{$account->name}\" is archived and cannot be counted.");
            }

            // What the books claimed at this moment. This is the evidence.
            $expected = PaymentAccountService::getPaymentAccountBalance($accountId, $input['businessDate']);
            $difference = self::countDifference($input['actual'], $expected);

            // The audit summary is written once and never revised, so it reads
            // the way the shop reads money.
            $currency = DB::table('business_settings')->where('id', 1)->value('currency_code') ?? 'GHS';

            $explanation = JsCompat::trim($input['explanation'] ?? '');
            if ($difference !== 0 && $explanation === '') {
                throw new ValidationError(
                    'There is a difference between the books and the count. Explain what you think happened before saving.',
                    ['expected' => $expected, 'actual' => $input['actual'], 'difference' => $difference],
                );
            }

            JournalService::readLockDate();
            $reconciliationNo = SequenceService::next(DocType::Reconciliation);
            $id = (int) DB::table('reconciliations')->insertGetId([
                'reconciliation_no' => $reconciliationNo,
                'payment_account_id' => $accountId,
                'business_date' => $input['businessDate'],
                'occurred_at' => $at,
                'expected_minor' => $expected,
                'actual_minor' => $input['actual'],
                'difference_minor' => $difference,
                'explanation' => $explanation !== '' ? $explanation : null,
                'adjusted' => false,
                'status' => 'POSTED',
                'created_by' => $actor->id,
                'is_demo' => $input['isDemo'] ?? false,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $journalEntryId = null;
            $adjusted = false;

            // Only a real difference the owner has accepted produces an entry.
            if ($difference !== 0 && ($input['adjust'] ?? false) === true) {
                $overShortId = JournalService::accountIdByCode(ChartOfAccounts::CASH_OVER_SHORT);
                $amount = Money::absolute($difference);
                $surplus = $difference > 0;
                $gl = (int) $account->gl_account_id;

                // Surplus: the account goes up and Cash Over / Short goes down (a gain). Shortage: the reverse.
                $lines = $surplus
                    ? [
                        Journal::debit($gl, $amount, ['paymentAccountId' => (int) $accountId, 'description' => "{$reconciliationNo} surplus found"]),
                        Journal::credit($overShortId, $amount, ['description' => "{$reconciliationNo} cash over"]),
                    ]
                    : [
                        Journal::debit($overShortId, $amount, ['description' => "{$reconciliationNo} cash short"]),
                        Journal::credit($gl, $amount, ['paymentAccountId' => (int) $accountId, 'description' => "{$reconciliationNo} shortage"]),
                    ];

                $posted = JournalService::post(
                    $input['businessDate'],
                    JournalSourceType::Reconciliation,
                    $lines,
                    sourceId: $id,
                    memo: "{$reconciliationNo} — {$account->name} ".($surplus ? 'over' : 'short').": {$explanation}",
                    isDemo: $input['isDemo'] ?? false,
                    occurredAt: $occurredAt,
                    actor: $actor,
                );
                $journalEntryId = $posted['entryId'];
                $adjusted = true;
                DB::table('reconciliations')->where('id', $id)->update(['adjusted' => true, 'journal_entry_id' => $journalEntryId, 'updated_at' => $at]);
            }

            AuditService::write(
                AuditAction::Reconcile,
                'reconciliation',
                $difference === 0
                    ? "{$reconciliationNo}: {$account->name} counted and agreed exactly"
                    : "{$reconciliationNo}: {$account->name} ".($difference > 0 ? 'over' : 'short').' by '.Money::format(Money::absolute($difference), $currency).($adjusted ? ' (adjusted)' : ' (left open)'),
                $id,
                $actor,
                [
                    'expectedMinor' => $expected,
                    'actualMinor' => $input['actual'],
                    'differenceMinor' => $difference,
                    'adjusted' => $adjusted,
                    'explanation' => $explanation !== '' ? $explanation : null,
                ],
                $occurredAt,
            );

            return [
                'reconciliationId' => $id,
                'reconciliationNo' => $reconciliationNo,
                'expected' => $expected,
                'actual' => $input['actual'],
                'difference' => $difference,
                'adjusted' => $adjusted,
                'journalEntryId' => $journalEntryId,
            ];
        });
    }

    /** Void a count: the record is kept, and any adjusting entry is reversed, dated the day of the void. */
    public static function voidReconciliation(int $reconciliationId, string $reason, Actor $actor, ?Carbon $now = null): void
    {
        $why = JsCompat::trim($reason);
        if (mb_strlen($why) < 3) {
            throw new ValidationError('Give a reason for voiding this count.');
        }
        $now ??= Clock::now();

        Tx::run(function () use ($reconciliationId, $why, $actor, $now): void {
            $record = DB::table('reconciliations')->where('id', $reconciliationId)->lockForUpdate()->first() ?? throw new NotFoundError('Reconciliation', $reconciliationId);
            if ($record->status === 'VOIDED') {
                throw new ConflictError('That count has already been voided.');
            }
            // Tx 3a: the accounts the reversal moves, shared, so archiving or counting one waits.
            PaymentAccountService::lockAccountsOfEntry($record->journal_entry_id);

            if ($record->journal_entry_id !== null) {
                JournalService::reverse(
                    (int) $record->journal_entry_id,
                    Clock::dateOf($now),
                    JournalSourceType::Reconciliation,
                    $reconciliationId,
                    "Void of {$record->reconciliation_no}: {$why}",
                    occurredAt: $now,
                    actor: $actor,
                );
            }

            DB::table('reconciliations')->where('id', $reconciliationId)->update([
                'status' => 'VOIDED',
                'voided_at' => Clock::stamp($now),
                'void_reason' => $why,
                'updated_at' => Clock::stamp($now),
            ]);
            AuditService::write(AuditAction::Void, 'reconciliation', "Voided count {$record->reconciliation_no}", $reconciliationId, $actor, ['reason' => $why], $now);
        });
    }

    // --- reads --------------------------------------------------------------

    /** Newest first. @return list<array> */
    public static function listReconciliations(?int $paymentAccountId = null, int $limit = 100, ?int $id = null): array
    {
        $q = DB::table('reconciliations')->join('payment_accounts', 'payment_accounts.id', '=', 'reconciliations.payment_account_id');
        if ($paymentAccountId !== null) {
            $q->where('reconciliations.payment_account_id', $paymentAccountId);
        }
        if ($id !== null) {
            $q->where('reconciliations.id', $id);
        }

        return $q->orderByDesc('reconciliations.business_date')->orderByDesc('reconciliations.id')
            ->limit(min($limit, 500))
            ->get([
                'reconciliations.id', 'reconciliations.reconciliation_no', 'reconciliations.payment_account_id',
                'payment_accounts.name AS account_name', 'reconciliations.business_date', 'reconciliations.occurred_at',
                'reconciliations.expected_minor', 'reconciliations.actual_minor', 'reconciliations.difference_minor',
                'reconciliations.explanation', 'reconciliations.adjusted', 'reconciliations.status', 'reconciliations.journal_entry_id',
            ])->map(fn (object $row): array => [...Row::camel($row), 'adjusted' => (bool) $row->adjusted])->all();
    }

    /** Counted less the books: positive is money over, negative money short. The page's live preview uses the same rule. */
    public static function countDifference(int $actual, int $expected): int
    {
        return Money::subtract($actual, $expected);
    }

    /**
     * The overview's headline: every unresolved difference added up, and how
     * many accounts were never counted.
     *
     * @param  list<array{unresolvedDifference: int, lastCountedOn: ?string}>  $overview  getReconciliationOverview()
     * @return array{unresolved: int, neverCounted: int}
     */
    public static function getOverviewTotals(array $overview): array
    {
        return [
            'unresolved' => Money::sum(array_column($overview, 'unresolvedDifference')),
            'neverCounted' => count(array_filter($overview, fn (array $a): bool => $a['lastCountedOn'] === null)),
        ];
    }

    /**
     * One row per active account: its balance, its last count, and the
     * differences recorded but never adjusted (money still unaccounted for).
     *
     * @return list<array{paymentAccountId: int, accountName: string, kind: string, currentBalance: int, lastCountedOn: ?string, lastDifference: ?int, unresolvedDifference: int, countsRecorded: int}>
     */
    public static function getReconciliationOverview(): array
    {
        $accounts = PaymentAccountService::listPaymentAccounts();
        $counts = DB::table('reconciliations')
            ->whereIn('reconciliations.payment_account_id', array_column($accounts, 'id'))
            ->where('reconciliations.status', 'POSTED')
            ->orderByDesc('reconciliations.business_date')->orderByDesc('reconciliations.id')
            ->get(['reconciliations.payment_account_id', 'reconciliations.business_date', 'reconciliations.difference_minor', 'reconciliations.adjusted'])
            ->groupBy('payment_account_id');

        return array_map(function (array $account) use ($counts): array {
            $rows = $counts->get($account['id'], collect());
            $latest = $rows->first();

            return [
                'paymentAccountId' => $account['id'],
                'accountName' => $account['name'],
                'kind' => $account['kind'],
                'currentBalance' => $account['balance'],
                'lastCountedOn' => $latest?->business_date,
                'lastDifference' => $latest === null ? null : (int) $latest->difference_minor,
                // Each count is taken against the books, so the latest one already holds every earlier
                // shortfall still unexplained: adding them up counted the same missing money once per count.
                'unresolvedDifference' => $latest === null || $latest->adjusted ? 0 : (int) $latest->difference_minor,
                'countsRecorded' => $rows->count(),
            ];
        }, $accounts);
    }
}
