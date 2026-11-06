<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Domain\FinancialYear;
use App\Enums\JournalSourceType;
use Illuminate\Support\Facades\DB;

/**
 * Money in and out per month, for the dashboard's cash-flow chart, in one
 * grouped query. "Money" is the GL accounts behind the payment accounts: a
 * debit is money arriving, a credit money leaving. A port of the old
 * reporting/money-trend.ts, except that transfers between the shop's own
 * accounts are left out, as the shop-wide cash flow leaves them out (D6):
 * money changing pockets is neither in nor out.
 */
final class MoneyTrend
{
    private const MONTH_LABELS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** @return list<array{month: string, label: string, inMinor: int, outMinor: int}> */
    public static function getMoneyByMonth(string $endDate, int $count = 6): array
    {
        $year = (int) substr($endDate, 0, 4);
        $month = (int) substr($endDate, 5, 2);
        $months = [];
        for ($back = $count - 1; $back >= 0; $back--) {
            // Arithmetic on a month index, never a DateTime with a zone.
            $index = $year * 12 + ($month - 1) - $back;
            $months[] = sprintf('%d-%02d', intdiv($index, 12), $index % 12 + 1);
        }

        $empty = array_map(fn (string $m): array => ['month' => $m, 'label' => self::MONTH_LABELS[(int) substr($m, 5, 2) - 1], 'inMinor' => 0, 'outMinor' => 0], $months);
        $moneyAccountIds = DB::table('payment_accounts')->pluck('payment_accounts.gl_account_id')->all();
        if ($moneyAccountIds === [] || $months === []) {
            return $empty;
        }

        $from = $months[0].'-01';
        $last = $months[count($months) - 1];
        $to = $last.'-'.sprintf('%02d', FinancialYear::daysInMonth((int) substr($last, 0, 4), (int) substr($last, 5, 2)));

        $rows = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->whereIn('journal_lines.account_id', $moneyAccountIds)
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->where('journal_entries.source_type', '<>', JournalSourceType::Transfer->value)
            ->groupByRaw("DATE_FORMAT(journal_entries.entry_date, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(journal_entries.entry_date, '%Y-%m') AS m, COALESCE(SUM(journal_lines.debit_minor), 0) AS d, COALESCE(SUM(journal_lines.credit_minor), 0) AS c")
            ->get()->keyBy('m');

        return array_map(fn (array $m): array => [
            ...$m,
            'inMinor' => isset($rows[$m['month']]) ? (int) $rows[$m['month']]->d : 0,
            'outMinor' => isset($rows[$m['month']]) ? (int) $rows[$m['month']]->c : 0,
        ], $empty);
    }
}
