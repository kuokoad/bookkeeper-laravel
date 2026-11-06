<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Domain\Accounting\Journal;
use App\Domain\Money;
use App\Enums\AccountType;
use Illuminate\Support\Facades\DB;

/**
 * Account balances, derived from the ledger every single time: no balance is
 * stored anywhere. A port of the old reporting/balances.service.ts.
 *
 * A range is ['from' => ?date, 'to' => ?date, 'excludeClosing' => ?bool],
 * inclusive business dates.
 */
final class BalancesService
{
    /**
     * Debits and credits per account, in chart order. Lines are summed in a
     * derived table first, then joined to the chart. With no conditions every
     * account is listed. With any condition the old query's WHERE
     * (journal_lines.id IS NULL OR journal_entries.id IS NOT NULL, old
     * balances.service.ts:80-84, ledger.service.ts:91-95, financial.service.ts:79-83)
     * is reproduced: an account that has lines, none of them in the window,
     * is left out; one that never had a line stays, at zero.
     *
     * $periodOnlyWhenDated reproduces the old getAccountBalances, which
     * applied its conditions only when a from or to was given: with
     * excludeClosing alone, closing entries still counted.
     *
     * @return list<array{accountId: int, code: string, name: string, type: string, parentId: ?int, isSystem: bool, isActive: bool, sortOrder: int, totalDebit: int, totalCredit: int, entryCount: int, childCount: int}>
     */
    public static function loadTotals(array $range = [], bool $periodOnlyWhenDated = false): array
    {
        $from = $range['from'] ?? null;
        $to = $range['to'] ?? null;
        $excludeClosing = (bool) ($range['excludeClosing'] ?? false);
        if ($periodOnlyWhenDated && $from === null && $to === null) {
            $excludeClosing = false;
        }

        $conditions = [];
        $bindings = [];
        if ($from !== null) {
            $conditions[] = 'journal_entries.entry_date >= ?';
            $bindings[] = $from;
        }
        if ($to !== null) {
            $conditions[] = 'journal_entries.entry_date <= ?';
            $bindings[] = $to;
        }
        if ($excludeClosing) {
            $conditions[] = 'journal_entries.is_closing = 0';
        }

        $allTime = 'SELECT journal_lines.account_id, SUM(journal_lines.debit_minor) AS d, SUM(journal_lines.credit_minor) AS c, COUNT(*) AS n
               FROM journal_lines GROUP BY journal_lines.account_id';
        $where = implode(' AND ', $conditions);
        $joined = fn (string $condition, string $sign): string => "SELECT journal_lines.account_id, {$sign}SUM(journal_lines.debit_minor) AS d, {$sign}SUM(journal_lines.credit_minor) AS c, {$sign}COUNT(*) AS n
               FROM journal_lines JOIN journal_entries ON journal_entries.id = journal_lines.entry_id
               WHERE {$condition} GROUP BY journal_lines.account_id";

        if ($conditions === []) {
            $moved = $allTime;
        } elseif (self::mostOfTheLedger($from, $to)) {
            // A window holding most of the ledger is cheaper as all time less what lies outside it:
            // the all-time sums read the covering index alone, and joining lines to entries costs per line.
            $moved = "SELECT both_sides.account_id, SUM(both_sides.d) AS d, SUM(both_sides.c) AS c, SUM(both_sides.n) AS n
               FROM ({$allTime} UNION ALL ".$joined("NOT ({$where})", '-').') AS both_sides GROUP BY both_sides.account_id';
        } else {
            $moved = $joined($where, '');
        }

        $keep = $conditions === [] ? '' : 'WHERE COALESCE(moved.n, 0) > 0
             OR NOT EXISTS (SELECT 1 FROM journal_lines AS any_line WHERE any_line.account_id = accounts.id)';

        $rows = DB::select(
            "SELECT accounts.id, accounts.code, accounts.name, accounts.type, accounts.parent_id, accounts.is_system, accounts.is_active, accounts.sort_order,
                    COALESCE(moved.d, 0) AS d, COALESCE(moved.c, 0) AS c, COALESCE(moved.n, 0) AS n,
                    (SELECT COUNT(*) FROM accounts AS child WHERE child.parent_id = accounts.id) AS children
             FROM accounts LEFT JOIN ({$moved}) AS moved ON moved.account_id = accounts.id
             {$keep}
             ORDER BY accounts.sort_order, accounts.code",
            $bindings,
        );

        return array_map(fn (object $r): array => [
            'accountId' => (int) $r->id,
            'code' => (string) $r->code,
            'name' => (string) $r->name,
            'type' => (string) $r->type,
            'parentId' => $r->parent_id === null ? null : (int) $r->parent_id,
            'isSystem' => (bool) $r->is_system,
            'isActive' => (bool) $r->is_active,
            'sortOrder' => (int) $r->sort_order,
            'totalDebit' => (int) $r->d,
            'totalCredit' => (int) $r->c,
            'entryCount' => (int) $r->n,
            'childCount' => (int) $r->children,
        ], $rows);
    }

    /** True when the entries dated in the window outnumber those outside it (counted on the date index). */
    private static function mostOfTheLedger(?string $from, ?string $to): bool
    {
        $counts = DB::table('journal_entries')->selectRaw(
            'COUNT(*) AS total, COALESCE(SUM(journal_entries.entry_date >= ? AND journal_entries.entry_date <= ?), 0) AS inside',
            [$from ?? '0000-01-01', $to ?? '9999-12-31'],
        )->first();

        return (int) $counts->inside * 2 > (int) $counts->total;
    }

    /** @return list<array{accountId: int, code: string, name: string, type: string, parentId: ?int, isHeader: bool, totalDebit: int, totalCredit: int, balance: int}> */
    public static function getAccountBalances(array $range = []): array
    {
        return array_map(fn (array $a): array => [
            'accountId' => $a['accountId'],
            'code' => $a['code'],
            'name' => $a['name'],
            'type' => $a['type'],
            'parentId' => $a['parentId'],
            'isHeader' => $a['childCount'] > 0,
            'totalDebit' => $a['totalDebit'],
            'totalCredit' => $a['totalCredit'],
            'balance' => Journal::signedBalance(AccountType::from($a['type']), $a['totalDebit'], $a['totalCredit']),
        ], self::loadTotals($range, periodOnlyWhenDated: true));
    }

    /** One account's balance on its normal side; a heading adds its direct children. */
    public static function getAccountBalanceByCode(string $code, array $range = []): int
    {
        $balances = self::getAccountBalances($range);
        $account = null;
        foreach ($balances as $entry) {
            if ($entry['code'] === $code) {
                $account = $entry;
                break;
            }
        }
        if ($account === null) {
            return 0;
        }
        if (! $account['isHeader']) {
            return $account['balance'];
        }

        $children = array_filter($balances, fn (array $e): bool => $e['parentId'] === $account['accountId']);

        return Money::sum([$account['balance'], ...array_column($children, 'balance')]);
    }

    /**
     * A trial balance of BALANCES: each leaf account once, its net on the side
     * it falls, accounts that net to zero left out.
     *
     * @return array{lines: list<array{accountId: int, code: string, name: string, type: string, debit: int, credit: int}>, totalDebit: int, totalCredit: int, difference: int, balanced: bool}
     */
    public static function getTrialBalance(array $range = []): array
    {
        $lines = [];
        foreach (self::getAccountBalances($range) as $account) {
            if ($account['isHeader']) {
                continue;
            }
            $net = $account['totalDebit'] - $account['totalCredit'];
            if ($net === 0) {
                continue;
            }
            $lines[] = [
                'accountId' => $account['accountId'],
                'code' => $account['code'],
                'name' => $account['name'],
                'type' => $account['type'],
                'debit' => $net > 0 ? $net : 0,
                'credit' => $net < 0 ? -$net : 0,
            ];
        }

        $totalDebit = Money::sum(array_column($lines, 'debit'));
        $totalCredit = Money::sum(array_column($lines, 'credit'));
        $difference = Money::subtract($totalDebit, $totalCredit);

        return ['lines' => $lines, 'totalDebit' => $totalDebit, 'totalCredit' => $totalCredit, 'difference' => $difference, 'balanced' => $difference === 0];
    }

    /**
     * What is in cash, MoMo and the bank: active payment accounts, in their order.
     *
     * @return list<array{id: int, name: string, kind: string, provider: ?string, glCode: string, balance: int}>
     */
    public static function getPaymentAccountBalances(array $range = []): array
    {
        $balances = [];
        foreach (self::getAccountBalances($range) as $entry) {
            $balances[$entry['accountId']] = $entry;
        }

        return DB::table('payment_accounts')->where('payment_accounts.is_active', true)
            ->orderBy('payment_accounts.sort_order')->orderBy('payment_accounts.id')
            ->get(['payment_accounts.id', 'payment_accounts.name', 'payment_accounts.kind', 'payment_accounts.provider', 'payment_accounts.gl_account_id'])
            ->map(fn (object $a): array => [
                'id' => (int) $a->id,
                'name' => (string) $a->name,
                'kind' => (string) $a->kind,
                'provider' => $a->provider,
                'glCode' => $balances[(int) $a->gl_account_id]['code'] ?? '',
                'balance' => $balances[(int) $a->gl_account_id]['balance'] ?? 0,
            ])->all();
    }

    /** Entries in the period: "genuinely zero" against "nothing recorded yet". */
    public static function countJournalEntries(array $range = []): int
    {
        $query = DB::table('journal_entries');
        if (($range['from'] ?? null) !== null) {
            $query->where('journal_entries.entry_date', '>=', $range['from']);
        }
        if (($range['to'] ?? null) !== null) {
            $query->where('journal_entries.entry_date', '<=', $range['to']);
        }
        if ($range['excludeClosing'] ?? false) {
            $query->where('journal_entries.is_closing', false);
        }

        return $query->count();
    }
}
