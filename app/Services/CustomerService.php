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
use App\Domain\Sales\SaleCalculator;
use App\Enums\AuditAction;
use App\Support\Clock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Customers and what they owe. A port of the old customer.service.ts.
 *
 * A balance is never stored: it is the Accounts Receivable journal lines
 * tagged with the customer, so the profile and the balance sheet read the same
 * number two ways.
 *
 * Input: ['name', 'phone'?, 'email'?, 'address'?, 'notes'?, 'creditLimit'? => ?int]
 * (null limit means none; 0 means no credit at all).
 */
final class CustomerService
{
    public const CUSTOMER_SORTS = ['name', 'balance'];

    /** A customer's receivable balance as SQL, correlated on the outer customers row. */
    private const BALANCE_SQL = '(SELECT COALESCE(SUM(`jl`.`debit_minor` - `jl`.`credit_minor`), 0)
        FROM `journal_lines` `jl` JOIN `accounts` `a` ON `a`.`id` = `jl`.`account_id`
        WHERE `jl`.`customer_id` = `customers`.`id` AND `a`.`code` = \''.ChartOfAccounts::ACCOUNTS_RECEIVABLE.'\')';

    /**
     * The customer page's "Total bought" and "Total paid later" over all time (D24): every posted
     * sale less every posted return, netted as the balance nets them (a voided sale and its void
     * mirror both drop out), and every posted payment. The old page summed only the 100 it listed.
     *
     * @return array{totalBought: int, totalPaid: int}
     */
    public static function getActivityTotals(int $customerId): array
    {
        return [
            'totalBought' => (int) DB::table('sales')->where('sales.customer_id', $customerId)->where('sales.status', 'POSTED')->where('sales.kind', '<>', 'VOID')->sum('sales.total_minor'),
            'totalPaid' => (int) DB::table('customer_payments')->where('customer_payments.customer_id', $customerId)->where('customer_payments.status', 'POSTED')->sum('customer_payments.amount_minor'),
        ];
    }

    /** What this customer owes right now (or at the end of $asAt): A/R debits less credits tagged to them. */
    public static function getCustomerBalance(int $customerId, ?string $asAt = null): int
    {
        $row = DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_lines.customer_id', $customerId)
            ->where('accounts.code', ChartOfAccounts::ACCOUNTS_RECEIVABLE)
            ->when($asAt !== null, fn ($q) => $q->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')->where('journal_entries.entry_date', '<=', $asAt))
            ->selectRaw('COALESCE(SUM(`journal_lines`.`debit_minor`), 0) AS d, COALESCE(SUM(`journal_lines`.`credit_minor`), 0) AS c')
            ->first();

        return Money::subtract((int) $row->d, (int) $row->c);
    }

    /**
     * @param  list<int>|null  $customerIds  only these customers (a page of them); null for everyone
     * @return array<int, int> every customer's balance, by id
     */
    public static function getAllCustomerBalances(?array $customerIds = null): array
    {
        return DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', ChartOfAccounts::ACCOUNTS_RECEIVABLE)
            ->whereNotNull('journal_lines.customer_id')
            ->when($customerIds !== null, fn (Builder $q) => $q->whereIn('journal_lines.customer_id', $customerIds))
            ->groupBy('journal_lines.customer_id')
            ->selectRaw('`journal_lines`.`customer_id` AS id, COALESCE(SUM(`journal_lines`.`debit_minor`), 0) - COALESCE(SUM(`journal_lines`.`credit_minor`), 0) AS b')
            ->pluck('b', 'id')
            ->map(fn ($b): int => (int) $b)
            ->all();
    }

    /** Owed to the shop by everyone: the receivables figure. */
    public static function getTotalReceivables(): int
    {
        return Money::sum(self::getAllCustomerBalances());
    }

    /**
     * Lock order (Tx): the customer row, after any products and before any
     * document, so a credit limit, overpayment or refund cap is read from a
     * balance no other till can move until this one commits.
     */
    public static function lockCustomer(int $customerId): ?object
    {
        return DB::table('customers')->where('id', $customerId)->lockForUpdate()->first();
    }

    public static function createCustomer(array $input, Actor $actor): int
    {
        $name = JsCompat::trim($input['name'] ?? '');
        if ($name === '') {
            throw new ValidationError('Enter the customer’s name.');
        }
        $limit = $input['creditLimit'] ?? null;
        if ($limit !== null && $limit < 0) {
            throw new ValidationError('A credit limit cannot be negative.');
        }

        return Tx::run(function () use ($input, $name, $limit, $actor): int {
            $now = Clock::now();
            $at = Clock::stamp($now);
            $id = (int) DB::table('customers')->insertGetId([
                'name' => $name,
                ...self::contact($input),
                'credit_limit_minor' => $limit,
                'is_active' => true,
                'created_by' => $actor->id,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            // An unset phone is left out, as JSON.stringify dropped undefined.
            AuditService::write(AuditAction::Create, 'customer', "Added customer \"{$name}\"", $id, $actor, [
                ...(isset($input['phone']) ? ['phone' => $input['phone']] : []),
                'creditLimitMinor' => $limit,
            ], $now);

            return $id;
        });
    }

    public static function updateCustomer(int $id, array $input, Actor $actor): void
    {
        $name = JsCompat::trim($input['name'] ?? '');
        if ($name === '') {
            throw new ValidationError('Enter the customer’s name.');
        }

        Tx::run(function () use ($id, $input, $name, $actor): void {
            $existing = self::lockCustomer($id) ?? throw new NotFoundError('Customer', $id);
            $now = Clock::now();
            DB::table('customers')->where('id', $id)->update([
                'name' => $name,
                ...self::contact($input),
                'credit_limit_minor' => $input['creditLimit'] ?? null,
                'updated_at' => Clock::stamp($now),
            ]);

            AuditService::write(AuditAction::Update, 'customer', "Updated customer \"{$name}\"", $id, $actor, [
                'before' => ['name' => $existing->name],
                'after' => ['name' => $name],
            ], $now);
        });
    }

    /**
     * Archived, never deleted: their sales and ledger lines must survive. And
     * refused while they owe, because an archived debtor is a forgotten debt.
     */
    public static function setCustomerActive(int $id, bool $isActive, Actor $actor): void
    {
        Tx::run(function () use ($id, $isActive, $actor): void {
            $existing = self::lockCustomer($id) ?? throw new NotFoundError('Customer', $id);
            if (! $isActive && self::getCustomerBalance($id) !== 0) {
                throw new ConflictError("{$existing->name} still has an outstanding balance. Settle it before archiving them.");
            }

            $now = Clock::now();
            DB::table('customers')->where('id', $id)->update(['is_active' => $isActive, 'updated_at' => Clock::stamp($now)]);
            AuditService::write(
                $isActive ? AuditAction::Restore : AuditAction::Archive,
                'customer',
                ($isActive ? 'Restored' : 'Archived')." customer \"{$existing->name}\"",
                $id,
                $actor,
                null,
                $now,
            );
        });
    }

    // --- reads --------------------------------------------------------------

    /**
     * Query keys: search, includeInactive, customerStatus ('active'|'archived'),
     * balanceState ('owing'|'zero'|'credit'), owingOnly, sort, direction, limit, offset.
     *
     * @return list<array>
     */
    public static function listCustomers(array $query = []): array
    {
        $q = DB::table('customers');
        self::applyConditions($q, $query);

        $ascending = ($query['direction'] ?? 'asc') === 'asc';
        if (($query['sort'] ?? null) === 'balance') {
            $q->orderByRaw(self::BALANCE_SQL.($ascending ? ' ASC' : ' DESC'))->orderByRaw('LOWER(`customers`.`name`) ASC');
        } else {
            $q->orderByRaw('LOWER(`customers`.`name`) '.($ascending ? 'ASC' : 'DESC'));
        }

        $rows = $q->limit(min($query['limit'] ?? 200, 500))->offset($query['offset'] ?? 0)->get(['customers.*']);
        $balances = self::getAllCustomerBalances($rows->map(fn (object $row): int => (int) $row->id)->all());

        return $rows->map(fn (object $row): array => self::item($row, $balances[(int) $row->id] ?? 0))->all();
    }

    /** How many customers match, ignoring the page. */
    public static function countCustomers(array $query = []): int
    {
        $q = DB::table('customers');
        self::applyConditions($q, $query);

        return $q->count();
    }

    /** @return list<array{id: int, name: string, isActive: bool}> */
    public static function listCustomerOptions(bool $includeInactive = false): array
    {
        $q = DB::table('customers');
        if (! $includeInactive) {
            $q->where('customers.is_active', true);
        }

        return $q->orderByRaw('LOWER(`customers`.`name`) ASC')->get(['id', 'name', 'is_active'])
            ->map(fn (object $r): array => ['id' => (int) $r->id, 'name' => $r->name, 'isActive' => (bool) $r->is_active])->all();
    }

    public static function getCustomer(int $id): array
    {
        $row = DB::table('customers')->where('id', $id)->first() ?? throw new NotFoundError('Customer', $id);

        return self::item($row, self::getCustomerBalance($id));
    }

    private static function item(object $row, int $balance): array
    {
        $limit = $row->credit_limit_minor === null ? null : (int) $row->credit_limit_minor;

        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'phone' => $row->phone,
            'email' => $row->email,
            'address' => $row->address,
            'notes' => $row->notes,
            'creditLimit' => $limit,
            'balance' => $balance,
            'headroom' => SaleCalculator::creditHeadroom($limit, $balance),
            'overLimit' => $limit !== null && $balance > $limit,
            'isActive' => (bool) $row->is_active,
        ];
    }

    private static function applyConditions(Builder $q, array $query): void
    {
        $status = $query['customerStatus'] ?? null;
        if ($status === 'active') {
            $q->where('customers.is_active', true);
        } elseif ($status === 'archived') {
            $q->where('customers.is_active', false);
        } elseif (! ($query['includeInactive'] ?? false)) {
            $q->where('customers.is_active', true);
        }

        $state = $query['balanceState'] ?? (($query['owingOnly'] ?? false) ? 'owing' : null);
        match ($state) {
            'owing' => $q->whereRaw(self::BALANCE_SQL.' > 0'),
            'zero' => $q->whereRaw(self::BALANCE_SQL.' = 0'),
            'credit' => $q->whereRaw(self::BALANCE_SQL.' < 0'),
            default => null,
        };

        $search = JsCompat::trim($query['search'] ?? '');
        if ($search !== '') {
            $term = CatalogService::containsPattern(mb_strtolower($search));
            $q->where(fn (Builder $w) => $w
                ->whereRaw('LOWER(`customers`.`name`) LIKE ?', [$term])
                ->orWhereRaw("LOWER(COALESCE(`customers`.`phone`, '')) LIKE ?", [$term])
                ->orWhereRaw("LOWER(COALESCE(`customers`.`email`, '')) LIKE ?", [$term]));
        }
    }

    /** Optional contact fields: trimmed, and empty becomes null, as `?.trim() || null` did. */
    private static function contact(array $input): array
    {
        $clean = fn (?string $v): ?string => ($v === null || JsCompat::trim($v) === '') ? null : JsCompat::trim($v);

        return [
            'phone' => $clean($input['phone'] ?? null),
            'email' => $clean($input['email'] ?? null),
            'address' => $clean($input['address'] ?? null),
            'notes' => $clean($input['notes'] ?? null),
        ];
    }
}
