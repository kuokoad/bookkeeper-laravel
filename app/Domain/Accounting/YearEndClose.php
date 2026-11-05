<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Errors\ValidationError;
use App\Domain\Money;

/**
 * The closing entry for a financial year: every revenue, contra-revenue,
 * expense and drawings account swept to zero, and what is left carried to
 * Retained Earnings. Each account is closed from whichever side clears it,
 * including one sitting on the wrong side of itself. A port of the old
 * src/domain/accounting/year-end-close.ts.
 *
 * Accounts are arrays: ['accountId' => int, 'code' => string, 'name' => string, 'balance' => int],
 * where balance is on the account's natural side.
 */
final class YearEndClose
{
    /**
     * @param  list<array>  $revenue
     * @param  list<array>  $contraRevenue
     * @param  list<array>  $expenses
     * @param  list<array>  $drawings
     * @return array{lines: list<DraftLine>, profit: int, totalRevenue: int, grossRevenue: int, totalContraRevenue: int, totalExpenses: int, totalDrawings: int, netToRetainedEarnings: int}
     */
    public static function buildClosingEntry(array $revenue, array $contraRevenue, array $expenses, array $drawings, int $retainedEarningsAccountId): array
    {
        $lines = [];
        foreach ([[$revenue, 'credit'], [$contraRevenue, 'debit'], [$expenses, 'debit'], [$drawings, 'debit']] as [$accounts, $naturalSide]) {
            foreach ($accounts as $account) {
                $line = self::closeOut($account, $naturalSide);
                if ($line !== null) {
                    $lines[] = $line;
                }
            }
        }

        $balances = fn (array $accounts): int => Money::sum(array_column($accounts, 'balance'));
        $grossRevenue = $balances($revenue);
        $totalContraRevenue = $balances($contraRevenue);
        $totalRevenue = Money::subtract($grossRevenue, $totalContraRevenue);
        $totalExpenses = $balances($expenses);
        $totalDrawings = $balances($drawings);
        $profit = Money::subtract($totalRevenue, $totalExpenses);
        $netToRetainedEarnings = Money::subtract($profit, $totalDrawings);

        if ($lines === []) {
            throw new ValidationError('There is nothing to close: no revenue, expenses or drawings were recorded in this year.');
        }

        if ($netToRetainedEarnings !== 0) {
            $amount = Money::absolute($netToRetainedEarnings);
            $lines[] = $netToRetainedEarnings > 0
                ? Journal::credit($retainedEarningsAccountId, $amount, ['description' => 'Profit for the year, less drawings'])
                : Journal::debit($retainedEarningsAccountId, $amount, ['description' => 'Loss for the year, plus drawings']);
        }

        return compact('lines', 'profit', 'totalRevenue', 'grossRevenue', 'totalContraRevenue', 'totalExpenses', 'totalDrawings', 'netToRetainedEarnings');
    }

    private static function closeOut(array $account, string $naturalSide): ?DraftLine
    {
        if ($account['balance'] === 0) {
            return null;
        }

        $amount = Money::absolute($account['balance']);
        $closeWithDebit = $naturalSide === 'credit' ? $account['balance'] > 0 : $account['balance'] < 0;
        $options = ['description' => "Close {$account['name']} for the year"];

        return $closeWithDebit
            ? Journal::debit($account['accountId'], $amount, $options)
            : Journal::credit($account['accountId'], $amount, $options);
    }
}
