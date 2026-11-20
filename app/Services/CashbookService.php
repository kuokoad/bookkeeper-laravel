<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\RoleGuard;
use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Domain\BusinessDate;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Domain\JsCompat;
use App\Domain\Money;
use App\Enums\AuditAction;
use App\Enums\DocType;
use App\Enums\JournalSourceType;
use App\Support\Clock;
use App\Support\Filters\Filters;
use App\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Expenses and other income, mirror images posted by one function:
 *
 *   Expense: Dr expense category   Cr Cash / MoMo / Bank
 *   Income:  Dr Cash / MoMo / Bank Cr income category
 *
 * and the owner's capital and drawings. A port of the old cashbook.service.ts.
 *
 * Input: ['businessDate', 'categoryAccountId', 'description', 'amount',
 * 'paymentAccountId', 'reference'?, 'note'?, 'occurredAt'? => Carbon, 'isDemo'?].
 */
final class CashbookService
{
    public const CASHBOOK_SORTS = ['date', 'amount', 'category', 'reference'];

    /** The table, number column, document type, journal source and entity of each direction. */
    /** Owner money voids the way an expense does, by a reversal; kept apart from SIDES, which also numbers new records. */
    private const OWNER_SIDES = [
        'CAPITAL' => ['table' => 'owner_movements', 'no' => 'movement_no', 'source' => JournalSourceType::Capital, 'entity' => 'owner_capital', 'name' => 'Owner capital'],
        'DRAWINGS' => ['table' => 'owner_movements', 'no' => 'movement_no', 'source' => JournalSourceType::Drawings, 'entity' => 'owner_drawings', 'name' => 'Owner drawings'],
    ];

    private const SIDES = [
        'EXPENSE' => ['table' => 'expenses', 'no' => 'expense_no', 'doc' => DocType::Expense, 'source' => JournalSourceType::Expense, 'entity' => 'expense', 'name' => 'Expense'],
        'INCOME' => ['table' => 'incomes', 'no' => 'income_no', 'doc' => DocType::Income, 'source' => JournalSourceType::Income, 'entity' => 'income', 'name' => 'Income'],
    ];

    /**
     * The category must be a leaf account of the right type: an expense posted
     * to revenue, or to a heading, would quietly corrupt the Profit & Loss.
     * And one of the categories the form offers (PaymentAccountService::
     * listExpenseCategories / listIncomeCategories): Sales Revenue or Cost of
     * Goods Sold is the right type too, but income typed in there would show
     * as sales no sale stands behind.
     */
    private static function assertCategoryUsable(int $accountId, string $direction, ?int $keeping = null): string
    {
        $account = DB::table('accounts')->where('id', $accountId)->first() ?? throw new NotFoundError('Account', $accountId);

        $expected = $direction === 'EXPENSE' ? ['EXPENSE', 'COGS'] : ['REVENUE'];
        if (! in_array($account->type, $expected, true)) {
            throw new ValidationError(
                "\"{$account->name}\" is not a valid ".($direction === 'EXPENSE' ? 'expense' : 'income').' category.',
                ['accountId' => $accountId, 'type' => $account->type],
            );
        }
        if (! $account->is_active) {
            throw new ValidationError("The category \"{$account->name}\" is no longer in use.");
        }
        if (DB::table('accounts')->where('accounts.parent_id', $accountId)->exists()) {
            throw new ValidationError("\"{$account->name}\" is a heading. Choose one of the categories under it.");
        }
        $heading = $direction === 'EXPENSE' ? ChartOfAccounts::OPERATING_EXPENSES : ChartOfAccounts::OTHER_INCOME;
        // An entry may keep the category it already has (the old app took any account of the type), so an
        // imported one can still have its amount or description corrected. It cannot be moved to another such account.
        if ($accountId !== $keeping && ($account->parent_id === null || DB::table('accounts')->where('accounts.id', $account->parent_id)->value('code') !== $heading)) {
            throw new ValidationError(
                "\"{$account->name}\" is not one of the ".($direction === 'EXPENSE' ? 'expense' : 'income').' categories. Choose one from the list.',
                ['accountId' => $accountId],
            );
        }

        return $account->name;
    }

    /** @return array{id: int, documentNo: string, journalEntryId: int} */
    private static function record(string $direction, array $input, Actor $actor): array
    {
        if ($input['amount'] <= 0) {
            throw new ValidationError('Enter an amount greater than zero.');
        }
        if (JsCompat::trim($input['description'] ?? '') === '') {
            throw new ValidationError('Enter a short description.');
        }
        $side = self::SIDES[$direction];

        return Tx::run(function () use ($direction, $side, $input, $actor): array {
            $occurredAt = $input['occurredAt'] ?? Clock::now();
            BusinessDate::assertNotFuture($input['businessDate'], Clock::dateOf($occurredAt), strtolower($side['name']));
            $at = Clock::stamp($occurredAt);
            $categoryName = self::assertCategoryUsable($input['categoryAccountId'], $direction);

            $account = PaymentAccountService::lockAccounts([$input['paymentAccountId']])[$input['paymentAccountId']] ?? throw new NotFoundError('Payment account', $input['paymentAccountId']);
            if (! $account->is_active) {
                throw new ValidationError("Payment account \"{$account->name}\" is not active.");
            }

            JournalService::readLockDate();
            $documentNo = SequenceService::next($side['doc']);
            $description = JsCompat::trim($input['description']);

            $id = (int) DB::table($side['table'])->insertGetId([
                $side['no'] => $documentNo,
                'business_date' => $input['businessDate'],
                'occurred_at' => $at,
                'category_account_id' => $input['categoryAccountId'],
                'description' => $description,
                'amount_minor' => $input['amount'],
                'payment_account_id' => $input['paymentAccountId'],
                'reference' => $input['reference'] ?? null,
                'note' => $input['note'] ?? null,
                'status' => 'POSTED',
                'created_by' => $actor->id,
                'is_demo' => $input['isDemo'] ?? false,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $posted = self::postEntry($direction, $id, $documentNo, $input, $categoryName, $account, $occurredAt, $actor, $input['isDemo'] ?? false);
            DB::table($side['table'])->where('id', $id)->update(['journal_entry_id' => $posted['entryId'], 'updated_at' => $at]);

            AuditService::write(AuditAction::Create, $side['entity'], "{$documentNo}: {$categoryName} — {$description}", $id, $actor, [
                'amountMinor' => $input['amount'],
                'paymentAccount' => $account->name,
                'entryNo' => $posted['entryNo'],
            ], $occurredAt);

            return ['id' => $id, 'documentNo' => $documentNo, 'journalEntryId' => $posted['entryId']];
        });
    }

    /** @return array{id: int, documentNo: string, journalEntryId: int} */
    public static function recordExpense(array $input, Actor $actor): array
    {
        return self::record('EXPENSE', $input, $actor);
    }

    /**
     * Several expenses at once (the record-expenses page): each keeps its own
     * number and journal entry, and all are saved or none. Keys are the
     * screen's row indexes, so a refusal names the row.
     *
     * @param  array<int, array<string, mixed>>  $inputs
     * @return list<array{id: int, documentNo: string, journalEntryId: int}>
     */
    public static function recordExpenses(array $inputs, Actor $actor): array
    {
        return Tx::run(function () use ($inputs, $actor): array {
            $saved = [];
            foreach ($inputs as $row => $input) {
                try {
                    $saved[] = self::record('EXPENSE', $input, $actor);
                } catch (DomainError $e) {
                    throw new ValidationError('Row '.($row + 1).': '.$e->userMessage);
                }
            }

            return $saved;
        });
    }

    /** @return array{id: int, documentNo: string, journalEntryId: int} */
    public static function recordIncome(array $input, Actor $actor): array
    {
        return self::record('INCOME', $input, $actor);
    }

    /**
     * The one journal entry a cashbook record posts. Shared by record and
     * edit, so an edited record posts exactly what a new one would.
     *
     * @return array{entryId: int, entryNo: string}
     */
    private static function postEntry(string $direction, int $id, string $documentNo, array $input, string $categoryName, object $account, Carbon $occurredAt, Actor $actor, bool $isDemo): array
    {
        $side = self::SIDES[$direction];
        $description = JsCompat::trim($input['description']);
        $category = fn (string $method) => Journal::$method($input['categoryAccountId'], $input['amount'], ['description' => "{$documentNo} {$categoryName}"]);
        $money = fn (string $method, string $text) => Journal::$method((int) $account->gl_account_id, $input['amount'], [
            'paymentAccountId' => (int) $input['paymentAccountId'],
            'description' => $text,
        ]);
        $lines = $direction === 'EXPENSE'
            ? [$category('debit'), $money('credit', "{$documentNo} paid from {$account->name}")]
            : [$money('debit', "{$documentNo} received into {$account->name}"), $category('credit')];

        return JournalService::post(
            $input['businessDate'],
            $side['source'],
            $lines,
            sourceId: $id,
            memo: "{$documentNo} — {$description}",
            isDemo: $isDemo,
            occurredAt: $occurredAt,
            actor: $actor,
        );
    }

    /**
     * Owner decision D34: correct a saved expense or income in place. Its
     * entry is reversed on the day it was recorded and the corrected one
     * posted, under the same number; what it said before is kept in the
     * audit log. Input: as recordExpense / recordIncome.
     */
    private static function editRecord(string $direction, int $id, array $input, string $why, Actor $actor, ?Carbon $now): void
    {
        $side = self::SIDES[$direction];
        RoleGuard::assertActorIsOwner($actor, 'edit a saved '.strtolower($side['name']));
        if ($input['amount'] <= 0) {
            throw new ValidationError('Enter an amount greater than zero.');
        }
        if (JsCompat::trim($input['description'] ?? '') === '') {
            throw new ValidationError('Enter a short description.');
        }
        $why = DocumentEdit::reason($why, 'record');
        $now ??= Clock::now();

        Tx::run(function () use ($direction, $side, $id, $input, $why, $actor, $now): void {
            $row = DB::table($side['table'])->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundError($side['name'], $id);
            $documentNo = $row->{$side['no']};
            if ($row->status === 'VOIDED') {
                throw new ConflictError("{$documentNo} has been voided, so it cannot be edited.");
            }
            $categoryName = self::assertCategoryUsable($input['categoryAccountId'], $direction, (int) $row->category_account_id);
            // The accounts the reversal and the new entry move, in one ordered lock.
            $accounts = PaymentAccountService::lockAccounts([(int) $row->payment_account_id, (int) $input['paymentAccountId']]);
            $account = $accounts[$input['paymentAccountId']] ?? throw new NotFoundError('Payment account', $input['paymentAccountId']);
            if (! $account->is_active) {
                throw new ValidationError("Payment account \"{$account->name}\" is not active.");
            }
            // The reversal puts money back into the old account, which may be archived since.
            PaymentAccountService::lockAccountsOfEntry($row->journal_entry_id);
            BusinessDate::assertNotFuture($input['businessDate'], Clock::dateOf($now), strtolower($side['name']));
            DocumentEdit::assertDaysOpen((string) $row->business_date, $input['businessDate']);

            $before = self::snapshot($direction, $row);
            // No stock and no line rows: only the entry comes back out.
            DocumentEdit::reverseAndClear($side['source'], $row->journal_entry_id === null ? null : (int) $row->journal_entry_id, (string) $row->business_date, $id, $documentNo, $why, 0, (bool) $row->is_demo, 'id', [], $now, $actor);
            $posted = self::postEntry($direction, $id, $documentNo, $input, $categoryName, $account, $now, $actor, (bool) $row->is_demo);
            $at = Clock::stamp($now);
            DB::table($side['table'])->where('id', $id)->update([
                'business_date' => $input['businessDate'],
                'category_account_id' => $input['categoryAccountId'],
                'description' => JsCompat::trim($input['description']),
                'amount_minor' => $input['amount'],
                'payment_account_id' => $input['paymentAccountId'],
                'reference' => $input['reference'] ?? null,
                'note' => $input['note'] ?? null,
                'journal_entry_id' => $posted['entryId'],
                'edited_at' => $at,
                'updated_at' => $at,
            ]);
            DocumentEdit::record($side['entity'], $id, $documentNo, $why, $before, self::snapshot($direction, DB::table($side['table'])->where('id', $id)->first()), [], $actor, $now);
        });
    }

    public static function editExpense(int $id, array $input, string $why, Actor $actor, ?Carbon $now = null): void
    {
        self::editRecord('EXPENSE', $id, $input, $why, $actor, $now);
    }

    public static function editIncome(int $id, array $input, string $why, Actor $actor, ?Carbon $now = null): void
    {
        self::editRecord('INCOME', $id, $input, $why, $actor, $now);
    }

    /** What a record says, in the edit history's shape (see StockAdjustmentService::snapshot). */
    private static function snapshot(string $direction, object $row): array
    {
        return [
            'date' => (string) $row->business_date,
            'fields' => [
                'Category' => DB::table('accounts')->where('id', $row->category_account_id)->value('name'),
                'Description' => $row->description,
                ($direction === 'EXPENSE' ? 'Paid from' : 'Received into') => DB::table('payment_accounts')->where('id', $row->payment_account_id)->value('name'),
                'Reference' => $row->reference,
                'Note' => $row->note,
            ],
            'lines' => [],
            'total' => (int) $row->amount_minor,
        ];
    }

    /** Void either kind by a reversing entry dated the day of the void. The original row is kept. */
    private static function voidRecord(string $direction, int $id, string $reason, Actor $actor, ?Carbon $now): void
    {
        $why = JsCompat::trim($reason);
        if (mb_strlen($why) < 3) {
            throw new ValidationError('Give a reason for voiding this record.');
        }
        $now ??= Clock::now();
        $side = self::SIDES[$direction] ?? self::OWNER_SIDES[$direction];

        Tx::run(function () use ($side, $id, $why, $actor, $now): void {
            $row = DB::table($side['table'])->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundError($side['name'], $id);
            if ($row->status === 'VOIDED') {
                throw new ConflictError('That record has already been voided.');
            }
            // Tx 3a: the accounts the reversal moves, shared, so archiving or counting one waits.
            PaymentAccountService::lockAccountsOfEntry($row->journal_entry_id);
            $documentNo = $row->{$side['no']};

            if ($row->journal_entry_id !== null) {
                JournalService::reverse(
                    (int) $row->journal_entry_id,
                    Clock::dateOf($now),
                    $side['source'],
                    $id,
                    "Void of {$documentNo}: {$why}",
                    occurredAt: $now,
                    actor: $actor,
                );
            }

            DB::table($side['table'])->where('id', $id)->update([
                'status' => 'VOIDED',
                'voided_at' => Clock::stamp($now),
                'void_reason' => $why,
                'updated_at' => Clock::stamp($now),
            ]);
            AuditService::write(AuditAction::Void, $side['entity'], "Voided {$documentNo}", $id, $actor, ['reason' => $why], $now);
        });
    }

    public static function voidExpense(int $id, string $reason, Actor $actor, ?Carbon $now = null): void
    {
        self::voidRecord('EXPENSE', $id, $reason, $actor, $now);
    }

    public static function voidIncome(int $id, string $reason, Actor $actor, ?Carbon $now = null): void
    {
        self::voidRecord('INCOME', $id, $reason, $actor, $now);
    }

    // --- owner capital and drawings -----------------------------------------

    /**
     * The owner putting money in (Dr account, Cr Owner's Capital, an opening
     * entry) or taking it out (Dr Owner's Drawings, Cr account). Drawings are
     * not an expense: they reduce the owner's stake, not the profit.
     *
     * The movement is numbered from the INCOME sequence for capital and the
     * EXPENSE sequence for drawings, as the old app did (PLAN §10: kept).
     *
     * Input: ['businessDate', 'paymentAccountId', 'amount', 'description'?,
     * 'occurredAt'? => Carbon, 'isDemo'?].
     *
     * @param  'CAPITAL'|'DRAWINGS'  $direction
     * @return array{id: int, documentNo: string, journalEntryId: int}
     */
    private static function recordOwnerMovement(string $direction, array $input, Actor $actor): array
    {
        if ($input['amount'] <= 0) {
            throw new ValidationError('Enter an amount greater than zero.');
        }

        return Tx::run(function () use ($direction, $input, $actor): array {
            $occurredAt = $input['occurredAt'] ?? Clock::now();
            BusinessDate::assertNotFuture($input['businessDate'], Clock::dateOf($occurredAt), $direction === 'CAPITAL' ? 'capital payment' : 'drawing');
            $at = Clock::stamp($occurredAt);
            $account = PaymentAccountService::lockAccounts([$input['paymentAccountId']])[$input['paymentAccountId']] ?? throw new NotFoundError('Payment account', $input['paymentAccountId']);
            // An archived account is hidden from "Total held", counts and transfers: money put in or taken out
            // through it would be out of sight (the old app did not check this).
            if (! $account->is_active) {
                throw new ValidationError("Payment account \"{$account->name}\" is archived. Restore it, or choose another account.");
            }

            $isCapital = $direction === 'CAPITAL';
            $equityId = JournalService::accountIdByCode($isCapital ? ChartOfAccounts::OWNERS_CAPITAL : ChartOfAccounts::OWNERS_DRAWINGS);
            $given = JsCompat::trim($input['description'] ?? '');
            $description = $given !== '' ? $given : ($isCapital ? 'Owner put money into the business' : 'Owner took money out');

            $money = fn (string $method) => Journal::$method((int) $account->gl_account_id, $input['amount'], ['paymentAccountId' => (int) $input['paymentAccountId'], 'description' => $description]);
            $equity = fn (string $method) => Journal::$method($equityId, $input['amount'], ['description' => $description]);
            $lines = $isCapital ? [$money('debit'), $equity('credit')] : [$equity('debit'), $money('credit')];

            JournalService::readLockDate();
            $movementNo = SequenceService::next($isCapital ? DocType::Income : DocType::Expense);
            $id = (int) DB::table('owner_movements')->insertGetId([
                'movement_no' => $movementNo,
                'kind' => $direction,
                'business_date' => $input['businessDate'],
                'occurred_at' => $at,
                'payment_account_id' => $input['paymentAccountId'],
                'amount_minor' => $input['amount'],
                'description' => $description,
                'status' => 'POSTED',
                'created_by' => $actor->id,
                'is_demo' => $input['isDemo'] ?? false,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $posted = JournalService::post(
                $input['businessDate'],
                $isCapital ? JournalSourceType::Capital : JournalSourceType::Drawings,
                $lines,
                sourceId: $id,
                memo: $description,
                isOpening: $isCapital,
                isDemo: $input['isDemo'] ?? false,
                occurredAt: $occurredAt,
                actor: $actor,
            );
            DB::table('owner_movements')->where('id', $id)->update(['journal_entry_id' => $posted['entryId'], 'updated_at' => $at]);

            AuditService::write(AuditAction::Create, $isCapital ? 'owner_capital' : 'owner_drawings', "{$description} — {$account->name}", $id, $actor, [
                'amountMinor' => $input['amount'],
                'entryNo' => $posted['entryNo'],
            ], $occurredAt);

            return ['id' => $id, 'documentNo' => $movementNo, 'journalEntryId' => $posted['entryId']];
        });
    }

    /** @return array{id: int, documentNo: string, journalEntryId: int} */
    public static function recordOwnerCapital(array $input, Actor $actor): array
    {
        return self::recordOwnerMovement('CAPITAL', $input, $actor);
    }

    /** @return array{id: int, documentNo: string, journalEntryId: int} */
    public static function recordOwnerDrawings(array $input, Actor $actor): array
    {
        return self::recordOwnerMovement('DRAWINGS', $input, $actor);
    }

    /**
     * A mistyped capital or drawings voided like an expense: a reversing entry
     * dated today, the record kept. Without it, the only fix was an opposite
     * entry, which leaves Owner's Capital and Retained Earnings both wrong.
     */
    public static function voidOwnerMovement(int $id, string $reason, Actor $actor, ?Carbon $now = null): void
    {
        $kind = DB::table('owner_movements')->where('owner_movements.id', $id)->value('kind') ?? throw new NotFoundError('Owner money', $id);
        self::voidRecord((string) $kind, $id, $reason, $actor, $now);
    }

    /**
     * The newest owner money, for voiding a mistake in its row.
     *
     * @return list<array{id: int, movementNo: string, kind: string, businessDate: string, accountName: string, amountMinor: int, description: string, status: string, voidReason: ?string}>
     */
    public static function listOwnerMovements(int $limit = 20): array
    {
        return DB::table('owner_movements')->join('payment_accounts', 'payment_accounts.id', '=', 'owner_movements.payment_account_id')
            ->orderByDesc('owner_movements.business_date')->orderByDesc('owner_movements.id')->limit($limit)
            ->get(['owner_movements.id', 'owner_movements.movement_no', 'owner_movements.kind', 'owner_movements.business_date', 'payment_accounts.name AS account_name',
                'owner_movements.amount_minor', 'owner_movements.description', 'owner_movements.status', 'owner_movements.void_reason'])
            ->map(fn (object $r): array => [
                'id' => (int) $r->id, 'movementNo' => $r->movement_no, 'kind' => $r->kind, 'businessDate' => (string) $r->business_date,
                'accountName' => $r->account_name, 'amountMinor' => (int) $r->amount_minor, 'description' => $r->description,
                'status' => $r->status, 'voidReason' => $r->void_reason,
            ])->all();
    }

    // --- reads --------------------------------------------------------------

    /**
     * One filter for both tables, so a filter cannot work for expenses and
     * quietly do nothing for income.
     *
     * Query: ['from'?, 'to'?, 'categoryAccountId'?, 'paymentAccountId'?, 'staffId'?,
     * 'status'?, 'search'?, 'minAmount'?, 'maxAmount'?, 'sort'?, 'direction'?, 'limit'?, 'offset'?].
     */
    private static function applyConditions(Builder $q, string $t, array $query): void
    {
        if (isset($query['from'])) {
            $q->where("{$t}.business_date", '>=', $query['from']);
        }
        if (isset($query['to'])) {
            $q->where("{$t}.business_date", '<=', $query['to']);
        }
        foreach (['categoryAccountId' => 'category_account_id', 'paymentAccountId' => 'payment_account_id', 'staffId' => 'created_by', 'status' => 'status'] as $key => $column) {
            if (isset($query[$key])) {
                $q->where("{$t}.{$column}", $query[$key]);
            }
        }
        if (isset($query['minAmount'])) {
            $q->where("{$t}.amount_minor", '>=', $query['minAmount']);
        }
        if (isset($query['maxAmount'])) {
            $q->where("{$t}.amount_minor", '<=', $query['maxAmount']);
        }
        $search = JsCompat::trim($query['search'] ?? '');
        if ($search !== '') {
            $term = CatalogService::containsPattern(mb_strtolower($search));
            $q->whereRaw("(
                LOWER(`{$t}`.`description`) LIKE ?
                OR LOWER(COALESCE(`{$t}`.`reference`, '')) LIKE ?
                OR LOWER(COALESCE(`{$t}`.`note`, '')) LIKE ?
            )", [$term, $term, $term]);
        }
    }

    private static function applyOrder(Builder $q, string $t, array $query): void
    {
        $dir = ($query['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        match (in_array($query['sort'] ?? 'date', self::CASHBOOK_SORTS, true) ? ($query['sort'] ?? 'date') : 'date') {
            'amount' => $q->orderByRaw("`{$t}`.`amount_minor` {$dir}")->orderByRaw("`{$t}`.`id` DESC"),
            'category' => $q->orderByRaw("LOWER(`accounts`.`name`) {$dir}")->orderByRaw("`{$t}`.`id` DESC"),
            'reference' => $q->orderByRaw("COALESCE(`{$t}`.`reference`, '') {$dir}")->orderByRaw("`{$t}`.`id` DESC"),
            default => $q->orderByRaw("`{$t}`.`business_date` {$dir}")->orderByRaw("`{$t}`.`id` {$dir}"),
        };
    }

    /** @return list<array> */
    private static function listCashbook(string $direction, array $query): array
    {
        $side = self::SIDES[$direction];
        $t = $side['table'];
        $q = DB::table($t)
            ->join('accounts', 'accounts.id', '=', "{$t}.category_account_id")
            ->join('payment_accounts', 'payment_accounts.id', '=', "{$t}.payment_account_id");
        self::applyConditions($q, $t, $query);
        self::applyOrder($q, $t, $query);

        return $q->limit(min($query['limit'] ?? 200, Filters::MAX_LIST_ROWS))->offset($query['offset'] ?? 0)->get([
            "{$t}.id", "{$t}.{$side['no']} AS document_no", "{$t}.business_date", "{$t}.occurred_at", "{$t}.description",
            "{$t}.amount_minor", 'accounts.name AS category_name', "{$t}.category_account_id", "{$t}.payment_account_id",
            'payment_accounts.name AS payment_account_name', "{$t}.reference", "{$t}.note", "{$t}.status", "{$t}.created_by", "{$t}.edited_at",
        ])->map(Row::camel(...))->all();
    }

    /** @return list<array> */
    public static function listExpenses(array $query = []): array
    {
        return self::listCashbook('EXPENSE', $query);
    }

    /** @return list<array> */
    public static function listIncomes(array $query = []): array
    {
        return self::listCashbook('INCOME', $query);
    }

    private static function countCashbook(string $direction, array $query): int
    {
        $t = self::SIDES[$direction]['table'];
        $q = DB::table($t);
        self::applyConditions($q, $t, $query);

        return $q->count();
    }

    public static function countExpenses(array $query = []): int
    {
        return self::countCashbook('EXPENSE', $query);
    }

    public static function countIncomes(array $query = []): int
    {
        return self::countCashbook('INCOME', $query);
    }

    /**
     * Totals for exactly the rows a filter selects, voided rows left out of
     * count, total and average alike: a voided expense is not money that left.
     *
     * @return array{count: int, total: int, average: int}
     */
    private static function summarise(string $direction, array $query): array
    {
        $t = self::SIDES[$direction]['table'];
        $q = DB::table($t);
        self::applyConditions($q, $t, $query);
        $row = $q->where("{$t}.status", 'POSTED')->selectRaw("COUNT(*) AS cnt, COALESCE(SUM(`{$t}`.`amount_minor`), 0) AS total")->first();

        return ['count' => (int) $row->cnt, 'total' => (int) $row->total, 'average' => Money::average((int) $row->total, (int) $row->cnt)];
    }

    /** @return array{count: int, total: int, average: int} */
    public static function getFilteredExpensesSummary(array $query = []): array
    {
        return self::summarise('EXPENSE', $query);
    }

    /** @return array{count: int, total: int, average: int} */
    public static function getFilteredIncomesSummary(array $query = []): array
    {
        return self::summarise('INCOME', $query);
    }

    private static function postedBetween(string $table, string $from, string $to): Builder
    {
        return DB::table($table)
            ->where("{$table}.business_date", '>=', $from)
            ->where("{$table}.business_date", '<=', $to)
            ->where("{$table}.status", 'POSTED');
    }

    public static function getExpensesTotal(string $from, string $to): int
    {
        return (int) self::postedBetween('expenses', $from, $to)->sum('expenses.amount_minor');
    }

    /** The same posted rows and inclusive dates as getExpensesTotal, per day. @return list<array{businessDate: string, total: int}> */
    public static function getExpensesByDay(string $from, string $to): array
    {
        return self::postedBetween('expenses', $from, $to)
            ->groupBy('expenses.business_date')->orderBy('expenses.business_date')
            ->selectRaw('`expenses`.`business_date` AS business_date, COALESCE(SUM(`expenses`.`amount_minor`), 0) AS total')
            ->get()->map(fn (object $r): array => ['businessDate' => $r->business_date, 'total' => (int) $r->total])->all();
    }

    /** Spending per category, biggest first. @return list<array{categoryAccountId: int, categoryName: string, total: int, count: int}> */
    private static function byCategory(Builder $q): array
    {
        return $q->join('accounts', 'accounts.id', '=', 'expenses.category_account_id')
            ->groupBy('expenses.category_account_id', 'accounts.name')
            ->orderByRaw('SUM(`expenses`.`amount_minor`) DESC')
            ->selectRaw('`expenses`.`category_account_id` AS category_account_id, `accounts`.`name` AS category_name,
                COALESCE(SUM(`expenses`.`amount_minor`), 0) AS total, COUNT(*) AS cnt')
            ->get()->map(fn (object $r): array => [
                'categoryAccountId' => (int) $r->category_account_id,
                'categoryName' => $r->category_name,
                'total' => (int) $r->total,
                'count' => (int) $r->cnt,
            ])->all();
    }

    /** The category split for exactly the expenses a filter selects. @return list<array> */
    public static function getFilteredExpensesByCategory(array $query = []): array
    {
        $q = DB::table('expenses');
        self::applyConditions($q, 'expenses', $query);

        return self::byCategory($q->where('expenses.status', 'POSTED'));
    }

    /** @return list<array> */
    public static function getExpensesByCategory(string $from, string $to): array
    {
        return self::byCategory(self::postedBetween('expenses', $from, $to));
    }
}
