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
use App\Enums\AuditAction;
use App\Support\Clock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Suppliers and what the shop owes them. A port of the old supplier.service.ts,
 * the mirror of CustomerService: no balance is stored, it is the Accounts
 * Payable journal lines tagged with the supplier (credits less debits).
 *
 * Input: ['name', 'contactPerson'?, 'phone'?, 'email'?, 'address'?, 'notes'?].
 */
final class SupplierService
{
    public const SUPPLIER_SORTS = ['name', 'balance'];

    /** A supplier's payable balance as SQL, correlated on the outer suppliers row. */
    private const BALANCE_SQL = '(SELECT COALESCE(SUM(`jl`.`credit_minor` - `jl`.`debit_minor`), 0)
        FROM `journal_lines` `jl` JOIN `accounts` `a` ON `a`.`id` = `jl`.`account_id`
        WHERE `jl`.`supplier_id` = `suppliers`.`id` AND `a`.`code` = \''.ChartOfAccounts::ACCOUNTS_PAYABLE.'\')';

    /** What the shop owes this supplier: A/P credits less debits tagged to them. */
    public static function getSupplierBalance(int $supplierId): int
    {
        $row = DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_lines.supplier_id', $supplierId)
            ->where('accounts.code', ChartOfAccounts::ACCOUNTS_PAYABLE)
            ->selectRaw('COALESCE(SUM(`journal_lines`.`debit_minor`), 0) AS d, COALESCE(SUM(`journal_lines`.`credit_minor`), 0) AS c')
            ->first();

        return Money::subtract((int) $row->c, (int) $row->d);
    }

    /**
     * @param  list<int>|null  $supplierIds  only these suppliers (a page of them); null for everyone
     * @return array<int, int> every supplier's balance, by id
     */
    public static function getAllSupplierBalances(?array $supplierIds = null): array
    {
        return DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', ChartOfAccounts::ACCOUNTS_PAYABLE)
            ->whereNotNull('journal_lines.supplier_id')
            ->when($supplierIds !== null, fn (Builder $q) => $q->whereIn('journal_lines.supplier_id', $supplierIds))
            ->groupBy('journal_lines.supplier_id')
            ->selectRaw('`journal_lines`.`supplier_id` AS id, COALESCE(SUM(`journal_lines`.`credit_minor`), 0) - COALESCE(SUM(`journal_lines`.`debit_minor`), 0) AS b')
            ->pluck('b', 'id')
            ->map(fn ($b): int => (int) $b)
            ->all();
    }

    /** Owed by the shop to everyone: the payables figure. */
    public static function getTotalPayables(): int
    {
        return Money::sum(self::getAllSupplierBalances());
    }

    /**
     * Lock order (Tx): the supplier row, after any products and before any
     * document, so an overpayment check, refund cap or allocation is read from
     * a balance no other writer can move until this one commits.
     */
    public static function lockSupplier(int $supplierId): ?object
    {
        return DB::table('suppliers')->where('id', $supplierId)->lockForUpdate()->first();
    }

    public static function createSupplier(array $input, Actor $actor): int
    {
        $name = JsCompat::trim($input['name'] ?? '');
        if ($name === '') {
            throw new ValidationError('Enter the supplier’s name.');
        }

        return Tx::run(function () use ($input, $name, $actor): int {
            $now = Clock::now();
            $at = Clock::stamp($now);
            $id = (int) DB::table('suppliers')->insertGetId([
                'name' => $name,
                ...self::contact($input),
                'is_active' => true,
                'created_by' => $actor->id,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            AuditService::write(AuditAction::Create, 'supplier', "Added supplier \"{$name}\"", $id, $actor, null, $now);

            return $id;
        });
    }

    public static function updateSupplier(int $id, array $input, Actor $actor): void
    {
        $name = JsCompat::trim($input['name'] ?? '');
        if ($name === '') {
            throw new ValidationError('Enter the supplier’s name.');
        }

        Tx::run(function () use ($id, $input, $name, $actor): void {
            $existing = self::lockSupplier($id) ?? throw new NotFoundError('Supplier', $id);
            $now = Clock::now();
            DB::table('suppliers')->where('id', $id)->update(['name' => $name, ...self::contact($input), 'updated_at' => Clock::stamp($now)]);
            AuditService::write(AuditAction::Update, 'supplier', "Updated supplier \"{$name}\"", $id, $actor, [
                'before' => ['name' => $existing->name],
                'after' => ['name' => $name],
            ], $now);
        });
    }

    /** Archiving is refused while money is still owed: an archived creditor is a forgotten debt. */
    public static function setSupplierActive(int $id, bool $isActive, Actor $actor): void
    {
        Tx::run(function () use ($id, $isActive, $actor): void {
            $existing = self::lockSupplier($id) ?? throw new NotFoundError('Supplier', $id);
            if (! $isActive && self::getSupplierBalance($id) !== 0) {
                throw new ConflictError("You still owe {$existing->name}. Settle the balance before archiving them.");
            }

            $now = Clock::now();
            DB::table('suppliers')->where('id', $id)->update(['is_active' => $isActive, 'updated_at' => Clock::stamp($now)]);
            AuditService::write(
                $isActive ? AuditAction::Restore : AuditAction::Archive,
                'supplier',
                ($isActive ? 'Restored' : 'Archived')." supplier \"{$existing->name}\"",
                $id,
                $actor,
                null,
                $now,
            );
        });
    }

    // --- reads --------------------------------------------------------------

    /**
     * Query keys: search, includeInactive, supplierStatus ('active'|'archived'),
     * balanceState ('owing'|'zero'|'credit'), owingOnly, sort, direction, limit, offset.
     *
     * @return list<array>
     */
    public static function listSuppliers(array $query = []): array
    {
        $q = DB::table('suppliers');
        self::applyConditions($q, $query);

        $ascending = ($query['direction'] ?? 'asc') === 'asc';
        if (($query['sort'] ?? null) === 'balance') {
            $q->orderByRaw(self::BALANCE_SQL.($ascending ? ' ASC' : ' DESC'))->orderByRaw('LOWER(`suppliers`.`name`) ASC');
        } else {
            $q->orderByRaw('LOWER(`suppliers`.`name`) '.($ascending ? 'ASC' : 'DESC'));
        }

        $rows = $q->limit(min($query['limit'] ?? 200, 500))->offset($query['offset'] ?? 0)->get(['suppliers.*']);
        $balances = self::getAllSupplierBalances($rows->map(fn (object $row): int => (int) $row->id)->all());

        return $rows->map(fn (object $row): array => self::item($row, $balances[(int) $row->id] ?? 0))->all();
    }

    /** How many suppliers match, ignoring the page. */
    public static function countSuppliers(array $query = []): int
    {
        $q = DB::table('suppliers');
        self::applyConditions($q, $query);

        return $q->count();
    }

    /** Just enough to fill a filter dropdown. @return list<array{id: int, name: string, isActive: bool}> */
    public static function listSupplierOptions(bool $includeInactive = false): array
    {
        $q = DB::table('suppliers');
        if (! $includeInactive) {
            $q->where('suppliers.is_active', true);
        }

        return $q->orderByRaw('LOWER(`suppliers`.`name`) ASC')->get(['id', 'name', 'is_active'])
            ->map(fn (object $r): array => ['id' => (int) $r->id, 'name' => $r->name, 'isActive' => (bool) $r->is_active])->all();
    }

    public static function getSupplier(int $id): array
    {
        $row = DB::table('suppliers')->where('id', $id)->first() ?? throw new NotFoundError('Supplier', $id);

        return self::item($row, self::getSupplierBalance($id));
    }

    private static function item(object $row, int $balance): array
    {
        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'contactPerson' => $row->contact_person,
            'phone' => $row->phone,
            'email' => $row->email,
            'address' => $row->address,
            'notes' => $row->notes,
            'balance' => $balance,
            'isActive' => (bool) $row->is_active,
        ];
    }

    private static function applyConditions(Builder $q, array $query): void
    {
        $status = $query['supplierStatus'] ?? null;
        if ($status === 'active') {
            $q->where('suppliers.is_active', true);
        } elseif ($status === 'archived') {
            $q->where('suppliers.is_active', false);
        } elseif (! ($query['includeInactive'] ?? false)) {
            $q->where('suppliers.is_active', true);
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
                ->whereRaw('LOWER(`suppliers`.`name`) LIKE ?', [$term])
                ->orWhereRaw("LOWER(COALESCE(`suppliers`.`phone`, '')) LIKE ?", [$term])
                ->orWhereRaw("LOWER(COALESCE(`suppliers`.`contact_person`, '')) LIKE ?", [$term])
                ->orWhereRaw("LOWER(COALESCE(`suppliers`.`email`, '')) LIKE ?", [$term]));
        }
    }

    /** Optional contact fields: trimmed, and empty becomes null, as `?.trim() || null` did. */
    private static function contact(array $input): array
    {
        $clean = fn (?string $v): ?string => ($v === null || JsCompat::trim($v) === '') ? null : JsCompat::trim($v);

        return [
            'contact_person' => $clean($input['contactPerson'] ?? null),
            'phone' => $clean($input['phone'] ?? null),
            'email' => $clean($input['email'] ?? null),
            'address' => $clean($input['address'] ?? null),
            'notes' => $clean($input['notes'] ?? null),
        ];
    }
}
