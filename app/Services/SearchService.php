<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Principal;
use App\Domain\JsCompat;
use App\Domain\Quantity;
use App\Enums\PermissionModule;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Searching across the shop's records: a port of the old
 * services/search.service.ts. A plain case-insensitive substring
 * (LOWER(col) LIKE LOWER(?), wildcards escaped), at most 8 hits per group.
 *
 * Permission decides which record types are QUERIED at all, not which
 * results are shown: a type the person cannot view is never searched, so
 * neither a count nor a timing difference gives its records away. Reads only.
 */
final class SearchService
{
    private const PER_GROUP = 8;

    /**
     * @return array{query: string, groups: list<array{label: string, hits: list<array{kind: string, id: int, title: string, detail: string, href: string, amount?: int}>}>, total: int, truncated: bool}
     */
    public static function search(string $rawQuery, Principal $user): array
    {
        $query = JsCompat::trim($rawQuery);
        // String.length < 2, in UTF-16 units as the old check counted.
        if (strlen((string) mb_convert_encoding($query, 'UTF-16LE', 'UTF-8')) < 4) {
            return ['query' => $query, 'groups' => [], 'total' => 0, 'truncated' => false];
        }

        // "50%" means what it says: the wildcards are escaped (MySQL's LIKE escapes with a backslash).
        $term = '%'.addcslashes($query, '%_\\').'%';
        $like = fn (string $column): string => "LOWER({$column}) LIKE LOWER(?)";
        $groups = [];
        $truncated = false;
        $add = function (string $label, array $hits) use (&$groups, &$truncated): void {
            if ($hits === []) {
                return;
            }
            if (count($hits) > self::PER_GROUP) {
                $truncated = true;
            }
            $groups[] = ['label' => $label, 'hits' => array_slice($hits, 0, self::PER_GROUP)];
        };
        $rows = fn (Builder $q): array => $q->limit(self::PER_GROUP + 1)->get()->all();
        $join = fn (array $parts): string => implode(' · ', array_filter($parts, fn ($p) => $p !== null && $p !== ''));

        if ($user->can(PermissionModule::Products, 'view')) {
            $add('Stock items', array_map(fn (object $r): array => [
                'kind' => 'product', 'id' => (int) $r->id, 'title' => $r->name,
                'detail' => $join([$r->sku, $r->unit]),
                'href' => "/products/{$r->id}/edit",
            ], $rows(DB::table('products')
                ->where(fn (Builder $w) => $w->whereRaw($like('products.name'), [$term])->orWhereRaw($like('products.sku'), [$term]))
                ->orderBy('products.id')
                ->select(['products.id', 'products.name', 'products.sku', 'products.unit']))));

            // A batch reference finds the crate whatever its state (a recall is about stock that
            // has gone); a product name finds only crates still holding something.
            $add('Batches', array_map(fn (object $r): array => [
                'kind' => 'batch', 'id' => (int) $r->id, 'title' => $r->batch_ref,
                'detail' => implode(' · ', [
                    $r->product_name,
                    Quantity::formatWithUnit((int) $r->qty_milli, $r->unit).' left',
                    $r->expiry_date === null ? 'no date' : 'expires '.InventoryService::formatDate($r->expiry_date),
                ]),
                'href' => "/inventory/batches/{$r->id}",
            ], $rows(DB::table('product_batches')
                ->join('products', 'products.id', '=', 'product_batches.product_id')
                ->where(fn (Builder $w) => $w->whereRaw($like('product_batches.batch_ref'), [$term])
                    ->orWhere(fn (Builder $n) => $n->whereRaw($like('products.name'), [$term])->where('product_batches.qty_milli', '<>', 0)))
                // SQLite put a NULL expiry first in ascending order; MySQL does too.
                ->orderBy('product_batches.expiry_date')->orderBy('product_batches.id')
                ->select(['product_batches.id', 'product_batches.batch_ref', 'product_batches.expiry_date', 'product_batches.qty_milli', 'products.name AS product_name', 'products.unit']))));
        }

        if ($user->can(PermissionModule::Customers, 'view')) {
            $add('Customers', self::parties('customers', $term, $like, $rows));
        }
        if ($user->can(PermissionModule::Suppliers, 'view')) {
            $add('Suppliers', self::parties('suppliers', $term, $like, $rows));
        }

        if ($user->can(PermissionModule::Sales, 'view')) {
            $add('Receipts', array_map(fn (object $r): array => [
                'kind' => 'sale', 'id' => (int) $r->id, 'title' => $r->receipt_no,
                'detail' => $join([$r->business_date, $r->customer_name]),
                'href' => "/sales/{$r->id}",
                'amount' => (int) $r->total_minor,
            ], $rows(DB::table('sales')
                ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
                ->where(fn (Builder $w) => $w->whereRaw($like('sales.receipt_no'), [$term])->orWhereRaw($like('customers.name'), [$term])->orWhereRaw($like('sales.note'), [$term]))
                ->orderByDesc('sales.business_date')->orderBy('sales.id')
                ->select(['sales.id', 'sales.receipt_no', 'sales.business_date', 'sales.total_minor', 'customers.name AS customer_name']))));
        }

        if ($user->can(PermissionModule::Purchases, 'view')) {
            $add('Purchases', array_map(fn (object $r): array => [
                'kind' => 'purchase', 'id' => (int) $r->id, 'title' => $r->purchase_no,
                'detail' => $join([$r->business_date, $r->supplier_name]),
                'href' => "/purchases/{$r->id}",
                'amount' => (int) $r->total_minor,
            ], $rows(DB::table('purchases')
                ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
                ->where(fn (Builder $w) => $w->whereRaw($like('purchases.purchase_no'), [$term])->orWhereRaw($like('purchases.invoice_no'), [$term])->orWhereRaw($like('suppliers.name'), [$term]))
                ->orderByDesc('purchases.business_date')->orderBy('purchases.id')
                ->select(['purchases.id', 'purchases.purchase_no', 'purchases.business_date', 'purchases.total_minor', 'suppliers.name AS supplier_name']))));
        }

        // The ledger itself, for anyone allowed to look at it.
        if ($user->can(PermissionModule::Accounts, 'view')) {
            $add('Journal entries', array_map(fn (object $r): array => [
                'kind' => 'journal', 'id' => (int) $r->id, 'title' => $r->entry_no,
                'detail' => $join([$r->entry_date, $r->memo]),
                'href' => "/accounting/journal/{$r->id}",
            ], $rows(DB::table('journal_entries')
                ->where(fn (Builder $w) => $w->whereRaw($like('journal_entries.entry_no'), [$term])->orWhereRaw($like('journal_entries.memo'), [$term]))
                ->orderByDesc('journal_entries.entry_date')->orderBy('journal_entries.id')
                ->select(['journal_entries.id', 'journal_entries.entry_no', 'journal_entries.entry_date', 'journal_entries.memo']))));
        }

        return [
            'query' => $query,
            'groups' => $groups,
            'total' => array_sum(array_map(fn (array $g): int => count($g['hits']), $groups)),
            'truncated' => $truncated,
        ];
    }

    /** @param  'customers'|'suppliers'  $table */
    private static function parties(string $table, string $term, \Closure $like, \Closure $rows): array
    {
        $kind = $table === 'customers' ? 'customer' : 'supplier';

        return array_map(fn (object $r): array => [
            'kind' => $kind, 'id' => (int) $r->id, 'title' => $r->name,
            'detail' => $r->phone ?? 'No phone number',
            'href' => "/{$table}/{$r->id}",
        ], $rows(DB::table($table)
            ->where(fn (Builder $w) => $w->whereRaw($like("{$table}.name"), [$term])->orWhereRaw($like("{$table}.phone"), [$term]))
            ->orderBy("{$table}.id")
            ->select(["{$table}.id", "{$table}.name", "{$table}.phone"])));
    }
}
