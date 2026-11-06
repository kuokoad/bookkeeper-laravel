<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Domain\Accounting\ChartOfAccounts as Coa;
use App\Domain\Accounting\Journal;
use App\Domain\Money;
use App\Enums\AccountType;
use Illuminate\Support\Facades\DB;

/**
 * The three financial statements, all read from the same journal lines. A
 * port of the old reporting/financial.service.ts.
 *
 * A period is ['from' => date, 'to' => date], inclusive.
 */
final class FinancialService
{
    public const FLOW_LABELS = [
        'SALE' => 'Money taken on sales',
        'SALE_RETURN' => 'Refunds to customers',
        'CUSTOMER_PAYMENT' => 'Customer debt payments',
        'PURCHASE' => 'Paid for stock',
        'PURCHASE_RETURN' => 'Refunds from suppliers',
        'SUPPLIER_PAYMENT' => 'Paid suppliers',
        'EXPENSE' => 'Running costs',
        'INCOME' => 'Other income',
        'CAPITAL' => 'Owner put money in',
        'DRAWINGS' => 'Owner took money out',
        // Only shown for a single account; shop-wide it is left out (see getCashFlow).
        'TRANSFER' => 'Moved between your own accounts',
        'RECONCILIATION' => 'Cash count differences',
        'OPENING_BALANCE' => 'Opening balances',
        'REVERSAL' => 'Reversals',
    ];

    /**
     * Totals per account with the balance signed for its type; excludeClosing
     * applies on its own too, as in getAccountBalances since D4.
     *
     * @return list<array{id: int, code: string, name: string, type: string, parentId: ?int, debit: int, credit: int, balance: int}>
     */
    private static function loadAccountTotals(array $range): array
    {
        return array_map(fn (array $a): array => [
            'id' => $a['accountId'],
            'code' => $a['code'],
            'name' => $a['name'],
            'type' => $a['type'],
            'parentId' => $a['parentId'],
            'debit' => $a['totalDebit'],
            'credit' => $a['totalCredit'],
            'balance' => Journal::signedBalance(AccountType::from($a['type']), $a['totalDebit'], $a['totalCredit']),
        ], BalancesService::loadTotals($range));
    }

    /** Leaf accounts matching a predicate with a non-zero balance, as report lines. */
    private static function linesOfType(array $totals, callable $predicate): array
    {
        $parentIds = array_flip(array_filter(array_column($totals, 'parentId'), fn (?int $id): bool => $id !== null));
        $lines = [];
        foreach ($totals as $account) {
            if (! $predicate($account) || isset($parentIds[$account['id']]) || $account['balance'] === 0) {
                continue;
            }
            $lines[] = ['accountId' => $account['id'], 'code' => $account['code'], 'name' => $account['name'], 'amount' => $account['balance']];
        }

        return $lines;
    }

    /** A code and everything filed beneath it. */
    private static function isUnder(array $byId, array $account, string $ancestorCode): bool
    {
        $current = $account;
        while ($current !== null) {
            if ($current['code'] === $ancestorCode) {
                return true;
            }
            $current = $current['parentId'] === null ? null : ($byId[$current['parentId']] ?? null);
        }

        return false;
    }

    private static function byId(array $totals): array
    {
        return array_column($totals, null, 'id');
    }

    private static function balanceOf(array $totals, string $code): int
    {
        foreach ($totals as $account) {
            if ($account['code'] === $code) {
                return $account['balance'];
            }
        }

        return 0;
    }

    /** @param  array{from: string, to: string}  $period */
    public static function getProfitAndLoss(array $period): array
    {
        return self::buildProfitAndLoss(self::loadAccountTotals([...$period, 'excludeClosing' => true]), $period);
    }

    /** The P&L from totals already loaded (the balance sheet reuses its own). */
    private static function buildProfitAndLoss(array $totals, array $period): array
    {
        $byId = self::byId($totals);
        $salesRevenue = self::balanceOf($totals, Coa::SALES_REVENUE);
        $salesDiscounts = self::balanceOf($totals, Coa::SALES_DISCOUNTS);
        $salesReturns = self::balanceOf($totals, Coa::SALES_RETURNS);
        $netSales = Money::subtract($salesRevenue, Money::add($salesDiscounts, $salesReturns));

        $otherIncome = self::linesOfType($totals, fn (array $a): bool => $a['type'] === AccountType::Revenue->value
            && $a['code'] !== Coa::SALES_REVENUE
            && self::isUnder($byId, $a, Coa::OTHER_INCOME));
        $totalOtherIncome = Money::sum(array_column($otherIncome, 'amount'));
        $totalRevenue = Money::add($netSales, $totalOtherIncome);

        $costOfGoodsSold = self::balanceOf($totals, Coa::COST_OF_GOODS_SOLD);
        $grossProfit = Money::subtract($netSales, $costOfGoodsSold);

        $expenses = self::linesOfType($totals, fn (array $a): bool => $a['type'] === AccountType::Expense->value);
        $totalExpenses = Money::sum(array_column($expenses, 'amount'));
        $netProfit = Money::subtract(Money::add($grossProfit, $totalOtherIncome), $totalExpenses);

        return [
            'period' => $period,
            'salesRevenue' => $salesRevenue,
            'salesDiscounts' => $salesDiscounts,
            'salesReturns' => $salesReturns,
            'netSales' => $netSales,
            'otherIncome' => $otherIncome,
            'totalOtherIncome' => $totalOtherIncome,
            'totalRevenue' => $totalRevenue,
            'costOfGoodsSold' => $costOfGoodsSold,
            'grossProfit' => $grossProfit,
            'grossMarginBp' => $netSales === 0 ? null : Money::mulDiv($grossProfit, 10_000, $netSales),
            'expenses' => $expenses,
            'totalExpenses' => $totalExpenses,
            'netProfit' => $netProfit,
            'netMarginBp' => $totalRevenue === 0 ? null : Money::mulDiv($netProfit, 10_000, $totalRevenue),
        ];
    }

    public static function getBalanceSheet(string $asAt): array
    {
        // Deliberately WITH closing entries: they are how profit reaches equity.
        $totals = self::loadAccountTotals(['to' => $asAt]);
        $byId = self::byId($totals);
        $isCash = fn (array $a): bool => self::isUnder($byId, $a, Coa::CASH) || self::isUnder($byId, $a, Coa::MOBILE_MONEY) || self::isUnder($byId, $a, Coa::BANK);

        $cashAccounts = self::linesOfType($totals, fn (array $a): bool => $a['type'] === AccountType::Asset->value && $isCash($a));
        $totalCash = Money::sum(array_column($cashAccounts, 'amount'));

        $receivableSplit = SubledgerSplit::getReceivablesSplit($asAt);
        $payableSplit = SubledgerSplit::getPayablesSplit($asAt);
        $receivables = $receivableSplit['owed'];
        $customerCredits = $receivableSplit['inCredit'];
        $supplierAdvances = $payableSplit['inCredit'];
        $inventory = self::balanceOf($totals, Coa::INVENTORY);

        $otherAssets = self::linesOfType($totals, fn (array $a): bool => $a['type'] === AccountType::Asset->value
            && ! $isCash($a) && $a['code'] !== Coa::ACCOUNTS_RECEIVABLE && $a['code'] !== Coa::INVENTORY);
        $totalAssets = Money::sum([$totalCash, $receivables, $supplierAdvances, $inventory, Money::sum(array_column($otherAssets, 'amount'))]);

        $payables = $payableSplit['owed'];
        $taxPayable = self::balanceOf($totals, Coa::TAX_PAYABLE);
        $otherLiabilities = self::linesOfType($totals, fn (array $a): bool => $a['type'] === AccountType::Liability->value
            && $a['code'] !== Coa::ACCOUNTS_PAYABLE && $a['code'] !== Coa::TAX_PAYABLE);
        $totalLiabilities = Money::sum([$payables, $customerCredits, $taxPayable, Money::sum(array_column($otherLiabilities, 'amount'))]);

        $ownersCapital = self::balanceOf($totals, Coa::OWNERS_CAPITAL);
        $drawings = self::balanceOf($totals, Coa::OWNERS_DRAWINGS);
        $openingBalanceEquity = self::balanceOf($totals, Coa::OPENING_BALANCE_EQUITY);

        // Posted retained earnings plus all-time profit not yet closed: each year counted once.
        $allTime = self::buildProfitAndLoss($totals, ['from' => '0000-01-01', 'to' => $asAt]);
        $retainedEarnings = Money::add(self::balanceOf($totals, Coa::RETAINED_EARNINGS), $allTime['netProfit']);

        $totalEquity = Money::sum([$ownersCapital, $openingBalanceEquity, $retainedEarnings, -$drawings]);
        $totalLiabilitiesAndEquity = Money::add($totalLiabilities, $totalEquity);
        $difference = Money::subtract($totalAssets, $totalLiabilitiesAndEquity);

        return [
            'asAt' => $asAt,
            'cashAccounts' => $cashAccounts,
            'totalCash' => $totalCash,
            'receivables' => $receivables,
            'customerCredits' => $customerCredits,
            'inventory' => $inventory,
            'otherAssets' => $otherAssets,
            'totalAssets' => $totalAssets,
            'payables' => $payables,
            'supplierAdvances' => $supplierAdvances,
            'taxPayable' => $taxPayable,
            'otherLiabilities' => $otherLiabilities,
            'totalLiabilities' => $totalLiabilities,
            'ownersCapital' => $ownersCapital,
            'drawings' => $drawings,
            'openingBalanceEquity' => $openingBalanceEquity,
            'retainedEarnings' => $retainedEarnings,
            'totalEquity' => $totalEquity,
            'totalLiabilitiesAndEquity' => $totalLiabilitiesAndEquity,
            'balances' => $difference === 0,
            'difference' => $difference,
        ];
    }

    /**
     * Where the money actually went: only lines on a payment account count.
     * Shop-wide, transfers are left out (money changing pockets).
     *
     * @param  array{from: string, to: string}  $period
     */
    public static function getCashFlow(array $period, ?int $paymentAccountId = null): array
    {
        $lines = fn () => DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->whereNotNull('journal_lines.payment_account_id')
            ->when($paymentAccountId !== null, fn ($q) => $q->where('journal_lines.payment_account_id', $paymentAccountId));
        $sums = 'COALESCE(SUM(journal_lines.debit_minor), 0) AS d, COALESCE(SUM(journal_lines.credit_minor), 0) AS c';

        $opening = $lines()->where('journal_entries.entry_date', '<', $period['from'])->selectRaw($sums)->first();
        $openingBalance = (int) $opening->d - (int) $opening->c;

        $rows = $lines()->whereBetween('journal_entries.entry_date', [$period['from'], $period['to']])
            ->groupBy('journal_entries.source_type')->orderBy('journal_entries.source_type')
            ->selectRaw("journal_entries.source_type, {$sums}")->get();

        $shopWide = $paymentAccountId === null;
        $flow = [];
        foreach ($rows as $row) {
            if ($shopWide && $row->source_type === 'TRANSFER') {
                continue;
            }
            $in = (int) $row->d;
            $out = (int) $row->c;
            if ($in === 0 && $out === 0) {
                continue;
            }
            $flow[] = ['sourceType' => $row->source_type, 'label' => self::FLOW_LABELS[$row->source_type] ?? $row->source_type, 'inMinor' => $in, 'outMinor' => $out, 'net' => $in - $out];
        }
        usort($flow, fn (array $a, array $b): int => $b['net'] <=> $a['net']);

        $totalIn = Money::sum(array_column($flow, 'inMinor'));
        $totalOut = Money::sum(array_column($flow, 'outMinor'));
        $netMovement = Money::subtract($totalIn, $totalOut);
        $closingBalance = Money::add($openingBalance, $netMovement);

        // Per account, in two grouped queries rather than two per account.
        $before = [];
        $during = [];
        $perAccount = fn () => DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->whereNotNull('journal_lines.payment_account_id')
            ->when($paymentAccountId !== null, fn ($q) => $q->where('journal_lines.payment_account_id', $paymentAccountId))
            ->groupBy('journal_lines.payment_account_id')->selectRaw("journal_lines.payment_account_id AS id, {$sums}");
        foreach ($perAccount()->where('journal_entries.entry_date', '<', $period['from'])->get() as $r) {
            $before[(int) $r->id] = $r;
        }
        foreach ($perAccount()->whereBetween('journal_entries.entry_date', [$period['from'], $period['to']])->get() as $r) {
            $during[(int) $r->id] = $r;
        }

        $byAccount = DB::table('payment_accounts')
            ->when($paymentAccountId !== null, fn ($q) => $q->where('payment_accounts.id', $paymentAccountId))
            ->orderBy('payment_accounts.sort_order')->orderBy('payment_accounts.id')
            ->get(['payment_accounts.id', 'payment_accounts.name'])
            ->map(function (object $a) use ($before, $during): array {
                $id = (int) $a->id;
                $opening = isset($before[$id]) ? (int) $before[$id]->d - (int) $before[$id]->c : 0;
                $in = isset($during[$id]) ? (int) $during[$id]->d : 0;
                $out = isset($during[$id]) ? (int) $during[$id]->c : 0;

                return ['id' => $id, 'name' => (string) $a->name, 'opening' => $opening, 'in' => $in, 'out' => $out, 'closing' => $opening + $in - $out];
            })->all();

        return [
            'period' => $period,
            'openingBalance' => $openingBalance,
            'lines' => $flow,
            'totalIn' => $totalIn,
            'totalOut' => $totalOut,
            'netMovement' => $netMovement,
            'closingBalance' => $closingBalance,
            'reconciles' => Money::add($openingBalance, $netMovement) === $closingBalance,
            'byAccount' => $byAccount,
        ];
    }

    /** Profit for a period in one number, for the dashboard. */
    public static function getNetProfit(array $period): int
    {
        return self::getProfitAndLoss($period)['netProfit'];
    }
}
