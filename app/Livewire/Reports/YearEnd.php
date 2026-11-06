<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Domain\Money;
use App\Services\Reporting\YearEndService;
use App\Support\Clock;
use App\View\Format;
use Illuminate\View\View;
use Livewire\Attributes\Title;

/**
 * The old (app)/reports/year-end/page.tsx: the statements an accountant is
 * given, written to be printed (the controls are no-print). Every figure is
 * the pack's; the rows below only put the old page's signs on them (costs
 * shown as negatives), as its negate() did.
 */
#[Title('Year-end pack')]
final class YearEnd extends ReportPage
{
    public function render(): View
    {
        $years = YearEndService::availableFinancialYears();
        $requested = is_string($this->params['year'] ?? null) ? $this->params['year'] : null;
        $chosen = $years[0];
        foreach ($years as $year) {
            if ((string) $year->startYear === $requested) {
                $chosen = $year;
                break;
            }
        }

        $pack = YearEndService::getYearEndPack($chosen->startYear);
        $pl = $pack['profitAndLoss'];
        $ppl = $pack['previousProfitAndLoss'];
        $bs = $pack['balanceSheet'];
        $pbs = $pack['previousBalanceSheet'];
        $neg = Money::negate(...);
        $row = fn (string $label, int $value, ?int $previous = null, bool $bold = false, bool $indent = false, bool $rule = false): array => compact('label', 'value', 'previous', 'bold', 'indent', 'rule');
        $previousExpense = array_column($ppl['expenses'], 'amount', 'accountId');

        $profitAndLoss = array_values(array_filter([
            $row('Sales', $pl['salesRevenue'], $ppl['salesRevenue']),
            $pl['salesDiscounts'] !== 0 ? $row('Less discounts', $neg($pl['salesDiscounts']), $neg($ppl['salesDiscounts']), indent: true) : null,
            $pl['salesReturns'] !== 0 ? $row('Less returns', $neg($pl['salesReturns']), $neg($ppl['salesReturns']), indent: true) : null,
            $row('Net sales', $pl['netSales'], $ppl['netSales'], rule: true),
            $row('Cost of goods sold', $neg($pl['costOfGoodsSold']), $neg($ppl['costOfGoodsSold'])),
            $row('Gross profit', $pl['grossProfit'], $ppl['grossProfit'], bold: true, rule: true),
            $pl['totalOtherIncome'] !== 0 ? $row('Other income', $pl['totalOtherIncome'], $ppl['totalOtherIncome']) : null,
            ...array_map(fn (array $line): array => $row($line['name'], $neg($line['amount']), $neg($previousExpense[$line['accountId']] ?? 0), indent: true), $pl['expenses']),
            $row('Total expenses', $neg($pl['totalExpenses']), $neg($ppl['totalExpenses']), rule: true),
            $row('Net profit for the year', $pl['netProfit'], $ppl['netProfit'], bold: true, rule: true),
        ]));

        $balanceSheet = array_values(array_filter([
            $row('Cash, mobile money and bank', $bs['totalCash'], $pbs['totalCash']),
            $row('Owed by customers', $bs['receivables'], $pbs['receivables']),
            $row('Stock on hand', $bs['inventory'], $pbs['inventory']),
            ...array_map(fn (array $line): array => $row($line['name'], $line['amount'], indent: true), $bs['otherAssets']),
            $row('Total assets', $bs['totalAssets'], $pbs['totalAssets'], bold: true, rule: true),
            $row('Owed to suppliers', $bs['payables'], $pbs['payables']),
            $bs['taxPayable'] !== 0 ? $row('Tax payable', $bs['taxPayable'], $pbs['taxPayable']) : null,
            $row('Total liabilities', $bs['totalLiabilities'], $pbs['totalLiabilities'], bold: true, rule: true),
            $row("Owner's stake", $bs['totalEquity'], $pbs['totalEquity'], bold: true),
            $row("Total liabilities and owner's stake", $bs['totalLiabilitiesAndEquity'], $pbs['totalLiabilitiesAndEquity'], bold: true, rule: true),
        ]));

        $equity = $pack['equity'];
        $equityRows = array_values(array_filter([
            $row('Balance at '.Format::date($pack['previous']->end), $equity['openingEquity']),
            $row('Capital introduced', $equity['capitalIntroduced'], indent: true),
            $row('Drawings', $neg($equity['drawings']), indent: true),
            $equity['openingBalancesRecognised'] !== 0 ? $row('Opening balances brought in', $equity['openingBalancesRecognised'], indent: true) : null,
            $row('Profit for the year', $equity['profitForYear'], indent: true),
            $row('Balance at '.Format::date($pack['year']->end), $equity['closingEquity'], bold: true, rule: true),
        ]));

        return view('livewire.reports.year-end', [
            'years' => $years,
            'requested' => $requested,
            'pack' => $pack,
            'currency' => $pack['shop']['currencyCode'],
            'today' => Clock::today(),
            'profitAndLoss' => $profitAndLoss,
            'balanceSheet' => $balanceSheet,
            'equityRows' => $equityRows,
            'startMonthName' => date('F', mktime(0, 0, 0, (int) substr($pack['year']->start, 5, 2), 1)),
        ]);
    }
}
