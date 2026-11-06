<?php

declare(strict_types=1);

namespace App\Reports\Csv;

use App\Domain\JsCompat;
use App\Services\InventoryService;
use App\Services\Reporting\FinancialService;
use App\Services\Reporting\LedgerService;
use App\Services\Reporting\OperationsService;
use App\Services\Reporting\TaxReturnService;
use App\Services\Reporting\YearEndService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use App\Support\Filters\ListFilters;

/**
 * CSV of a report, from the SAME service reads as the report page. A port
 * of the old app/api/reports/[report]/route.ts, without the HTTP: a
 * controller checks the reports permission, throttles, and sends
 * download()'s body (null is the old 404 "Unknown report").
 */
final class ReportExport
{
    /**
     * The from/to the old route fell back to: junk becomes this month so far.
     *
     * @return array{from: string, to: string}
     */
    public static function period(array $params, string $today): array
    {
        $from = $params['from'] ?? null;
        $to = $params['to'] ?? null;

        return [
            'from' => $from !== null && Filters::isValidBusinessDate($from) ? $from : substr($today, 0, 7).'-01',
            'to' => $to !== null && Filters::isValidBusinessDate($to) ? $to : $today,
        ];
    }

    /** @return array{filename: string, body: string, headers: list<string>, rows: list<list<string>>}|null */
    public static function download(string $report, array $query, ?string $today = null): ?array
    {
        $today ??= Clock::today();
        $table = self::table($report, $query, $today);
        if ($table === null) {
            return null;
        }
        $range = self::period(Csv::firstValues($query), $today);

        return [...$table, 'filename' => Csv::filename($report, $range['from'], $range['to']), 'body' => Csv::toCsv($table['headers'], $table['rows'])];
    }

    /** @return array{headers: list<string>, rows: list<list<string>>}|null */
    public static function table(string $report, array $query, string $today): ?array
    {
        $params = Csv::firstValues($query);
        $range = self::period($params, $today);
        $m = Csv::money(...);

        switch ($report) {
            case 'profit-and-loss':
                $pl = FinancialService::getProfitAndLoss($range);

                return ['headers' => ['Item', 'Amount'], 'rows' => [
                    ['Sales', $m($pl['salesRevenue'])],
                    ['Less: discounts given', $m($pl['salesDiscounts'])],
                    ['Less: goods returned', $m($pl['salesReturns'])],
                    ['Net sales', $m($pl['netSales'])],
                    ['Cost of goods sold', $m($pl['costOfGoodsSold'])],
                    ['Gross profit', $m($pl['grossProfit'])],
                    ...array_map(fn (array $l): array => ["Other income: {$l['name']}", $m($l['amount'])], $pl['otherIncome']),
                    ['Total other income', $m($pl['totalOtherIncome'])],
                    ...array_map(fn (array $l): array => ["Cost: {$l['name']}", $m($l['amount'])], $pl['expenses']),
                    ['Total running costs', $m($pl['totalExpenses'])],
                    ['Net profit', $m($pl['netProfit'])],
                ]];

            case 'balance-sheet':
                $asAt = isset($params['asAt']) && Filters::isValidBusinessDate($params['asAt']) ? $params['asAt'] : $today;
                $bs = FinancialService::getBalanceSheet($asAt);

                return ['headers' => ['Item', 'Amount'], 'rows' => [
                    ...array_map(fn (array $l): array => ["Asset: {$l['name']}", $m($l['amount'])], $bs['cashAccounts']),
                    ['Asset: Money customers owe you', $m($bs['receivables'])],
                    ['Asset: Stock on the shelf', $m($bs['inventory'])],
                    ...array_map(fn (array $l): array => ["Asset: {$l['name']}", $m($l['amount'])], $bs['otherAssets']),
                    ['Total assets', $m($bs['totalAssets'])],
                    ['Liability: Money you owe suppliers', $m($bs['payables'])],
                    ['Liability: Tax not yet paid', $m($bs['taxPayable'])],
                    ...array_map(fn (array $l): array => ["Liability: {$l['name']}", $m($l['amount'])], $bs['otherLiabilities']),
                    ['Total liabilities', $m($bs['totalLiabilities'])],
                    ['Equity: Money you put in', $m($bs['ownersCapital'])],
                    ['Equity: Less money you took out', $m($bs['drawings'])],
                    ['Equity: Opening balances', $m($bs['openingBalanceEquity'])],
                    ['Equity: Profit kept in the business', $m($bs['retainedEarnings'])],
                    ['Total equity', $m($bs['totalEquity'])],
                    ['Total liabilities and equity', $m($bs['totalLiabilitiesAndEquity'])],
                    ['Balances?', $bs['balances'] ? 'yes' : 'NO'],
                ]];

            case 'cash-flow':
                $flow = FinancialService::getCashFlow($range, ListFilters::parseCashFlowFilters($params, $range['to'])['accountId']);

                return ['headers' => ['Item', 'In', 'Out', 'Net'], 'rows' => [
                    ['Opening balance', '', '', $m($flow['openingBalance'])],
                    ...array_map(fn (array $l): array => [$l['label'], $m($l['inMinor']), $m($l['outMinor']), $m($l['net'])], $flow['lines']),
                    ['Total', $m($flow['totalIn']), $m($flow['totalOut']), $m($flow['netMovement'])],
                    ['Closing balance', '', '', $m($flow['closingBalance'])],
                ]];

            case 'sales':
                return self::sales(ListFilters::parseSalesReportFilters($params, $range['to'])['filters']);

            case 'purchases':
                $q = ListFilters::parsePurchaseReportFilters($params, $range['to'])['filters'];

                return ['headers' => ['Section', 'Name', 'Count/Qty', 'Total'], 'rows' => [
                    ...array_map(fn (array $r): array => ['By day', $r['businessDate'], (string) $r['purchaseCount'], $m($r['total'])], OperationsService::getPurchasesByDay($q)),
                    ...array_map(fn (array $r): array => ['By supplier', $r['supplierName'], (string) $r['purchaseCount'], $m($r['total'])], OperationsService::getPurchasesBySupplier($q)),
                    ...array_map(fn (array $r): array => ['By product', $r['productName'], Csv::qty($r['qtyBought']), $m($r['total'])], OperationsService::getPurchasesByProduct($q)),
                ]];

            case 'inventory':
                $q = ListFilters::parseInventoryReportFilters($params)['filters'];
                $moved = array_column(OperationsService::getStockMovementSummary($range, $q), null, 'productId');

                return [
                    'headers' => ['Product', 'SKU', 'Category', 'Unit', 'On hand', 'On hand (alternate unit)', 'Average cost', 'Value at cost', 'Selling price', 'Value at retail', 'Qty in (period)', 'Qty out (period)', 'Status'],
                    'rows' => array_map(fn (array $r): array => [
                        $r['productName'], $r['sku'] ?? '', $r['categoryName'] ?? '', $r['unit'], Csv::qty($r['qtyOnHand']),
                        Csv::altQty($r['qtyOnHand'], $r['unit'], $r['altUnit'], $r['altFactorMilli']),
                        $m($r['averageCost']), $m($r['stockValue']), $m($r['sellingPrice']), $m($r['retailValue']),
                        isset($moved[$r['productId']]) ? Csv::qty($moved[$r['productId']]['qtyIn']) : '0',
                        isset($moved[$r['productId']]) ? Csv::qty($moved[$r['productId']]['qtyOut']) : '0',
                        $r['outOfStock'] ? 'out of stock' : ($r['lowStock'] ? 'low' : 'ok'),
                    ], OperationsService::getStockValuation($q)['rows']),
                ];

            case 'tax':
                $tax = TaxReturnService::getTaxReturn($range);

                return ['headers' => ['Tax', 'Code', 'Charged on sales', 'Reclaimable on purchases', 'Not reclaimable', 'Net'], 'rows' => [
                    ...array_map(fn (array $c): array => [$c['name'], $c['code'], $m($c['outputMinor']), $m($c['recoverableInputMinor']), $m($c['nonRecoverableInputMinor']), $m($c['netMinor'])], $tax['components']),
                    ['Total', '', $m($tax['totalOutput']), $m($tax['totalRecoverableInput']), $m($tax['totalNonRecoverableInput']), $m($tax['netPayable'])],
                    [],
                    ['Sales the tax was charged on', '', $m($tax['taxableSalesMinor'])],
                    ['Purchases the tax was paid on', '', $m($tax['taxablePurchasesMinor'])],
                    ['Sales documents', '', (string) $tax['saleCount']],
                    ['Purchase documents', '', (string) $tax['purchaseCount']],
                ]];

            case 'expiry':
                // Batches, never money: a batch carries no cost.
                return [
                    'headers' => ['Batch', 'Product', 'SKU', 'Unit', 'Expires', 'Days left', 'Remaining', 'Supplier', 'Received', 'Status'],
                    'rows' => array_map(fn (array $r): array => [
                        $r['batchRef'], $r['productName'], $r['sku'] ?? '', $r['unit'], $r['expiryDate'] ?? '',
                        $r['daysLeft'] === null ? '' : (string) $r['daysLeft'], Csv::qty($r['qtyMilli']), $r['supplierName'] ?? '', $r['receivedDate'] ?? '',
                        $r['expiryDate'] === null ? 'no date' : ($r['daysLeft'] < 0 ? 'expired' : 'in date'),
                    ], InventoryService::listAllOpenBatches($range['to'])),
                ];

            case 'receivables':
            case 'payables':
                $ageing = $report === 'receivables' ? LedgerService::getReceivablesAgeing($range['to']) : LedgerService::getPayablesAgeing($range['to']);

                return [
                    'headers' => [$report === 'receivables' ? 'Customer' : 'Supplier', 'Phone', 'Oldest', 'Not due', '1-30 days', '31-60 days', '61-90 days', 'Over 90 days', 'Total'],
                    'rows' => array_map(fn (array $r): array => [
                        $r['partyName'], $r['phone'] ?? '', $r['oldestDate'] ?? '', $m($r['current']), $m($r['days1to30']), $m($r['days31to60']), $m($r['days61to90']), $m($r['over90']), $m($r['total']),
                    ], $ageing),
                ];

            case 'year-end':
                return self::yearEnd($params);
        }

        return null;
    }

    /** The line-versus-receipt switch the page makes: a product or category filter reads lines. */
    private static function sales(array $q): array
    {
        $m = Csv::money(...);
        $byLine = isset($q['productId']) || isset($q['categoryId']);
        $net = 'Revenue net of tax and discounts';
        $basis = $byLine ? $net : 'Takings with tax';

        $byDay = $byLine
            ? array_map(fn (array $r): array => ['businessDate' => $r['businessDate'], 'saleCount' => $r['saleCount'], 'total' => $r['revenue'], 'cogs' => $r['cost'], 'profit' => $r['profit']], OperationsService::getSalesLinesByDay($q))
            : OperationsService::getSalesByDay($q);
        $byCustomer = $byLine
            ? array_map(fn (array $r): array => ['customerName' => $r['customerName'], 'saleCount' => $r['saleCount'], 'total' => $r['revenue'], 'profit' => $r['profit']], OperationsService::getSalesLinesByCustomer($q))
            : OperationsService::getSalesByCustomer($q);
        $byMethod = $byLine ? [] : OperationsService::getSalesByPaymentMethod($q);

        return [
            'headers' => ['Section', 'Name', 'Category', 'Count/Qty', 'Amount', 'Cost', 'Profit', 'Amount is'],
            'rows' => [
                ...array_map(fn (array $r): array => ['By day', $r['businessDate'], '', (string) $r['saleCount'], $m($r['total']), $m($r['cogs']), $m($r['profit']), $basis], $byDay),
                ...array_map(fn (array $r): array => ['By product', $r['productName'], $r['categoryName'] ?? '', Csv::qty($r['qtySold']), $m($r['revenue']), $m($r['cost']), $m($r['profit']), $net], OperationsService::getSalesByProduct($q)),
                ...array_map(fn (array $r): array => ['By category', $r['categoryName'], '', '', $m($r['revenue']), $m($r['cost']), $m($r['profit']), $net], OperationsService::getSalesByCategory($q)),
                ...array_map(fn (array $r): array => ['By customer', $r['customerName'], '', (string) $r['saleCount'], $m($r['total']), '', $m($r['profit']), $basis], $byCustomer),
                ...array_map(fn (array $r): array => ['By payment method', $r['accountName'], '', '', $m($r['received']), '', '', 'Money received'], $byMethod),
            ],
        ];
    }

    /** The whole pack as one file of labelled sections, read top to bottom. */
    private static function yearEnd(array $params): ?array
    {
        $years = YearEndService::availableFinancialYears();
        // As the old Number(year): JS-trimmed decimal text, so "2025.0", "+2025" and " 2025 " all mean 2025 (hex not handled).
        $text = JsCompat::trim((string) ($params['year'] ?? ''));
        $requested = preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/D', $text) === 1 ? (float) $text : null;
        $chosen = $years[0] ?? null;
        foreach ($years as $year) {
            if ($requested !== null && (float) $year->startYear === $requested) {
                $chosen = $year;
                break;
            }
        }
        if ($chosen === null) {
            return null;
        }

        $pack = YearEndService::getYearEndPack($chosen->startYear);
        $pl = $pack['profitAndLoss'];
        $ppl = $pack['previousProfitAndLoss'];
        $bs = $pack['balanceSheet'];
        $pbs = $pack['previousBalanceSheet'];
        $m = Csv::money(...);
        $gap = ['', '', ''];
        $heading = fn (string $title): array => [$title, $pack['year']->label, $pack['previous']->label];
        $line = fn (string $label, int $now, ?int $before = null): array => [$label, $m($now), $before === null ? '' : $m($before)];
        $yesNo = fn (bool $ok): string => $ok ? 'yes' : 'NO';
        $previousExpense = fn (int $accountId): int => array_column($ppl['expenses'], 'amount', 'accountId')[$accountId] ?? 0;

        $rows = [
            [$pack['shop']['name'], '', ''],
            ["Financial statements for {$pack['year']->label}", '', ''],
            ["{$pack['year']->start} to {$pack['year']->end}", "All figures in {$pack['shop']['currencyCode']}", ''],
            [$pack['isProvisional'] ? 'PROVISIONAL — the year has not finished' : 'Final', "{$pack['entryCount']} journal entries", ''],
            $gap,

            $heading('PROFIT AND LOSS'),
            $line('Sales', $pl['salesRevenue'], $ppl['salesRevenue']),
            $line('Less discounts', $pl['salesDiscounts'], $ppl['salesDiscounts']),
            $line('Less returns', $pl['salesReturns'], $ppl['salesReturns']),
            $line('Net sales', $pl['netSales'], $ppl['netSales']),
            $line('Cost of goods sold', $pl['costOfGoodsSold'], $ppl['costOfGoodsSold']),
            $line('Gross profit', $pl['grossProfit'], $ppl['grossProfit']),
            $line('Other income', $pl['totalOtherIncome'], $ppl['totalOtherIncome']),
            ...array_map(fn (array $e): array => $line("Expense: {$e['name']}", $e['amount'], $previousExpense($e['accountId'])), $pl['expenses']),
            $line('Total expenses', $pl['totalExpenses'], $ppl['totalExpenses']),
            $line('NET PROFIT', $pl['netProfit'], $ppl['netProfit']),
            $gap,

            $heading('BALANCE SHEET'),
            $line('Cash, mobile money and bank', $bs['totalCash'], $pbs['totalCash']),
            $line('Owed by customers', $bs['receivables'], $pbs['receivables']),
            $line('Stock on hand', $bs['inventory'], $pbs['inventory']),
            $line('TOTAL ASSETS', $bs['totalAssets'], $pbs['totalAssets']),
            $line('Owed to suppliers', $bs['payables'], $pbs['payables']),
            $line('Tax payable', $bs['taxPayable'], $pbs['taxPayable']),
            $line('TOTAL LIABILITIES', $bs['totalLiabilities'], $pbs['totalLiabilities']),
            $line("OWNER'S STAKE", $bs['totalEquity'], $pbs['totalEquity']),
            $gap,

            $heading("MOVEMENT IN THE OWNER'S STAKE"),
            $line("Balance at {$pack['previous']->end}", $pack['equity']['openingEquity']),
            $line('Capital introduced', $pack['equity']['capitalIntroduced']),
            $line('Drawings', $pack['equity']['drawings']),
            $line('Opening balances brought in', $pack['equity']['openingBalancesRecognised']),
            $line('Profit for the year', $pack['equity']['profitForYear']),
            $line("Balance at {$pack['year']->end}", $pack['equity']['closingEquity']),
            $gap,

            ['CASH FLOW', 'In', 'Out'],
            ["Balance at {$pack['previous']->end}", $m($pack['cashFlow']['openingBalance']), ''],
            ...array_map(fn (array $f): array => [$f['label'], $m($f['inMinor']), $m($f['outMinor'])], $pack['cashFlow']['lines']),
            ['Total', $m($pack['cashFlow']['totalIn']), $m($pack['cashFlow']['totalOut'])],
            ["Balance at {$pack['year']->end}", $m($pack['cashFlow']['closingBalance']), ''],
            $gap,

            ['OWED BY CUSTOMERS', 'Over 90 days', 'Total'],
            ...array_map(fn (array $r): array => [$r['partyName'], $m($r['over90']), $m($r['total'])], $pack['receivables']),
            ['Total owed to the shop', '', $m($bs['receivables'])],
            $gap,

            ['OWED TO SUPPLIERS', 'Over 90 days', 'Total'],
            ...array_map(fn (array $r): array => [$r['partyName'], $m($r['over90']), $m($r['total'])], $pack['payables']),
            ['Total owed by the shop', '', $m($bs['payables'])],
            $gap,

            ['TRIAL BALANCE', 'Debit', 'Credit'],
            ...array_map(fn (array $r): array => ["{$r['code']} {$r['name']}", $m($r['debit']), $m($r['credit'])], $pack['trialBalance']['lines']),
            ['Total', $m($pack['trialBalance']['totalDebit']), $m($pack['trialBalance']['totalCredit'])],
            $gap,

            ['CHECKS PERFORMED', '', ''],
            ['Trial balance balances', $yesNo($pack['integrity']['trialBalanced']), ''],
            ['Balance sheet balances', $yesNo($bs['balances']), ''],
            ['Owed by customers agrees', $yesNo($pack['integrity']['receivablesMatch']), ''],
            ['Owed to suppliers agrees', $yesNo($pack['integrity']['payablesMatch']), ''],
            ["Owner's stake reconciles", $yesNo($pack['equity']['reconciles']), ''],
            ['Cash flow reconciles', $yesNo($pack['cashFlow']['reconciles']), ''],
            ['Year closed to further entries', $pack['isLocked'] ? 'yes' : 'no', ''],
        ];

        return ['headers' => ['Item', $pack['year']->label, $pack['previous']->label], 'rows' => $rows];
    }
}
