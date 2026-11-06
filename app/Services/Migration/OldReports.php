<?php

declare(strict_types=1);

namespace App\Services\Migration;

use DateTimeImmutable;
use PDO;

/**
 * The old reports' figures, worked out afresh from a copy of the old SQLite
 * database with plain SQL and the old rules, never through the new services:
 * closing entries out of the P&L and in the balance sheet, the subledger
 * split by each party's sign, receivables aged from the due date (payables
 * from the purchase date) as at a date with voids netted, and the tax return
 * from every tax row (its document counts as standing at the period end, D9), the cash flow from the payment-account lines, sales by
 * day and the stock valuation. Even the financial years are plain date
 * arithmetic here. Reconcile v2 compares these with the new reports.
 */
final class OldReports
{
    private const DEBIT_NORMAL = ['ASSET', 'EXPENSE', 'COGS', 'CONTRA_EQUITY', 'CONTRA_REVENUE'];

    /**
     * Newest first: every year from the one holding the first entry to the one
     * holding the last, each starting on the 1st of the settings' start month.
     *
     * @return list<array{label: string, start: string, end: string, startYear: int}>
     */
    public static function years(PDO $old): array
    {
        $span = $old->query('SELECT MIN(entry_date) AS a, MAX(entry_date) AS b FROM journal_entries')->fetch();
        if ($span['a'] === null) {
            return [];
        }
        $month = (int) $old->query('SELECT financial_year_start_month FROM business_settings WHERE id = 1')->fetchColumn();
        $yearOf = fn (string $date): int => (int) substr($date, 5, 2) >= $month ? (int) substr($date, 0, 4) : (int) substr($date, 0, 4) - 1;

        $years = [];
        for ($y = $yearOf($span['b']); $y >= $yearOf($span['a']); $y--) {
            $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $month));
            $years[] = [
                'label' => $month === 1 ? (string) $y : sprintf('%d/%02d', $y, ($y + 1) % 100),
                'start' => $start->format('Y-m-d'),
                'end' => $start->modify('+1 year -1 day')->format('Y-m-d'),
                'startYear' => $y,
            ];
        }

        return $years;
    }

    public static function latestDate(PDO $old): ?string
    {
        $latest = $old->query('SELECT MAX(entry_date) FROM journal_entries')->fetchColumn();

        return $latest === null || $latest === false ? null : (string) $latest;
    }

    /** Every account with its debits, credits and natural-side balance over a window. */
    private static function accounts(PDO $old, ?string $from, ?string $to, bool $excludeClosing): array
    {
        $statement = $old->prepare(
            'SELECT a.id, a.code, a.type, a.parent_id,
                    (SELECT COUNT(*) FROM accounts c WHERE c.parent_id = a.id) AS children,
                    COALESCE(m.d, 0) AS d, COALESCE(m.c, 0) AS c
             FROM accounts a LEFT JOIN (
                 SELECT l.account_id, SUM(l.debit_minor) AS d, SUM(l.credit_minor) AS c
                 FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
                 WHERE (:f IS NULL OR e.entry_date >= :f) AND (:t IS NULL OR e.entry_date <= :t) AND (:x = 0 OR e.is_closing = 0)
                 GROUP BY l.account_id
             ) m ON m.account_id = a.id',
        );
        $statement->execute(['f' => $from, 't' => $to, 'x' => $excludeClosing ? 1 : 0]);

        $accounts = [];
        foreach ($statement->fetchAll() as $row) {
            $debit = (int) $row['d'];
            $credit = (int) $row['c'];
            $accounts[(int) $row['id']] = [
                'code' => (string) $row['code'],
                'type' => (string) $row['type'],
                'parent' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                'leaf' => (int) $row['children'] === 0,
                'net' => $debit - $credit,
                'balance' => in_array($row['type'], self::DEBIT_NORMAL, true) ? $debit - $credit : $credit - $debit,
            ];
        }

        return $accounts;
    }

    private static function under(array $accounts, int $id, string $code): bool
    {
        for ($at = $id; $at !== null; $at = $accounts[$at]['parent']) {
            if ($accounts[$at]['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    private static function code(array $accounts, string $code): int
    {
        foreach ($accounts as $account) {
            if ($account['code'] === $code) {
                return $account['balance'];
            }
        }

        return 0;
    }

    /** Leaf accounts the test keeps, with a non-zero balance, keyed by code. */
    private static function lines(array $accounts, callable $keep): array
    {
        $lines = [];
        foreach ($accounts as $id => $account) {
            if ($account['leaf'] && $account['balance'] !== 0 && $keep($id, $account)) {
                $lines[$account['code']] = $account['balance'];
            }
        }

        return $lines;
    }

    /** @return array<string, int> code => net debit (credit negative), leaf accounts, zeros left out */
    public static function trialBalance(PDO $old, string $to): array
    {
        $lines = [];
        foreach (self::accounts($old, null, $to, false) as $account) {
            if ($account['leaf'] && $account['net'] !== 0) {
                $lines[$account['code']] = $account['net'];
            }
        }

        return $lines;
    }

    /** @return array<string, int> the P&L's figures, and each other-income and expense line */
    public static function profitAndLoss(PDO $old, string $from, string $to): array
    {
        return self::profitFrom(self::accounts($old, $from, $to, true));
    }

    private static function profitFrom(array $accounts): array
    {
        $netSales = self::code($accounts, '4000') - self::code($accounts, '4100') - self::code($accounts, '4150');
        $otherIncome = self::lines($accounts, fn (int $id, array $a): bool => $a['type'] === 'REVENUE' && $a['code'] !== '4000' && self::under($accounts, $id, '4200'));
        $expenses = self::lines($accounts, fn (int $id, array $a): bool => $a['type'] === 'EXPENSE');
        $cogs = self::code($accounts, '5000');

        $figures = [
            'salesRevenue' => self::code($accounts, '4000'),
            'salesDiscounts' => self::code($accounts, '4100'),
            'salesReturns' => self::code($accounts, '4150'),
            'netSales' => $netSales,
            'totalOtherIncome' => array_sum($otherIncome),
            'costOfGoodsSold' => $cogs,
            'grossProfit' => $netSales - $cogs,
            'totalExpenses' => array_sum($expenses),
            'netProfit' => $netSales - $cogs + array_sum($otherIncome) - array_sum($expenses),
        ];
        foreach ($otherIncome as $code => $amount) {
            $figures["other income {$code}"] = $amount;
        }
        foreach ($expenses as $code => $amount) {
            $figures["expense {$code}"] = $amount;
        }

        return $figures;
    }

    /** What each party owes (or is owed) on a control account as at a date, split by sign. */
    private static function split(PDO $old, string $code, string $party, string $asAt, bool $debitNormal): array
    {
        $statement = $old->prepare("SELECT SUM(l.debit_minor) - SUM(l.credit_minor) AS net
            FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN accounts a ON a.id = l.account_id
            WHERE a.code = ? AND l.{$party} IS NOT NULL AND e.entry_date <= ? GROUP BY l.{$party}");
        $statement->execute([$code, $asAt]);
        $owed = 0;
        $inCredit = 0;
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $net) {
            $balance = $debitNormal ? (int) $net : -(int) $net;
            $balance > 0 ? $owed += $balance : $inCredit -= $balance;
        }

        return [$owed, $inCredit];
    }

    /** @return array<string, int> */
    public static function balanceSheet(PDO $old, string $asAt): array
    {
        $accounts = self::accounts($old, null, $asAt, false);
        $cash = fn (int $id): bool => self::under($accounts, $id, '1000') || self::under($accounts, $id, '1010') || self::under($accounts, $id, '1020');
        [$receivables, $customerCredits] = self::split($old, '1100', 'customer_id', $asAt, true);
        [$payables, $supplierAdvances] = self::split($old, '2000', 'supplier_id', $asAt, false);

        $cashLines = self::lines($accounts, fn (int $id, array $a): bool => $a['type'] === 'ASSET' && $cash($id));
        $otherAssetLines = self::lines($accounts, fn (int $id, array $a): bool => $a['type'] === 'ASSET' && ! $cash($id) && ! in_array($a['code'], ['1100', '1200'], true));
        $otherLiabilityLines = self::lines($accounts, fn (int $id, array $a): bool => $a['type'] === 'LIABILITY' && ! in_array($a['code'], ['2000', '2100'], true));
        $totalCash = array_sum($cashLines);
        $otherAssets = array_sum($otherAssetLines);
        $otherLiabilities = array_sum($otherLiabilityLines);

        $inventory = self::code($accounts, '1200');
        $taxPayable = self::code($accounts, '2100');
        $totalAssets = $totalCash + $receivables + $supplierAdvances + $inventory + $otherAssets;
        $totalLiabilities = $payables + $customerCredits + $taxPayable + $otherLiabilities;
        // Retained earnings as posted, plus all-time profit with the closing entries IN.
        $retainedEarnings = self::code($accounts, '3200') + self::profitFrom($accounts)['netProfit'];
        $totalEquity = self::code($accounts, '3000') + self::code($accounts, '3900') + $retainedEarnings - self::code($accounts, '3100');

        $figures = [
            'totalCash' => $totalCash,
            'receivables' => $receivables,
            'customerCredits' => $customerCredits,
            'inventory' => $inventory,
            'totalAssets' => $totalAssets,
            'payables' => $payables,
            'supplierAdvances' => $supplierAdvances,
            'taxPayable' => $taxPayable,
            'totalLiabilities' => $totalLiabilities,
            'ownersCapital' => self::code($accounts, '3000'),
            'drawings' => self::code($accounts, '3100'),
            'openingBalanceEquity' => self::code($accounts, '3900'),
            'retainedEarnings' => $retainedEarnings,
            'totalEquity' => $totalEquity,
            'difference' => $totalAssets - $totalLiabilities - $totalEquity,
        ];
        foreach (['cash' => $cashLines, 'other asset' => $otherAssetLines, 'other liability' => $otherLiabilityLines] as $section => $lines) {
            foreach ($lines as $code => $amount) {
                $figures["{$section} {$code}"] = $amount;
            }
        }

        return $figures;
    }

    /**
     * Money through the payment accounts over a period: opening, in and out
     * per source type (transfers left out shop-wide, as money changing
     * pockets), closing, and each payment account with its transfers in.
     *
     * @return array<string, int>
     */
    public static function cashFlow(PDO $old, string $from, string $to): array
    {
        $opening = $old->prepare('SELECT COALESCE(SUM(l.debit_minor) - SUM(l.credit_minor), 0) FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
            WHERE l.payment_account_id IS NOT NULL AND e.entry_date < ?');
        $opening->execute([$from]);
        $figures = ['opening' => (int) $opening->fetchColumn()];

        $bySource = $old->prepare("SELECT e.source_type, SUM(l.debit_minor), SUM(l.credit_minor) FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
            WHERE l.payment_account_id IS NOT NULL AND e.entry_date BETWEEN ? AND ? AND e.source_type <> 'TRANSFER' GROUP BY e.source_type");
        $bySource->execute([$from, $to]);
        $in = 0;
        $out = 0;
        foreach ($bySource->fetchAll(PDO::FETCH_NUM) as [$source, $debit, $credit]) {
            if ((int) $debit === 0 && (int) $credit === 0) {
                continue;
            }
            $figures["{$source} in"] = (int) $debit;
            $figures["{$source} out"] = (int) $credit;
            $in += (int) $debit;
            $out += (int) $credit;
        }
        $figures['total in'] = $in;
        $figures['total out'] = $out;
        $figures['closing'] = $figures['opening'] + $in - $out;

        $accounts = $old->prepare('SELECT p.id,
                COALESCE((SELECT SUM(l.debit_minor) - SUM(l.credit_minor) FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE l.payment_account_id = p.id AND e.entry_date < :f), 0),
                COALESCE((SELECT SUM(l.debit_minor) FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE l.payment_account_id = p.id AND e.entry_date BETWEEN :f AND :t), 0),
                COALESCE((SELECT SUM(l.credit_minor) FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE l.payment_account_id = p.id AND e.entry_date BETWEEN :f AND :t), 0)
            FROM payment_accounts p');
        $accounts->execute(['f' => $from, 't' => $to]);
        foreach ($accounts->fetchAll(PDO::FETCH_NUM) as [$id, $before, $debit, $credit]) {
            $figures["account {$id} opening"] = (int) $before;
            $figures["account {$id} in"] = (int) $debit;
            $figures["account {$id} out"] = (int) $credit;
            $figures["account {$id} closing"] = (int) $before + (int) $debit - (int) $credit;
        }

        return $figures;
    }

    /** @return array<string, int> per trading day: receipts (sales, not voids or returns), total, tax and cost */
    public static function salesByDay(PDO $old, string $from, string $to): array
    {
        $statement = $old->prepare("SELECT business_date, SUM(CASE WHEN kind = 'SALE' THEN 1 ELSE 0 END), SUM(total_minor), SUM(tax_minor), SUM(cogs_minor)
            FROM sales WHERE business_date BETWEEN ? AND ? GROUP BY business_date");
        $statement->execute([$from, $to]);
        $figures = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$date, $count, $total, $tax, $cogs]) {
            $figures["{$date} receipts"] = (int) $count;
            $figures["{$date} total"] = (int) $total;
            $figures["{$date} tax"] = (int) $tax;
            $figures["{$date} cost"] = (int) $cogs;
        }

        return $figures;
    }

    /**
     * Stock held now, per tracked product (archived ones while they hold
     * stock or value), at cost and at the selling price (Math.round of
     * price x qty / 1000, half towards +infinity).
     *
     * @return array<string, int>
     */
    public static function stockValuation(PDO $old): array
    {
        $figures = ['total cost' => 0, 'total retail' => 0];
        $rows = $old->query('SELECT id, qty_on_hand_milli, stock_value_minor, selling_price_minor FROM products
            WHERE (is_active = 1 OR qty_on_hand_milli <> 0 OR stock_value_minor <> 0) AND track_inventory = 1');
        foreach ($rows->fetchAll(PDO::FETCH_NUM) as [$id, $qty, $value, $price]) {
            $retail = (int) floor((int) $price * (int) $qty / 1000 + 0.5);
            $figures["product {$id} qty"] = (int) $qty;
            $figures["product {$id} cost"] = (int) $value;
            $figures["product {$id} retail"] = $retail;
            $figures['total cost'] += (int) $value;
            $figures['total retail'] += $retail;
        }

        return $figures;
    }

    /**
     * Ageing per party as at a date: each document's outstanding (a void
     * counts until its mirror is dated, payments by then, credited returns
     * on the sale they came off), bucketed by days past the due date for
     * sales (else the sale date) and past the purchase date for purchases.
     * D8 (owner decision, 2026-11-06) ages a purchase from its due date, else
     * its date; the old schema has no purchases.due_date and imported rows
     * keep NULL, so the purchase date IS the decided rule for this data.
     *
     * @return array<string, int> "party bucket" => amount, parties netting to zero left out
     */
    public static function ageing(PDO $old, string $side, string $asAt): array
    {
        [$doc, $party, $parties, $tender, $alloc, $payments, $voids, $returns, $agedFrom] = $side === 'receivables'
            ? ['sales', 'customer_id', 'customers', 'sale_payments', 'customer_payment_allocations', 'customer_payments', 'voids_sale_id', 'returns_sale_id', 'COALESCE(d.due_date, d.business_date)']
            : ['purchases', 'supplier_id', 'suppliers', 'purchase_payments', 'supplier_payment_allocations', 'supplier_payments', 'voids_purchase_id', 'returns_purchase_id', 'd.business_date'];
        $fk = $side === 'receivables' ? 'sale_id' : 'purchase_id';

        $statement = $old->prepare("WITH docs AS (
                SELECT s.id, s.total_minor, s.{$returns} AS returns_id, s.business_date, ".($side === 'receivables' ? 's.due_date' : 'NULL AS due_date').", s.{$party} AS party
                FROM {$doc} s
                WHERE s.{$voids} IS NULL AND s.business_date <= :d
                  AND (s.status = 'POSTED' OR NOT EXISTS (SELECT 1 FROM {$doc} m WHERE m.{$voids} = s.id AND m.business_date <= :d))
            ),
            tend AS (SELECT {$fk} AS id, SUM(amount_minor) AS t FROM {$tender} GROUP BY {$fk}),
            settled AS (SELECT a.{$fk} AS id, SUM(a.amount_minor) AS t FROM {$alloc} a JOIN {$payments} p ON p.id = a.payment_id
                        WHERE p.status = 'POSTED' AND p.business_date <= :d GROUP BY a.{$fk}),
            credited AS (SELECT r.returns_id AS id, SUM(COALESCE(tend.t, 0) - r.total_minor) AS c FROM docs r LEFT JOIN tend ON tend.id = r.id
                         WHERE r.returns_id IN (SELECT id FROM docs) GROUP BY r.returns_id)
            SELECT d.party, {$agedFrom} AS aged_from,
                   CASE WHEN d.returns_id IN (SELECT id FROM docs) THEN 0
                        ELSE d.total_minor - COALESCE(t.t, 0) - COALESCE(st.t, 0) - COALESCE(c.c, 0) END AS owed
            FROM docs d JOIN {$parties} p ON p.id = d.party
            LEFT JOIN tend t ON t.id = d.id LEFT JOIN settled st ON st.id = d.id LEFT JOIN credited c ON c.id = d.id");
        $statement->execute(['d' => $asAt]);

        $byParty = [];
        $day = new DateTimeImmutable($asAt);
        foreach ($statement->fetchAll() as $row) {
            $owed = (int) $row['owed'];
            if ($owed === 0) {
                continue;
            }
            $days = (int) (new DateTimeImmutable($row['aged_from']))->diff($day)->format('%r%a');
            $bucket = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => 'days1to30',
                $days <= 60 => 'days31to60',
                $days <= 90 => 'days61to90',
                default => 'over90',
            };
            $byParty[$row['party']][$bucket] = ($byParty[$row['party']][$bucket] ?? 0) + $owed;
            $byParty[$row['party']]['total'] = ($byParty[$row['party']]['total'] ?? 0) + $owed;
        }

        $flat = [];
        foreach ($byParty as $id => $buckets) {
            if ($buckets['total'] === 0) {
                continue;
            }
            foreach (array_filter($buckets) as $bucket => $amount) {
                $flat["{$id} {$bucket}"] = $amount;
            }
        }

        return $flat;
    }

    /** @return array<string, int> per component and the boxes beneath */
    public static function taxReturn(PDO $old, string $from, string $to): array
    {
        $figures = [];
        $output = $old->prepare('SELECT t.code, SUM(t.amount_minor) FROM sale_taxes t JOIN sales s ON s.id = t.sale_id WHERE s.business_date BETWEEN ? AND ? GROUP BY t.code');
        $output->execute([$from, $to]);
        foreach ($output->fetchAll(PDO::FETCH_NUM) as [$code, $amount]) {
            $figures["{$code} output"] = (int) $amount;
        }
        $input = $old->prepare('SELECT t.code, t.is_recoverable, SUM(t.amount_minor) FROM purchase_taxes t JOIN purchases p ON p.id = t.purchase_id WHERE p.business_date BETWEEN ? AND ? GROUP BY t.code, t.is_recoverable');
        $input->execute([$from, $to]);
        foreach ($input->fetchAll(PDO::FETCH_NUM) as [$code, $recoverable, $amount]) {
            $key = "{$code} ".((int) $recoverable === 1 ? 'recoverable input' : 'non-recoverable input');
            $figures[$key] = ($figures[$key] ?? 0) + (int) $amount;
        }
        foreach (array_unique(array_map(fn (string $k): string => explode(' ', $k)[0], array_keys($figures))) as $code) {
            $figures["{$code} output"] ??= 0;
            $figures["{$code} recoverable input"] ??= 0;
            $figures["{$code} non-recoverable input"] ??= 0;
            $figures["{$code} net"] = $figures["{$code} output"] - $figures["{$code} recoverable input"];
        }
        // D9 (owner decision, 2026-11-06): the value still nets every row; the count, for sales and purchases
        // alike, is the documents standing at the period end: never a VOID mirror, and an original only if
        // no void of it is dated on or before the end.
        foreach (['sales' => 'voids_sale_id', 'purchases' => 'voids_purchase_id'] as $table => $voids) {
            $box = $old->prepare("SELECT COALESCE(SUM(d.subtotal_minor - d.discount_minor), 0),
                    COUNT(CASE WHEN d.kind <> 'VOID' AND NOT EXISTS (SELECT 1 FROM {$table} m WHERE m.{$voids} = d.id AND m.business_date <= ?) THEN 1 END)
                FROM {$table} d WHERE d.business_date BETWEEN ? AND ?");
            $box->execute([$to, $from, $to]);
            [$value, $documents] = $box->fetch(PDO::FETCH_NUM);
            $figures["taxable {$table}"] = (int) $value;
            $figures["{$table} documents"] = (int) $documents;
        }

        return $figures;
    }
}
