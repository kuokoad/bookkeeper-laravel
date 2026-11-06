<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
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
    private const SIDES = [
        'EXPENSE' => ['table' => 'expenses', 'no' => 'expense_no', 'doc' => DocType::Expense, 'source' => JournalSourceType::Expense, 'entity' => 'expense', 'name' => 'Expense'],
        'INCOME' => ['table' => 'incomes', 'no' => 'income_no', 'doc' => DocType::Income, 'source' => JournalSourceType::Income, 'entity' => 'income', 'name' => 'Income'],
    ];

    /**
     * The category must be a leaf account of the right type: an expense posted
     * to revenue, or to a heading, would quietly corrupt the Profit & Loss.
     */
    private static function assertCategoryUsable(int $accountId, string $direction): string
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
            $at = Clock::stamp($occurredAt);
            $categoryName = self::assertCategoryUsable($input['categoryAccountId'], $direction);

            $account = PaymentAccountService::lockAccounts([$input['paymentAccountId']])[$input['paymentAccountId']] ?? throw new NotFoundError('Payment account', $input['paymentAccountId']);
            if (! $account->is_active) {
                throw new ValidationError("Payment account \"{$account->name}\" is not active.");
            }

            $isExpense = $direction === 'EXPENSE';
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

            $category = fn (string $method) => Journal::$method($input['categoryAccountId'], $input['amount'], ['description' => "{$documentNo} {$categoryName}"]);
            $money = fn (string $method, string $text) => Journal::$method((int) $account->gl_account_id, $input['amount'], [
                'paymentAccountId' => (int) $input['paymentAccountId'],
                'description' => $text,
            ]);
            $lines = $isExpense
                ? [$category('debit'), $money('credit', "{$documentNo} paid from {$account->name}")]
                : [$money('debit', "{$documentNo} received into {$account->name}"), $category('credit')];

            $posted = JournalService::post(
                $input['businessDate'],
                $side['source'],
                $lines,
                sourceId: $id,
                memo: "{$documentNo} — {$description}",
                isDemo: $input['isDemo'] ?? false,
                occurredAt: $occurredAt,
                actor: $actor,
            );
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

    /** @return array{id: int, documentNo: string, journalEntryId: int} */
    public static function recordIncome(array $input, Actor $actor): array
    {
        return self::record('INCOME', $input, $actor);
    }

    /** Void either kind by a reversing entry dated the day of the void. The original row is kept. */
    private static function voidRecord(string $direction, int $id, string $reason, Actor $actor, ?Carbon $now): void
    {
        $why = JsCompat::trim($reason);
        if (mb_strlen($why) < 3) {
            throw new ValidationError('Give a reason for voiding this record.');
        }
        $now ??= Clock::now();
        $side = self::SIDES[$direction];

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
            $at = Clock::stamp($occurredAt);
            // Not refused when archived: the old app did not check it here.
            $account = PaymentAccountService::lockAccounts([$input['paymentAccountId']])[$input['paymentAccountId']] ?? throw new NotFoundError('Payment account', $input['paymentAccountId']);

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

        return $q->limit(min($query['limit'] ?? 200, 500))->offset($query['offset'] ?? 0)->get([
            "{$t}.id", "{$t}.{$side['no']} AS document_no", "{$t}.business_date", "{$t}.occurred_at", "{$t}.description",
            "{$t}.amount_minor", 'accounts.name AS category_name', "{$t}.category_account_id", "{$t}.payment_account_id",
            'payment_accounts.name AS payment_account_name', "{$t}.reference", "{$t}.status", "{$t}.created_by",
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
