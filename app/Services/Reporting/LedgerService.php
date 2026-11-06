<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Domain\Accounting\ChartOfAccounts as Coa;
use App\Domain\Accounting\Journal;
use App\Domain\BusinessDate;
use App\Domain\Errors\NotFoundError;
use App\Domain\Money;
use App\Enums\AccountType;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * Reading the books: the chart, the journal, the general ledger, the ageing
 * and the integrity check. Read-only and derived. A port of the old
 * reporting/ledger.service.ts.
 */
final class LedgerService
{
    /**
     * The chart of accounts with balances, as a tree in chart order. Headings
     * carry a roll-up of everything beneath them.
     *
     * @return list<array{id: int, code: string, name: string, type: string, parentId: ?int, isHeader: bool, isSystem: bool, isActive: bool, depth: int, totalDebit: int, totalCredit: int, balance: int, rollup: int, entryCount: int}>
     */
    public static function getChartOfAccounts(array $range = []): array
    {
        $rows = BalancesService::loadTotals(['from' => $range['from'] ?? null, 'to' => $range['to'] ?? null]);
        $byId = array_column($rows, null, 'accountId');
        $childrenOf = [];
        foreach ($rows as $row) {
            $childrenOf[$row['parentId'] ?? 0][] = $row;
        }
        $signed = fn (array $r): int => Journal::signedBalance(AccountType::from($r['type']), $r['totalDebit'], $r['totalCredit']);

        $rollupOf = function (int $id) use (&$rollupOf, $byId, $childrenOf, $signed): int {
            $total = $signed($byId[$id]);
            foreach ($childrenOf[$id] ?? [] as $child) {
                $total += $rollupOf($child['accountId']);
            }

            return $total;
        };

        $ordered = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$ordered, $childrenOf, $signed, $rollupOf): void {
            foreach ($childrenOf[$parent] ?? [] as $row) {
                $ordered[] = [
                    'id' => $row['accountId'],
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'parentId' => $row['parentId'],
                    'isHeader' => ($childrenOf[$row['accountId']] ?? []) !== [],
                    'isSystem' => $row['isSystem'],
                    'isActive' => $row['isActive'],
                    'depth' => $depth,
                    'totalDebit' => $row['totalDebit'],
                    'totalCredit' => $row['totalCredit'],
                    'balance' => $signed($row),
                    'rollup' => $rollupOf($row['accountId']),
                    'entryCount' => $row['entryCount'],
                ];
                $walk($row['accountId'], $depth + 1);
            }
        };
        $walk(0, 0);

        return $ordered;
    }

    /**
     * Newest first. Pages FIRST from journal_entries alone, then totals only
     * that page: the cost is the page, not the shop's age.
     *
     * @param  array{from?: string, to?: string, sourceType?: string, accountId?: int, limit?: int, offset?: int}  $query
     */
    public static function listJournalEntries(array $query = []): array
    {
        $page = DB::table('journal_entries')
            ->when(isset($query['from']), fn ($q) => $q->where('journal_entries.entry_date', '>=', $query['from']))
            ->when(isset($query['to']), fn ($q) => $q->where('journal_entries.entry_date', '<=', $query['to']))
            ->when(isset($query['sourceType']), fn ($q) => $q->where('journal_entries.source_type', $query['sourceType']))
            ->when(isset($query['accountId']), fn ($q) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('journal_lines')
                ->whereColumn('journal_lines.entry_id', 'journal_entries.id')->where('journal_lines.account_id', $query['accountId'])))
            ->orderByDesc('journal_entries.entry_date')->orderByDesc('journal_entries.id')
            ->limit(min($query['limit'] ?? 100, 500))->offset($query['offset'] ?? 0)
            ->get(['journal_entries.id', 'journal_entries.entry_no', 'journal_entries.entry_date', 'journal_entries.occurred_at', 'journal_entries.source_type',
                'journal_entries.source_id', 'journal_entries.memo', 'journal_entries.is_opening', 'journal_entries.reverses_entry_id', 'journal_entries.reversed_by_entry_id']);
        if ($page->isEmpty()) {
            return [];
        }

        $totals = DB::table('journal_lines')->whereIn('journal_lines.entry_id', $page->pluck('id')->all())
            ->groupBy('journal_lines.entry_id')
            ->selectRaw('journal_lines.entry_id, COUNT(*) AS n, COALESCE(SUM(journal_lines.debit_minor), 0) AS d, COALESCE(SUM(journal_lines.credit_minor), 0) AS c')
            ->get()->keyBy('entry_id');

        return $page->map(function (object $e) use ($totals): array {
            $t = $totals[$e->id] ?? null;
            $debit = $t === null ? 0 : (int) $t->d;
            $credit = $t === null ? 0 : (int) $t->c;

            return [
                'id' => (int) $e->id,
                'entryNo' => $e->entry_no,
                'entryDate' => (string) $e->entry_date,
                'occurredAt' => $e->occurred_at,
                'sourceType' => $e->source_type,
                'sourceId' => $e->source_id === null ? null : (int) $e->source_id,
                'memo' => $e->memo,
                'isOpening' => (bool) $e->is_opening,
                'reversesEntryId' => $e->reverses_entry_id === null ? null : (int) $e->reverses_entry_id,
                'reversedByEntryId' => $e->reversed_by_entry_id === null ? null : (int) $e->reversed_by_entry_id,
                'lineCount' => $t === null ? 0 : (int) $t->n,
                'total' => $debit,
                'balanced' => $debit === $credit,
            ];
        })->all();
    }

    /** @return array{entry: array, lines: list<array>, totalDebit: int, totalCredit: int, balanced: bool} */
    public static function getJournalEntry(int $entryId): array
    {
        $entry = DB::table('journal_entries')->where('journal_entries.id', $entryId)->first();
        if ($entry === null) {
            throw new NotFoundError('Journal entry', $entryId);
        }

        $lines = DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->leftJoin('customers', 'customers.id', '=', 'journal_lines.customer_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'journal_lines.supplier_id')
            ->where('journal_lines.entry_id', $entryId)->orderBy('journal_lines.line_no')
            ->get([
                'journal_lines.id', 'journal_lines.line_no', 'journal_lines.account_id', 'accounts.code as account_code', 'accounts.name as account_name',
                'accounts.type as account_type', 'journal_lines.debit_minor as debit', 'journal_lines.credit_minor as credit', 'journal_lines.description',
                'journal_lines.customer_id', 'customers.name as customer_name', 'journal_lines.supplier_id', 'suppliers.name as supplier_name',
            ])->map(fn (object $l): array => Row::camel($l))->all();

        $totalDebit = Money::sum(array_map(fn (array $l): int => (int) $l['debit'], $lines));
        $totalCredit = Money::sum(array_map(fn (array $l): int => (int) $l['credit'], $lines));

        return ['entry' => Row::camel($entry), 'lines' => $lines, 'totalDebit' => $totalDebit, 'totalCredit' => $totalCredit, 'balanced' => $totalDebit === $totalCredit];
    }

    /**
     * Every movement on one account, with an opening balance (everything
     * before the period) and a running balance signed for its type.
     */
    public static function getGeneralLedger(int $accountId, array $range = []): array
    {
        $account = self::chartAccount($accountId);
        $type = AccountType::from($account['type']);
        $from = $range['from'] ?? null;
        $to = $range['to'] ?? null;

        $openingBalance = 0;
        if ($from !== null) {
            $before = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
                ->where('journal_lines.account_id', $accountId)->where('journal_entries.entry_date', '<', $from)
                ->selectRaw('COALESCE(SUM(journal_lines.debit_minor), 0) AS d, COALESCE(SUM(journal_lines.credit_minor), 0) AS c')->first();
            $openingBalance = Journal::signedBalance($type, (int) $before->d, (int) $before->c);
        }

        $rows = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('journal_lines.account_id', $accountId)
            ->when($from !== null, fn ($q) => $q->where('journal_entries.entry_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('journal_entries.entry_date', '<=', $to))
            ->orderBy('journal_entries.entry_date')->orderBy('journal_entries.id')->orderBy('journal_lines.line_no')
            ->get(['journal_entries.id as entry_id', 'journal_entries.entry_no', 'journal_entries.entry_date', 'journal_entries.source_type', 'journal_entries.source_id',
                'journal_entries.memo', 'journal_lines.description', 'journal_lines.debit_minor', 'journal_lines.credit_minor']);

        $running = $openingBalance;
        $lines = [];
        foreach ($rows as $row) {
            $debit = (int) $row->debit_minor;
            $credit = (int) $row->credit_minor;
            $running = Money::add($running, Journal::signedBalance($type, $debit, $credit));
            $lines[] = [
                'entryId' => (int) $row->entry_id,
                'entryNo' => $row->entry_no,
                'entryDate' => (string) $row->entry_date,
                'sourceType' => $row->source_type,
                'sourceId' => $row->source_id === null ? null : (int) $row->source_id,
                'memo' => $row->memo,
                'description' => $row->description,
                'debit' => $debit,
                'credit' => $credit,
                'runningBalance' => $running,
            ];
        }

        return [
            'account' => $account,
            'openingBalance' => $openingBalance,
            'lines' => $lines,
            'closingBalance' => $running,
            'totalDebit' => Money::sum(array_column($lines, 'debit')),
            'totalCredit' => Money::sum(array_column($lines, 'credit')),
        ];
    }

    /**
     * One account as getChartOfAccounts() shows it (all-time balance, roll-up
     * and depth), from that account's own subtree rather than the whole chart.
     */
    private static function chartAccount(int $accountId): array
    {
        $accounts = DB::table('accounts')->get(['accounts.id', 'accounts.code', 'accounts.name', 'accounts.type', 'accounts.parent_id', 'accounts.is_system', 'accounts.is_active'])->keyBy('id');
        $row = $accounts[$accountId] ?? null;
        if ($row === null) {
            throw new NotFoundError('Account', $accountId);
        }
        $children = [];
        foreach ($accounts as $a) {
            if ($a->parent_id !== null) {
                $children[(int) $a->parent_id][] = (int) $a->id;
            }
        }
        $subtree = [$accountId];
        for ($i = 0; $i < count($subtree); $i++) {
            array_push($subtree, ...($children[$subtree[$i]] ?? []));
        }
        $depth = 0;
        for ($at = $row->parent_id; $at !== null; $at = $accounts[(int) $at]->parent_id) {
            $depth++;
        }

        $sums = DB::table('journal_lines')->whereIn('journal_lines.account_id', $subtree)->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit_minor) AS d, SUM(journal_lines.credit_minor) AS c, COUNT(*) AS n')->get()->keyBy('account_id');
        $signed = fn (int $id): int => isset($sums[$id]) ? Journal::signedBalance(AccountType::from($accounts[$id]->type), (int) $sums[$id]->d, (int) $sums[$id]->c) : 0;
        $own = $sums[$accountId] ?? null;

        return [
            'id' => $accountId,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'type' => (string) $row->type,
            'parentId' => $row->parent_id === null ? null : (int) $row->parent_id,
            'isHeader' => isset($children[$accountId]),
            'isSystem' => (bool) $row->is_system,
            'isActive' => (bool) $row->is_active,
            'depth' => $depth,
            'totalDebit' => $own === null ? 0 : (int) $own->d,
            'totalCredit' => $own === null ? 0 : (int) $own->c,
            'balance' => $signed($accountId),
            'rollup' => array_sum(array_map($signed, $subtree)),
            'entryCount' => $own === null ? 0 : (int) $own->n,
        ];
    }

    private static function bucketFor(int $days): string
    {
        return match (true) {
            $days <= 0 => 'current',
            $days <= 30 => 'days1to30',
            $days <= 60 => 'days31to60',
            $days <= 90 => 'days61to90',
            default => 'over90',
        };
    }

    /**
     * How long money has been OVERDUE, from the due date (else the sale
     * date). Amounts come from SaleService::getOutstandingBySale(asAt), so
     * voids net out and credits are kept. Largest first; parties netting to
     * zero dropped.
     *
     * @return list<array{partyId: int, partyName: string, phone: ?string, total: int, current: int, days1to30: int, days31to60: int, days61to90: int, over90: int, oldestDate: ?string}>
     */
    public static function getReceivablesAgeing(string $asAt): array
    {
        $rows = DB::table('sales')->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sales.business_date', '<=', $asAt)->orderBy('sales.business_date')->orderBy('sales.id')
            ->get(['sales.id', 'sales.business_date', 'sales.due_date', 'sales.customer_id', 'customers.name', 'customers.phone']);

        return self::age($rows, SaleService::getOutstandingBySale(['asAt' => $asAt]), 'customer_id', $asAt);
    }

    /**
     * The same for suppliers, from the purchase's due date, else its date
     * (D8: the old report had no due date and aged every delivery from the day it came).
     */
    public static function getPayablesAgeing(string $asAt): array
    {
        $rows = DB::table('purchases')->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.business_date', '<=', $asAt)->orderBy('purchases.business_date')->orderBy('purchases.id')
            ->get(['purchases.id', 'purchases.business_date', 'purchases.due_date', 'purchases.supplier_id', 'suppliers.name', 'suppliers.phone']);

        return self::age($rows, PurchaseService::getOutstandingByPurchase(['asAt' => $asAt]), 'supplier_id', $asAt);
    }

    /**
     * The ageing rows one party and a status keep, in order: the old pages
     * narrowed the built report this way (overdue = anything over 90 days).
     */
    public static function narrowAgeing(array $rows, ?int $partyId, ?string $status): array
    {
        return array_values(array_filter($rows, fn (array $r): bool => ($partyId === null || $r['partyId'] === $partyId)
            && ! ($status === 'overdue' && $r['over90'] <= 0)
            && ! ($status === 'current' && $r['over90'] > 0)));
    }

    /**
     * Bucket totals over the ageing rows given (the page passes the rows it
     * shows, or the whole report), as the old receivables/payables pages
     * summed them.
     *
     * @param  list<array{current: int, days1to30: int, days31to60: int, days61to90: int, over90: int, total: int}>  $rows
     * @return array{current: int, days1to30: int, days31to60: int, days61to90: int, over90: int, total: int}
     */
    public static function getAgeingTotals(array $rows): array
    {
        $keys = ['current', 'days1to30', 'days31to60', 'days61to90', 'over90', 'total'];

        return array_combine($keys, array_map(fn (string $key): int => Money::sum(array_column($rows, $key)), $keys));
    }

    /**
     * The old trial-balance page: raw (gross) debit and credit totals per
     * leaf account with movement, headings excluded so nothing counts twice,
     * and the grand totals of those rows.
     *
     * @param  array{from?: ?string, to?: ?string}  $range
     * @return array{rows: list<array<string, mixed>>, totalDebit: int, totalCredit: int, difference: int, balanced: bool}
     */
    public static function getGrossTrialBalance(array $range = []): array
    {
        $rows = array_values(array_filter(
            self::getChartOfAccounts($range),
            fn (array $a): bool => ! $a['isHeader'] && ($a['totalDebit'] > 0 || $a['totalCredit'] > 0),
        ));
        $totalDebit = Money::sum(array_column($rows, 'totalDebit'));
        $totalCredit = Money::sum(array_column($rows, 'totalCredit'));

        return [
            'rows' => $rows,
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
            'difference' => Money::subtract($totalDebit, $totalCredit),
            'balanced' => $totalDebit === $totalCredit,
        ];
    }

    private static function age(iterable $rows, array $outstanding, string $partyColumn, string $asAt): array
    {
        $byParty = [];
        foreach ($rows as $row) {
            $owed = $outstanding[(int) $row->id] ?? 0;
            if ($owed === 0) {
                continue;
            }
            $id = (int) $row->{$partyColumn};
            $party = $byParty[$id] ?? [
                'partyId' => $id, 'partyName' => (string) $row->name, 'phone' => $row->phone,
                'total' => 0, 'current' => 0, 'days1to30' => 0, 'days31to60' => 0, 'days61to90' => 0, 'over90' => 0, 'oldestDate' => null,
            ];
            $agedFrom = (string) ($row->due_date ?? $row->business_date);
            $bucket = self::bucketFor(BusinessDate::daysBetween($agedFrom, $asAt));
            $party[$bucket] = Money::add($party[$bucket], $owed);
            $party['total'] = Money::add($party['total'], $owed);
            if ($party['oldestDate'] === null || $agedFrom < $party['oldestDate']) {
                $party['oldestDate'] = $agedFrom;
            }
            $byParty[$id] = $party;
        }

        $list = array_values(array_filter($byParty, fn (array $p): bool => $p['total'] !== 0));
        usort($list, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $list;
    }

    /**
     * One health check over the whole ledger: totals tie, every entry
     * balances, the subledgers match their control accounts, and no entry
     * lacks a source document (opening balances and year-end closes aside).
     */
    public static function checkBooksIntegrity(): array
    {
        $totals = DB::table('journal_lines')->selectRaw('COALESCE(SUM(journal_lines.debit_minor), 0) AS d, COALESCE(SUM(journal_lines.credit_minor), 0) AS c')->first();
        $totalDebit = (int) $totals->d;
        $totalCredit = (int) $totals->c;

        // One hashed pass over the table: grouping through the (entry, line) index costs a row lookup per line.
        $unbalanced = array_map(fn (object $r): array => ['id' => (int) $r->id, 'entryNo' => $r->entry_no, 'difference' => (int) $r->d - (int) $r->c], DB::select(
            'SELECT journal_entries.id, journal_entries.entry_no, totals.d, totals.c
             FROM (SELECT journal_lines.entry_id, SUM(journal_lines.debit_minor) AS d, SUM(journal_lines.credit_minor) AS c
                   FROM journal_lines IGNORE INDEX (uq_journal_lines_entry_line) GROUP BY journal_lines.entry_id
                   HAVING SUM(journal_lines.debit_minor) <> SUM(journal_lines.credit_minor)) AS totals
             JOIN journal_entries ON journal_entries.id = totals.entry_id
             ORDER BY journal_entries.id',
        ));

        $control = function (string $code, bool $creditNormal, ?string $party) {
            $row = DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
                ->where('accounts.code', $code)
                ->when($party !== null, fn ($q) => $q->whereNotNull("journal_lines.{$party}"))
                ->selectRaw('COALESCE(SUM(journal_lines.debit_minor), 0) AS d, COALESCE(SUM(journal_lines.credit_minor), 0) AS c')->first();

            return $creditNormal ? (int) $row->c - (int) $row->d : (int) $row->d - (int) $row->c;
        };

        $untraced = DB::table('journal_entries')->whereNull('journal_entries.source_id')
            ->whereNotIn('journal_entries.source_type', ['OPENING_BALANCE', 'YEAR_END_CLOSE'])->count();

        return [
            'trialBalanced' => $totalDebit === $totalCredit,
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
            'difference' => $totalDebit - $totalCredit,
            'unbalancedEntries' => $unbalanced,
            'receivablesMatch' => $control(Coa::ACCOUNTS_RECEIVABLE, false, null) === $control(Coa::ACCOUNTS_RECEIVABLE, false, 'customer_id'),
            'payablesMatch' => $control(Coa::ACCOUNTS_PAYABLE, true, null) === $control(Coa::ACCOUNTS_PAYABLE, true, 'supplier_id'),
            'untracedEntries' => $untraced,
        ];
    }
}
