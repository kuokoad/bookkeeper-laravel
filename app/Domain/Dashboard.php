<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The dashboard's pure helpers: its windows, its daily series and how it
 * classifies ageing, counts and checks. A port of the old src/lib/dashboard.ts.
 * Figures arrive already totalled by the reporting services.
 */
final class Dashboard
{
    public const RANGES = ['week' => 7, 'month' => 30, 'quarter' => 90];

    public static function dashboardRange(?string $value): string
    {
        return $value === 'week' || $value === 'quarter' ? $value : 'month';
    }

    /**
     * The last N days, today included: both ends count, so the window starts
     * N − 1 days back (D5; the old range ran today − N, one day too many).
     *
     * @return array{from: string, to: string}
     */
    public static function dashboardPeriod(string $today, string $range): array
    {
        return ['from' => BusinessDate::addDays($today, 1 - self::RANGES[$range]), 'to' => $today];
    }

    /** The Transaction History filter: one journal source type each, the journal page's own kinds. */
    public const ACTIVITY = ['SALE' => 'Sales', 'PURCHASE' => 'Purchases', 'EXPENSE' => 'Expenses', 'CUSTOMER_PAYMENT' => 'Customer payments', 'SUPPLIER_PAYMENT' => 'Supplier payments'];

    /** A known kind, or null for everything: anything else in the query string is ignored. */
    public static function dashboardActivity(?string $value): ?string
    {
        return $value !== null && array_key_exists($value, self::ACTIVITY) ? $value : null;
    }

    public static function dashboardRangeHref(string $sales, string $spend, ?string $activity = null): string
    {
        return "/?sales={$sales}&spend={$spend}".($activity === null ? '' : "&activity={$activity}");
    }

    /**
     * Every day of the period, a day with no row a confirmed zero.
     *
     * @param  list<array{businessDate: string, total: int}>  $rows
     * @param  array{from: string, to: string}  $period
     * @return list<array{label: string, value: int}>
     */
    public static function dashboardDailySeries(array $rows, array $period): array
    {
        $byDate = array_column($rows, 'total', 'businessDate');
        $result = [];
        for ($date = $period['from']; $date <= $period['to']; $date = BusinessDate::addDays($date, 1)) {
            $result[] = ['label' => substr($date, 5), 'value' => $byDate[$date] ?? 0];
        }

        return $result;
    }

    /**
     * Overdue against not yet due, and what the ledger holds that no document
     * ages (opening balances, unallocated payments, credits): never given a due date.
     *
     * @param  list<array>  $rows  ageing rows
     * @return array{overdue: int, notYetDue: int, agedTotal: int, unaged: int, reconciles: bool}
     */
    public static function summariseAgeing(array $rows, int $ledgerTotal): array
    {
        $overdue = Money::sum(array_map(fn (array $r): int => Money::sum([$r['days1to30'], $r['days31to60'], $r['days61to90'], $r['over90']]), $rows));
        $notYetDue = Money::sum(array_column($rows, 'current'));
        $agedTotal = Money::add($overdue, $notYetDue);
        $unaged = Money::subtract($ledgerTotal, $agedTotal);

        return ['overdue' => $overdue, 'notYetDue' => $notYetDue, 'agedTotal' => $agedTotal, 'unaged' => $unaged, 'reconciles' => $unaged === 0];
    }

    /** Accounts with any unresolved difference, either sign: opposite ones must not hide each other. */
    public static function reconciliationIssues(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $r): bool => $r['unresolvedDifference'] !== 0));
    }

    /** @return list<array{label: string, passed: bool}> */
    public static function integrityChecks(array $check): array
    {
        return [
            ['label' => 'Debits equal credits', 'passed' => $check['trialBalanced']],
            ['label' => 'Every journal entry balances', 'passed' => $check['unbalancedEntries'] === []],
            ['label' => 'Customer balances match control account', 'passed' => $check['receivablesMatch']],
            ['label' => 'Supplier balances match control account', 'passed' => $check['payablesMatch']],
            ['label' => 'Entries have source references', 'passed' => $check['untracedEntries'] === 0],
        ];
    }
}
