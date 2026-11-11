<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Domain\JsCompat;
use App\Domain\Money;
use App\Enums\AccountType;
use App\Enums\AuditAction;
use App\Enums\PaymentAccountKind;
use App\Support\Clock;
use App\Support\Filters\Filters;
use App\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Payment accounts: the pots of money the owner thinks in ("Cash box", "MTN
 * MoMo", "GCB current account"). Each owns exactly one ledger leaf, so its
 * balance is a sum of journal lines and can never disagree with the books.
 * Expense and income categories are ledger leaves too, and are made here.
 * A port of the old payment-account.service.ts.
 *
 * Input for create/update: ['name', 'kind' => PaymentAccountKind, 'provider'?,
 * 'accountNumber'?, 'isDefault'?].
 */
final class PaymentAccountService
{
    /** Which heading a new account of each kind is filed under. */
    private const PARENT_BY_KIND = [
        'CASH' => ChartOfAccounts::CASH,
        'MOBILE_MONEY' => ChartOfAccounts::MOBILE_MONEY,
        'BANK' => ChartOfAccounts::BANK,
        'OTHER' => ChartOfAccounts::CASH,
    ];

    /**
     * Lock order (Tx): a heading in the chart, FOR UPDATE, before reading its
     * children, so two people adding an account (or category) under it at the
     * same moment take turns and get different codes.
     */
    private static function lockHeading(string $code): object
    {
        return DB::table('accounts')->where('code', $code)->lockForUpdate()->first() ?? throw new NotFoundError('Account', $code);
    }

    /** Next free code inside the heading's block of ten, e.g. 1011 -> 1012. Call with the heading locked. */
    private static function nextChildCode(object $parent): string
    {
        $used = DB::table('accounts')->where('accounts.parent_id', $parent->id)->pluck('code')->map(fn ($c): int => (int) $c)->all();
        $base = (int) $parent->code;
        $candidate = $base + 1;
        while (in_array($candidate, $used, true)) {
            $candidate++;
        }
        // Stay inside the heading's block (e.g. 1010-1019 for mobile money).
        if ($candidate >= $base + 10) {
            throw new ConflictError('There is no room for another account under this heading. Archive one you no longer use.');
        }

        return (string) $candidate;
    }

    public static function createPaymentAccount(array $input, Actor $actor): int
    {
        $name = JsCompat::trim($input['name'] ?? '');
        if ($name === '') {
            throw new ValidationError('Enter a name for the account.');
        }
        $kind = $input['kind'] instanceof PaymentAccountKind ? $input['kind'] : PaymentAccountKind::from($input['kind']);

        return Tx::run(function () use ($input, $name, $kind, $actor): int {
            $parent = self::lockHeading(self::PARENT_BY_KIND[$kind->value]);

            if (DB::table('payment_accounts')->whereRaw('LOWER(`payment_accounts`.`name`) = LOWER(?)', [$name])->exists()) {
                throw new ConflictError("An account called \"{$name}\" already exists.");
            }

            $now = Clock::now();
            $at = Clock::stamp($now);
            $code = self::nextChildCode($parent);

            $glAccountId = (int) DB::table('accounts')->insertGetId([
                'code' => $code,
                'name' => $name,
                'type' => AccountType::Asset->value,
                'normal_balance' => 'DEBIT',
                'parent_id' => $parent->id,
                'description' => "Ledger account backing the \"{$name}\" payment account.",
                'is_system' => true,
                'is_active' => true,
                'sort_order' => (int) $code,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            // At most one default; setting a new one clears the old.
            if (($input['isDefault'] ?? false) === true) {
                DB::table('payment_accounts')->update(['is_default' => false, 'updated_at' => $at]);
            }

            $maxOrder = (int) DB::table('payment_accounts')->max('sort_order');
            $id = (int) DB::table('payment_accounts')->insertGetId([
                'name' => $name,
                'kind' => $kind->value,
                'provider' => self::blankToNull($input['provider'] ?? null),
                'account_number' => self::blankToNull($input['accountNumber'] ?? null),
                'gl_account_id' => $glAccountId,
                'is_active' => true,
                'is_default' => ($input['isDefault'] ?? false) === true,
                'sort_order' => $maxOrder + 10,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $kindLabel = preg_replace('/_/', ' ', strtolower($kind->value), 1);
            // As JSON.stringify wrote it: a provider never given is left out, not null.
            $metadata = ['kind' => $kind->value] + (isset($input['provider']) ? ['provider' => $input['provider']] : []) + ['glCode' => $code];
            AuditService::write(AuditAction::Create, 'payment_account', "Added payment account \"{$name}\" ({$kindLabel})", $id, $actor, $metadata, $now);

            return $id;
        });
    }

    public static function updatePaymentAccount(int $id, array $input, Actor $actor): void
    {
        $name = JsCompat::trim($input['name'] ?? '');
        if ($name === '') {
            throw new ValidationError('Enter a name for the account.');
        }

        Tx::run(function () use ($id, $input, $name, $actor): void {
            $existing = DB::table('payment_accounts')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundError('Payment account', $id);

            if (DB::table('payment_accounts')->whereRaw('LOWER(`payment_accounts`.`name`) = LOWER(?)', [$name])->where('payment_accounts.id', '<>', $id)->exists()) {
                throw new ConflictError("An account called \"{$name}\" already exists.");
            }

            $now = Clock::now();
            $at = Clock::stamp($now);
            if (($input['isDefault'] ?? false) === true) {
                DB::table('payment_accounts')->update(['is_default' => false, 'updated_at' => $at]);
            }

            DB::table('payment_accounts')->where('id', $id)->update([
                'name' => $name,
                'provider' => self::blankToNull($input['provider'] ?? null),
                'account_number' => self::blankToNull($input['accountNumber'] ?? null),
                'is_default' => $input['isDefault'] ?? (bool) $existing->is_default,
                'updated_at' => $at,
            ]);
            // Keep the ledger account's name in step so reports read sensibly.
            DB::table('accounts')->where('id', $existing->gl_account_id)->update(['name' => $name, 'updated_at' => $at]);

            AuditService::write(AuditAction::Update, 'payment_account', "Updated payment account \"{$name}\"", $id, $actor, [
                'before' => ['name' => $existing->name],
                'after' => ['name' => $name],
            ], $now);
        });
    }

    /**
     * Archived, never deleted. Refused while money is still in the account,
     * because a hidden balance is how cash goes missing from a set of books.
     */
    public static function setPaymentAccountActive(int $id, bool $isActive, Actor $actor): void
    {
        Tx::run(function () use ($id, $isActive, $actor): void {
            // Locked, and the balance read after it: every writer that moves
            // money through an account share-locks it first (Tx 3a), so none
            // can slip money in between this check and the archive.
            $existing = DB::table('payment_accounts')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundError('Payment account', $id);

            if (! $isActive) {
                if (self::glBalance((int) $existing->gl_account_id) !== 0) {
                    throw new ConflictError("\"{$existing->name}\" still holds money. Move the balance out before archiving it.");
                }
                if ($existing->is_default) {
                    throw new ConflictError('Make another account the default before archiving this one.');
                }
            }

            $now = Clock::now();
            $at = Clock::stamp($now);
            DB::table('payment_accounts')->where('id', $id)->update(['is_active' => $isActive, 'updated_at' => $at]);
            DB::table('accounts')->where('id', $existing->gl_account_id)->update(['is_active' => $isActive, 'updated_at' => $at]);

            AuditService::write(
                $isActive ? AuditAction::Restore : AuditAction::Archive,
                'payment_account',
                ($isActive ? 'Restored' : 'Archived')." payment account \"{$existing->name}\"",
                $id,
                $actor,
                at: $now,
            );
        });
    }

    /**
     * Lock order (Tx): a payment account a cashbook document posts to, shared,
     * after any parties and documents and before the settings row. Ids
     * ascending when there are two (a transfer). Returns the rows by id.
     *
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    public static function lockAccounts(array $ids, bool $exclusive = false): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        $q = DB::table('payment_accounts')->whereIn('id', $ids)->orderBy('id');
        $exclusive ? $q->lockForUpdate() : $q->sharedLock();

        return $q->get()->keyBy('id')->all();
    }

    /**
     * Shared locks on the accounts a journal entry moved money through, taken
     * by a void before it reverses that entry (same place in the lock order).
     */
    public static function lockAccountsOfEntry(int|string|null $entryId): void
    {
        if ($entryId === null) {
            return;
        }
        self::lockAccounts(DB::table('payment_accounts')
            ->whereIn('payment_accounts.gl_account_id', DB::table('journal_lines')->where('journal_lines.entry_id', $entryId)->select('journal_lines.account_id'))
            ->pluck('payment_accounts.id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * Shared locks on the accounts tenders or refunds will post to: the ones
     * with an amount above zero, the only ones the writers read.
     *
     * @param  list<array{paymentAccountId: int, amount: int}>  $tenders
     * @return array<int, object>
     */
    public static function lockTenderAccounts(array $tenders): array
    {
        return self::lockAccounts(array_column(array_filter($tenders, fn (array $t): bool => $t['amount'] > 0), 'paymentAccountId'));
    }

    // --- reads --------------------------------------------------------------

    /** Debits less credits on one ledger account, to a trading date when given. */
    private static function glBalance(int $glAccountId, ?string $upTo = null): int
    {
        $q = DB::table('journal_lines')->where('journal_lines.account_id', $glAccountId);
        if ($upTo !== null) {
            $q->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')->where('journal_entries.entry_date', '<=', $upTo);
        }
        $row = $q->selectRaw('COALESCE(SUM(`journal_lines`.`debit_minor`), 0) AS d, COALESCE(SUM(`journal_lines`.`credit_minor`), 0) AS c')->first();

        // Asset account: debits increase, credits decrease.
        return Money::subtract((int) $row->d, (int) $row->c);
    }

    /** Balance of one payment account, derived from its ledger lines. */
    public static function getPaymentAccountBalance(int $id, ?string $upTo = null): int
    {
        $glId = DB::table('payment_accounts')->where('id', $id)->value('gl_account_id') ?? throw new NotFoundError('Payment account', $id);

        return self::glBalance((int) $glId, $upTo);
    }

    /** @return list<array> with every account's live balance, in one grouped query */
    public static function listPaymentAccounts(bool $includeInactive = false): array
    {
        return self::summaries($includeInactive ? null : fn (Builder $q) => $q->where('payment_accounts.is_active', true));
    }

    /**
     * Total money held: the balances of the ACTIVE accounts among those given
     * (the /accounts page passes listPaymentAccounts(true)), as the old page.
     */
    public static function getTotalHeld(array $accounts): int
    {
        return Money::sum(array_column(array_filter($accounts, fn (array $a): bool => $a['isActive']), 'balance'));
    }

    /** One account's summary, read on its own (no sweep of every account). */
    public static function getPaymentAccount(int $id): array
    {
        return self::summaries(fn (Builder $q) => $q->where('payment_accounts.id', $id))[0] ?? throw new NotFoundError('Payment account', $id);
    }

    /** @return list<array> */
    private static function summaries(?\Closure $narrow): array
    {
        $q = DB::table('payment_accounts')->join('accounts', 'accounts.id', '=', 'payment_accounts.gl_account_id');
        if ($narrow !== null) {
            $narrow($q);
        }
        $balances = DB::table('journal_lines')
            ->whereIn('journal_lines.account_id', (clone $q)->select('payment_accounts.gl_account_id'))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('`journal_lines`.`account_id` AS account_id, SUM(`journal_lines`.`debit_minor`) - SUM(`journal_lines`.`credit_minor`) AS balance')
            ->pluck('balance', 'account_id');

        return $q->orderBy('payment_accounts.sort_order')->get([
            'payment_accounts.id', 'payment_accounts.name', 'payment_accounts.kind', 'payment_accounts.provider',
            'payment_accounts.account_number', 'accounts.code AS gl_code', 'payment_accounts.gl_account_id',
            'payment_accounts.is_active', 'payment_accounts.is_default',
        ])->map(fn (object $row): array => [
            ...Row::camel($row),
            'balance' => (int) ($balances[$row->gl_account_id] ?? 0),
            'isDefault' => (bool) $row->is_default,
        ])->all();
    }

    /** Just enough to fill a dropdown: no balances. @return list<array{id: int, name: string, kind: string, isActive: bool}> */
    public static function listPaymentAccountOptions(bool $includeInactive = false): array
    {
        $q = DB::table('payment_accounts')->orderBy('payment_accounts.sort_order');
        if (! $includeInactive) {
            $q->where('payment_accounts.is_active', true);
        }

        return $q->get(['payment_accounts.id', 'payment_accounts.name', 'payment_accounts.kind', 'payment_accounts.is_active'])->map(Row::camel(...))->all();
    }

    private static function glAccountOf(int $id): int
    {
        return (int) (DB::table('payment_accounts')->where('id', $id)->value('gl_account_id') ?? throw new NotFoundError('Payment account', $id));
    }

    /**
     * Query: ['from'?, 'to'?, 'sourceType'?, 'flow'? => 'in'|'out', 'search'?,
     * 'minAmount'?, 'maxAmount'?, 'limit'?, 'offset'?].
     */
    private static function movementConditions(Builder $q, int $glAccountId, array $query): void
    {
        $q->where('journal_lines.account_id', $glAccountId);
        if (isset($query['from'])) {
            $q->where('journal_entries.entry_date', '>=', $query['from']);
        }
        if (isset($query['to'])) {
            $q->where('journal_entries.entry_date', '<=', $query['to']);
        }
        if (isset($query['sourceType'])) {
            $q->where('journal_entries.source_type', $query['sourceType']);
        }
        if (($query['flow'] ?? null) === 'in') {
            $q->where('journal_lines.debit_minor', '>', 0);
        }
        if (($query['flow'] ?? null) === 'out') {
            $q->where('journal_lines.credit_minor', '>', 0);
        }
        if (isset($query['minAmount'])) {
            $q->whereRaw('(`journal_lines`.`debit_minor` + `journal_lines`.`credit_minor`) >= ?', [$query['minAmount']]);
        }
        if (isset($query['maxAmount'])) {
            $q->whereRaw('(`journal_lines`.`debit_minor` + `journal_lines`.`credit_minor`) <= ?', [$query['maxAmount']]);
        }
        $search = JsCompat::trim($query['search'] ?? '');
        if ($search !== '') {
            $term = CatalogService::containsPattern(mb_strtolower($search));
            $q->whereRaw("(
                LOWER(`journal_entries`.`entry_no`) LIKE ?
                OR LOWER(COALESCE(`journal_entries`.`memo`, '')) LIKE ?
                OR LOWER(COALESCE(`journal_lines`.`description`, '')) LIKE ?
            )", [$term, $term, $term]);
        }
    }

    private static function movementBase(int $glAccountId, array $query): Builder
    {
        $q = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id');
        self::movementConditions($q, $glAccountId, $query);

        return $q;
    }

    /**
     * One account's statement: what it held before the window (from every
     * earlier entry, not worked back from today), the money in and out, the
     * closing figure, and the movements newest first with a running balance
     * computed over the whole filtered window before the page limit, so page
     * two carries on from page one.
     *
     * @return array{opening: int, moneyIn: int, moneyOut: int, closing: int, movements: list<array>, total: int}
     */
    public static function getAccountStatement(int $id, array $query = []): array
    {
        $glId = self::glAccountOf($id);

        // Only the account and the start date: an opening balance is what the
        // account HELD, not a subtotal of what a search box matches. No start
        // date means the window opens at the beginning of the records.
        $opening = 0;
        if (isset($query['from'])) {
            $opening = (int) DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
                ->where('journal_lines.account_id', $glId)
                ->where('journal_entries.entry_date', '<', $query['from'])
                ->selectRaw('COALESCE(SUM(`journal_lines`.`debit_minor` - `journal_lines`.`credit_minor`), 0) AS net')->value('net');
        }

        $totals = self::movementBase($glId, $query)->selectRaw('COUNT(*) AS cnt,
            COALESCE(SUM(`journal_lines`.`debit_minor`), 0) AS money_in,
            COALESCE(SUM(`journal_lines`.`credit_minor`), 0) AS money_out')->first();

        // Accumulated by trading date, then entry, then line: the exact mirror
        // of the display order below, or the column stops reading down the page.
        $rows = self::movementBase($glId, $query)
            ->selectRaw('`journal_entries`.`id` AS entry_id, `journal_entries`.`entry_no`, `journal_entries`.`entry_date`,
                `journal_entries`.`occurred_at`, `journal_entries`.`source_type`, `journal_entries`.`source_id`,
                `journal_entries`.`memo`, `journal_lines`.`description`,
                `journal_lines`.`debit_minor` AS in_minor, `journal_lines`.`credit_minor` AS out_minor,
                ? + SUM(`journal_lines`.`debit_minor` - `journal_lines`.`credit_minor`)
                    OVER (ORDER BY `journal_entries`.`entry_date` ASC, `journal_entries`.`id` ASC, `journal_lines`.`id` ASC
                          ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running_balance', [$opening])
            ->orderByDesc('journal_entries.entry_date')->orderByDesc('journal_entries.id')->orderByDesc('journal_lines.id')
            ->limit(min($query['limit'] ?? 200, Filters::MAX_LIST_ROWS))->offset($query['offset'] ?? 0)
            ->get();

        $moneyIn = (int) $totals->money_in;
        $moneyOut = (int) $totals->money_out;

        return [
            'opening' => $opening,
            'moneyIn' => $moneyIn,
            'moneyOut' => $moneyOut,
            'closing' => Money::subtract(Money::add($opening, $moneyIn), $moneyOut),
            'total' => (int) $totals->cnt,
            'movements' => $rows->map(fn (object $row): array => [
                'entryId' => (int) $row->entry_id,
                'entryNo' => $row->entry_no,
                'entryDate' => $row->entry_date,
                'occurredAt' => $row->occurred_at,
                'sourceType' => $row->source_type,
                'sourceId' => $row->source_id === null ? null : (int) $row->source_id,
                'memo' => $row->memo,
                'description' => $row->description,
                'inMinor' => (int) $row->in_minor,
                'outMinor' => (int) $row->out_minor,
                'runningBalance' => (int) $row->running_balance,
            ])->all(),
        ];
    }

    /** How many movements match, ignoring the page. */
    public static function countAccountMovements(int $id, array $query = []): int
    {
        return self::movementBase(self::glAccountOf($id), $query)->count();
    }

    /** The transaction kinds an account has actually seen, for the filter list. @return list<string> */
    public static function listAccountSourceTypes(int $id): array
    {
        return DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('journal_lines.account_id', self::glAccountOf($id))
            ->distinct()->orderBy('journal_entries.source_type')->pluck('journal_entries.source_type')->all();
    }

    // --- categories (which are accounts) ------------------------------------

    /** Active leaf accounts under a heading: what the owner picks as a category. @return list<array{id: int, code: string, name: string}> */
    private static function leafChildren(string $parentCode): array
    {
        $parentId = DB::table('accounts')->where('code', $parentCode)->value('id');
        if ($parentId === null) {
            return [];
        }

        return DB::table('accounts')->where('accounts.parent_id', $parentId)->where('accounts.is_active', true)
            ->orderBy('accounts.sort_order')->orderBy('accounts.name')
            ->get(['accounts.id', 'accounts.code', 'accounts.name'])->map(Row::camel(...))->all();
    }

    /** @return list<array{id: int, code: string, name: string}> */
    public static function listExpenseCategories(): array
    {
        return self::leafChildren(ChartOfAccounts::OPERATING_EXPENSES);
    }

    /** @return list<array{id: int, code: string, name: string}> */
    public static function listIncomeCategories(): array
    {
        return self::leafChildren(ChartOfAccounts::OTHER_INCOME);
    }

    /**
     * A category is an account: a child of Operating Expenses (6010-6899) or
     * Other Income (4210-4299), at the next free code in steps of ten.
     *
     * @param  'EXPENSE'|'INCOME'  $kind
     */
    public static function createCategory(string $kind, string $name, Actor $actor): int
    {
        $trimmed = JsCompat::trim($name);
        if ($trimmed === '') {
            throw new ValidationError('Enter a category name.');
        }
        $isExpense = $kind === 'EXPENSE';

        return Tx::run(function () use ($isExpense, $kind, $trimmed, $actor): int {
            $parent = self::lockHeading($isExpense ? ChartOfAccounts::OPERATING_EXPENSES : ChartOfAccounts::OTHER_INCOME);

            if (DB::table('accounts')->where('accounts.parent_id', $parent->id)->whereRaw('LOWER(`accounts`.`name`) = LOWER(?)', [$trimmed])->exists()) {
                throw new ConflictError("A category called \"{$trimmed}\" already exists.");
            }

            $used = DB::table('accounts')->where('accounts.parent_id', $parent->id)->pluck('code')->map(fn ($c): int => (int) $c)->all();
            $base = (int) $parent->code;
            $candidate = $base + 10;
            while (in_array($candidate, $used, true)) {
                $candidate += 10;
            }
            if ($candidate >= $base + ($isExpense ? 900 : 100)) {
                throw new ConflictError('There is no room for another category.');
            }

            $now = Clock::now();
            $at = Clock::stamp($now);
            $id = (int) DB::table('accounts')->insertGetId([
                'code' => (string) $candidate,
                'name' => $trimmed,
                'type' => $isExpense ? AccountType::Expense->value : AccountType::Revenue->value,
                'normal_balance' => $isExpense ? 'DEBIT' : 'CREDIT',
                'parent_id' => $parent->id,
                'description' => $isExpense ? "Operating expense: {$trimmed}." : "Other income: {$trimmed}.",
                'is_system' => false,
                'is_active' => true,
                'sort_order' => $candidate,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            AuditService::write(AuditAction::Create, 'category', 'Added '.strtolower($kind)." category \"{$trimmed}\"", $id, $actor, at: $now);

            return $id;
        });
    }

    private static function blankToNull(?string $value): ?string
    {
        $trimmed = JsCompat::trim($value ?? '');

        return $trimmed === '' ? null : $trimmed;
    }
}
