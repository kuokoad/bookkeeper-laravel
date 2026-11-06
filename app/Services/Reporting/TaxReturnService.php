<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Domain\JsCompat;
use App\Domain\Money;
use Illuminate\Support\Facades\DB;

/**
 * What the shop owes the tax authority for a period, one row per tax. A port
 * of the old reporting/tax-return.service.ts, keeping its three rules:
 * every tax row counts whatever the document's status (a void's mirror
 * adjusts the period it happened in); input tax is reclaimable only if it
 * was on the day (the snapshotted is_recoverable); non-reclaimable input is
 * reported, not hidden. Dated by business date.
 */
final class TaxReturnService
{
    /**
     * D9 (and D23 for the sales report): SQL true while a document of $table
     * is still standing at the period end, which the caller binds: no void of
     * it is dated on or before that day. Leaving out the void mirrors
     * themselves is the caller's part.
     */
    public static function standingAtEndSql(string $table, string $voidsColumn): string
    {
        return "NOT EXISTS (SELECT 1 FROM {$table} AS void_mirror WHERE void_mirror.{$voidsColumn} = {$table}.id AND void_mirror.business_date <= ?)";
    }

    /** @param  array{from: string, to: string}  $period */
    public static function getTaxReturn(array $period): array
    {
        $range = [$period['from'], $period['to']];

        // shortcut: the name is MAX(name) per code; SQLite took any row's. Codes keep one name in practice.
        $output = DB::table('sale_taxes')->join('sales', 'sales.id', '=', 'sale_taxes.sale_id')
            ->whereBetween('sales.business_date', $range)
            ->groupBy('sale_taxes.code')->orderBy('sale_taxes.code')
            ->selectRaw('sale_taxes.code, MAX(sale_taxes.name) AS name, COALESCE(SUM(sale_taxes.amount_minor), 0) AS amount')->get();

        $input = DB::table('purchase_taxes')->join('purchases', 'purchases.id', '=', 'purchase_taxes.purchase_id')
            ->whereBetween('purchases.business_date', $range)
            ->groupBy('purchase_taxes.code', 'purchase_taxes.is_recoverable')->orderBy('purchase_taxes.code')->orderBy('purchase_taxes.is_recoverable')
            ->selectRaw('purchase_taxes.code, MAX(purchase_taxes.name) AS name, purchase_taxes.is_recoverable, COALESCE(SUM(purchase_taxes.amount_minor), 0) AS amount')->get();

        $byCode = [];
        $take = function (string $code, string $name) use (&$byCode): string {
            $byCode[$code] ??= ['code' => $code, 'name' => $name, 'outputMinor' => 0, 'recoverableInputMinor' => 0, 'nonRecoverableInputMinor' => 0, 'netMinor' => 0];

            return $code;
        };
        foreach ($output as $row) {
            $byCode[$take($row->code, $row->name)]['outputMinor'] = (int) $row->amount;
        }
        foreach ($input as $row) {
            $key = $row->is_recoverable ? 'recoverableInputMinor' : 'nonRecoverableInputMinor';
            $code = $take($row->code, $row->name);
            $byCode[$code][$key] = Money::add($byCode[$code][$key], (int) $row->amount);
        }

        // paidOnPurchasesMinor: reclaimable plus not, the "paid on purchases" column the old page added up itself.
        $components = array_values(array_map(fn (array $c): array => [...$c, 'netMinor' => Money::subtract($c['outputMinor'], $c['recoverableInputMinor']), 'paidOnPurchasesMinor' => Money::add($c['recoverableInputMinor'], $c['nonRecoverableInputMinor'])], $byCode));
        // Old: a.code.localeCompare(b.code) (tax-return.service.ts:145).
        usort($components, fn (array $a, array $b): int => JsCompat::compareCodes($a['code'], $b['code']));

        // The value nets every row. The count (D9) is the documents standing at the period end: a void
        // mirror never counts, and an original counts unless its void is dated on or before the end, so a
        // March sale voided in April stays "1 sale" in March and April counts nothing for the void.
        $value = fn (string $table, string $voids): string => "COALESCE(SUM({$table}.subtotal_minor - {$table}.discount_minor), 0) AS v,
            COUNT(CASE WHEN {$table}.kind <> 'VOID' AND ".self::standingAtEndSql($table, $voids).' THEN 1 END) AS n';
        $sales = DB::table('sales')->whereBetween('sales.business_date', $range)->selectRaw($value('sales', 'voids_sale_id'), [$period['to']])->first();
        $purchases = DB::table('purchases')->whereBetween('purchases.business_date', $range)->selectRaw($value('purchases', 'voids_purchase_id'), [$period['to']])->first();

        $totalOutput = Money::sum(array_column($components, 'outputMinor'));
        $totalRecoverableInput = Money::sum(array_column($components, 'recoverableInputMinor'));
        $totalNonRecoverableInput = Money::sum(array_column($components, 'nonRecoverableInputMinor'));
        $netPayable = Money::subtract($totalOutput, $totalRecoverableInput);

        return [
            'period' => $period,
            'components' => $components,
            'totalOutput' => $totalOutput,
            'totalRecoverableInput' => $totalRecoverableInput,
            'totalNonRecoverableInput' => $totalNonRecoverableInput,
            'totalPaidOnPurchases' => Money::add($totalRecoverableInput, $totalNonRecoverableInput),
            'netPayable' => $netPayable,
            // The headline shows the size; its label says which way it goes.
            'netUnsigned' => Money::absolute($netPayable),
            'taxableSalesMinor' => (int) $sales->v,
            'taxablePurchasesMinor' => (int) $purchases->v,
            'saleCount' => (int) $sales->n,
            'purchaseCount' => (int) $purchases->n,
        ];
    }
}
