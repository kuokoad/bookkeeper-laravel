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
        // Over more than a quarter (as in OperationsService), a hash join of the period's sales against one
        // scan of sale_taxes beats one index probe per sale: a year 0.66 s -> 0.29 s at a year of trading,
        // 0.78 s -> 0.55 s at three. Over a month the probes win (0.08 s against 0.15 s at three years).
        // The IGNORE INDEX sits in the table expression, so no builder change can move it out of place.
        $long = (new \DateTimeImmutable($period['from']))->diff(new \DateTimeImmutable($period['to']))->days > 92;
        $output = ($long
            ? DB::table('sales')->straightJoin(DB::raw('`sale_taxes` IGNORE INDEX (`uq_sale_taxes_sale_code`)'), 'sales.id', '=', 'sale_taxes.sale_id')
            : DB::table('sale_taxes')->join('sales', 'sales.id', '=', 'sale_taxes.sale_id'))
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
        // Counted as every original minus those voided by the end: the same number as standingAtEndSql per
        // row, but the voided ones are found from the few void mirrors instead of one probe per document.
        // Owner decision 2026-11-12 (fifth audit, finding 8): the value and the count take only documents
        // carrying a tax line (void mirrors and returns carry their own), so it is the base the tax was worked out on.
        $value = function (string $table, string $voids, string $taxes, string $fk) use ($range, $period): object {
            $taxed = "EXISTS (SELECT 1 FROM {$taxes} WHERE {$taxes}.{$fk} = {$table}.id)";
            $totals = DB::table($table)->whereBetween("{$table}.business_date", $range)
                ->selectRaw("COALESCE(SUM(CASE WHEN {$taxed} THEN {$table}.subtotal_minor - {$table}.discount_minor ELSE 0 END), 0) AS v, COUNT(CASE WHEN {$table}.kind <> 'VOID' AND {$taxed} THEN 1 END) AS n")->first();
            $voided = DB::table("{$table} AS void_mirror")->join($table, "{$table}.id", '=', "void_mirror.{$voids}")
                ->whereNotNull("void_mirror.{$voids}")->where('void_mirror.business_date', '<=', $period['to'])
                ->whereBetween("{$table}.business_date", $range)->where("{$table}.kind", '<>', 'VOID')->whereRaw($taxed)
                ->selectRaw("COUNT(DISTINCT {$table}.id) AS n")->value('n');

            return (object) ['v' => $totals->v, 'n' => (int) $totals->n - (int) $voided];
        };
        $sales = $value('sales', 'voids_sale_id', 'sale_taxes', 'sale_id');
        $purchases = $value('purchases', 'voids_purchase_id', 'purchase_taxes', 'purchase_id');

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
