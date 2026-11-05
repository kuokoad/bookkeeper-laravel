<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\BusinessDate;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Support\Facades\DB;

/**
 * The old receivables ageing report (reporting/ledger.service
 * getReceivablesAgeing), line for line, until the reports arrive in Phase 7:
 * every customer sale dated by asAt, its outstanding from
 * SaleService::getOutstandingBySale(asAt), settled sales skipped (credits
 * kept), aged from the due date (else the sale date) into current / 1-30 /
 * 31-60 / 61-90 / over 90, parties netting to zero dropped, largest first.
 * payables() is the old getPayablesAgeing the same way, from
 * PurchaseService::getOutstandingByPurchase(asAt), aged from the purchase date.
 */
final class Ageing
{
    /** @return list<array{partyId: int, total: int, current: int, days1to30: int, days31to60: int, days61to90: int, over90: int}> */
    public static function receivables(string $asAt): array
    {
        $outstanding = SaleService::getOutstandingBySale(['asAt' => $asAt]);
        $rows = DB::table('sales')->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sales.business_date', '<=', $asAt)->orderBy('sales.business_date')
            ->get(['sales.id', 'sales.business_date', 'sales.due_date', 'sales.customer_id']);

        $byParty = [];
        foreach ($rows as $row) {
            $owed = $outstanding[(int) $row->id] ?? 0;
            if ($owed === 0) {
                continue;
            }
            $bucket = self::bucket(BusinessDate::daysBetween($row->due_date ?? $row->business_date, $asAt));
            $id = (int) $row->customer_id;
            $party = $byParty[$id] ?? ['partyId' => $id, 'total' => 0, 'current' => 0, 'days1to30' => 0, 'days31to60' => 0, 'days61to90' => 0, 'over90' => 0];
            $party[$bucket] += $owed;
            $party['total'] += $owed;
            $byParty[$id] = $party;
        }
        $list = array_values(array_filter($byParty, fn (array $p): bool => $p['total'] !== 0));
        usort($list, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $list;
    }

    /** The A/R control account from journal lines dated on or before asAt. */
    public static function receivablesAt(string $asAt): int
    {
        return (int) DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', ChartOfAccounts::ACCOUNTS_RECEIVABLE)
            ->where('journal_entries.entry_date', '<=', $asAt)
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor - journal_lines.credit_minor), 0) AS b')->value('b');
    }

    /** @return list<array{partyId: int, total: int, current: int, days1to30: int, days31to60: int, days61to90: int, over90: int}> */
    public static function payables(string $asAt): array
    {
        $outstanding = PurchaseService::getOutstandingByPurchase(['asAt' => $asAt]);
        $rows = DB::table('purchases')->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.business_date', '<=', $asAt)->orderBy('purchases.business_date')
            ->get(['purchases.id', 'purchases.business_date', 'purchases.supplier_id']);

        $byParty = [];
        foreach ($rows as $row) {
            $owed = $outstanding[(int) $row->id] ?? 0;
            if ($owed === 0) {
                continue;
            }
            $bucket = self::bucket(BusinessDate::daysBetween($row->business_date, $asAt));
            $id = (int) $row->supplier_id;
            $party = $byParty[$id] ?? ['partyId' => $id, 'total' => 0, 'current' => 0, 'days1to30' => 0, 'days31to60' => 0, 'days61to90' => 0, 'over90' => 0];
            $party[$bucket] += $owed;
            $party['total'] += $owed;
            $byParty[$id] = $party;
        }
        $list = array_values(array_filter($byParty, fn (array $p): bool => $p['total'] !== 0));
        usort($list, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $list;
    }

    /** The A/P control account (credits less debits) from journal lines dated on or before asAt. */
    public static function payablesAt(string $asAt): int
    {
        return (int) DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', ChartOfAccounts::ACCOUNTS_PAYABLE)
            ->where('journal_entries.entry_date', '<=', $asAt)
            ->selectRaw('COALESCE(SUM(journal_lines.credit_minor - journal_lines.debit_minor), 0) AS b')->value('b');
    }

    private static function bucket(int $days): string
    {
        return match (true) {
            $days <= 0 => 'current',
            $days <= 30 => 'days1to30',
            $days <= 60 => 'days31to60',
            $days <= 90 => 'days61to90',
            default => 'over90',
        };
    }
}
