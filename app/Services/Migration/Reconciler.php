<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Inventory\Costing;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\FinancialService;
use App\Services\Reporting\LedgerService;
use App\Services\Reporting\OperationsService;
use App\Services\Reporting\TaxReturnService;
use App\Services\Reporting\YearEndService;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Proves the new books hold the old figures: zero differences is the only
 * pass. Reads the old figures straight from a copy of the SQLite database and
 * the new ones from MySQL, and compares them to the pesewa.
 *
 * Version 1 (Phase 2) checks every column of every row of every table, then
 * the figures: the trial balance per account, the running stock pair per product (cached
 * and replayed from the stock ledger), receivables per customer, payables per
 * supplier and tax held per account.
 *
 * Version 2 (Phase 7) then reads the reports through the NEW reporting
 * services, for every financial year in the data and as at the latest date
 * (or --as-at): the trial balance, the P&L, the balance sheet with its
 * per-account lines, receivables and payables ageing, the tax return per
 * component, the cash flow per source and per payment account, sales by day
 * and the stock valuation, each against the same figures worked out from the
 * old SQLite copy by OldReports. Where an owner decision changed a compared
 * figure, OldReports computes the decided rule (D9's document counts; D8's
 * payables ageing, identical on old data, which has no due dates). The
 * figures D5, D6, D7 and D11 change (dashboard windows, money trend,
 * purchases by product, margins) are not among those compared.
 */
final class Reconciler
{
    /** @var list<string> */
    private array $differences = [];

    /** @var array<string, int> */
    private array $checked = [];

    /** @return array{differences: list<string>, checked: array<string, int>} */
    public function reconcile(string $path, ?string $asAt = null): array
    {
        $old = OldDatabase::open($path);

        $this->contents($old);
        $this->trialBalance($old, $asAt);
        $this->stock($old);
        $this->subledger($old, ChartOfAccounts::ACCOUNTS_RECEIVABLE, 'customer_id', 'receivables', $asAt);
        $this->subledger($old, ChartOfAccounts::ACCOUNTS_PAYABLE, 'supplier_id', 'payables', $asAt);
        $this->taxHeld($old, $asAt);
        $this->reports($old, $asAt);

        return ['differences' => $this->differences, 'checked' => $this->checked];
    }

    /**
     * Every row of every table, every column, to the byte. Streams both sides
     * in primary-key order. The new side is read back through MySQL's own
     * conversions (DATETIME to milliseconds with UNIX_TIMESTAMP, BLOB to hex),
     * not through the importer's code, so a wrong conversion there shows here.
     */
    private function contents(PDO $old): void
    {
        $newTables = array_map(fn (object $r): string => $r->t, DB::select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'));
        $rows = 0;

        foreach (OldDatabase::tables($old) as $table) {
            if (! in_array($table, $newTables, true)) {
                $this->differences[] = "table {$table}: in the old database, missing here";

                continue;
            }

            $types = [];
            foreach (DB::select('SELECT COLUMN_NAME AS c, DATA_TYPE AS d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) as $c) {
                $types[$c->c] = $c->d;
            }
            $oldColumns = array_column($old->query('PRAGMA table_info("'.str_replace('"', '""', $table).'")')->fetchAll(), 'name');
            $missing = array_diff($oldColumns, array_keys($types));
            if ($missing !== []) {
                $this->differences[] = "table {$table}: old columns missing here: ".implode(', ', $missing);

                continue;
            }

            $key = in_array('id', $oldColumns, true) ? 'id' : $oldColumns[0];
            $select = implode(', ', array_map(fn (string $c): string => match ($types[$c]) {
                'datetime', 'timestamp' => "CAST(ROUND(UNIX_TIMESTAMP(`{$c}`) * 1000) AS SIGNED) AS `{$c}`",
                'blob', 'mediumblob', 'longblob', 'binary', 'varbinary' => "HEX(`{$c}`) AS `{$c}`",
                default => "`{$c}`",
            }, $oldColumns));
            $binary = array_filter($oldColumns, fn (string $c): bool => in_array($types[$c], ['blob', 'mediumblob', 'longblob', 'binary', 'varbinary'], true));

            $oldCursor = $old->query('SELECT * FROM "'.str_replace('"', '""', $table).'" ORDER BY "'.$key.'"');
            $newCursor = DB::cursor("SELECT {$select} FROM `{$table}` ORDER BY `{$key}`");
            $newIterator = $newCursor; // a Generator: valid(), current(), next()
            $mismatches = 0;

            foreach ($oldCursor as $oldRow) {
                $rows++;
                $newRow = $newIterator->valid() ? (array) $newIterator->current() : null;
                $newIterator->next();
                if ($newRow === null) {
                    $this->differences[] = "{$table} {$key}={$oldRow[$key]}: missing here";
                    $mismatches++;

                    continue;
                }
                foreach ($oldColumns as $column) {
                    $before = in_array($column, $binary, true) && $oldRow[$column] !== null ? strtoupper(bin2hex($oldRow[$column])) : $oldRow[$column];
                    if ($this->normalise($before) !== $this->normalise($newRow[$column])) {
                        if (++$mismatches <= 5) {
                            $this->differences[] = "{$table} {$key}={$oldRow[$key]} {$column}: old ".var_export($before, true).', new '.var_export($newRow[$column], true);
                        }
                    }
                }
            }
            while ($newIterator->valid()) {
                $this->differences[] = "{$table} {$key}=".((array) $newIterator->current())[$key].': here, but not in the old database';
                $newIterator->next();
            }
            if ($mismatches > 5) {
                $this->differences[] = "{$table}: ".($mismatches - 5).' more difference(s) not listed';
            }
        }

        $this->checked['tables'] = count(OldDatabase::tables($old));
        $this->checked['rows, every column compared'] = $rows;
    }

    private function normalise(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /** Total debits and credits per account code, as at a date when given. */
    private function trialBalance(PDO $old, ?string $asAt): void
    {
        $sql = 'SELECT a.code AS code, SUM(l.debit_minor) AS d, SUM(l.credit_minor) AS c
            FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN accounts a ON a.id = l.account_id
            WHERE (? IS NULL OR e.entry_date <= ?) GROUP BY a.code';

        $oldRows = $this->oldRows($old, $sql, [$asAt, $asAt]);
        $newRows = DB::select($sql, [$asAt, $asAt]);

        $this->compareKeyed('trial balance', $this->keyed($oldRows, 'code'), $this->keyed($newRows, 'code'));
        $this->checked['accounts'] = count($oldRows);
    }

    /** The running pair (Q, V) per product: the cache, and a replay of the ledger from row 1. */
    private function stock(PDO $old): void
    {
        $oldProducts = $this->oldRows($old, 'SELECT id, qty_on_hand_milli AS q, stock_value_minor AS v FROM products', []);
        $newProducts = DB::table('products')->get(['id', 'qty_on_hand_milli as q', 'stock_value_minor as v'])->all();
        $this->compareKeyed('stock on hand', $this->keyed($oldProducts, 'id'), $this->keyed($newProducts, 'id'));

        foreach ($newProducts as $product) {
            $movements = DB::table('stock_ledger')->where('stock_ledger.product_id', $product->id)->orderBy('stock_ledger.id')
                ->get(['qty_in_milli', 'qty_out_milli', 'total_cost_minor'])
                ->map(fn (object $m): array => ['qtyIn' => (int) $m->qty_in_milli, 'qtyOut' => (int) $m->qty_out_milli, 'totalCost' => (int) $m->total_cost_minor]);
            $replayed = Costing::replay($movements);
            // A consistency check of the books themselves: the cached pair is what the ledger replays to.
            $this->compare("product {$product->id} cache vs its own stock ledger, quantity", (int) $product->q, $replayed->qty);
            $this->compare("product {$product->id} cache vs its own stock ledger, value", (int) $product->v, $replayed->value);
        }
        $this->checked['products'] = count($newProducts);
    }

    /** What each customer owes (or each supplier is owed), from the control account's tagged lines. */
    private function subledger(PDO $old, string $code, string $party, string $label, ?string $asAt): void
    {
        $sql = "SELECT l.{$party} AS party, SUM(l.debit_minor) - SUM(l.credit_minor) AS balance
            FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN accounts a ON a.id = l.account_id
            WHERE a.code = ? AND l.{$party} IS NOT NULL AND (? IS NULL OR e.entry_date <= ?)
            GROUP BY l.{$party}";

        $oldRows = $this->oldRows($old, $sql, [$code, $asAt, $asAt]);
        $newRows = DB::select($sql, [$code, $asAt, $asAt]);
        $this->compareKeyed($label, $this->keyed($oldRows, 'party'), $this->keyed($newRows, 'party'));
        $this->checked[$label] = count($oldRows);
    }

    /** Net tax held per component account. */
    private function taxHeld(PDO $old, ?string $asAt): void
    {
        $sql = 'SELECT a.code AS code, SUM(l.credit_minor) - SUM(l.debit_minor) AS held
            FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN accounts a ON a.id = l.account_id
            JOIN tax_components t ON t.gl_account_id = a.id
            WHERE (? IS NULL OR e.entry_date <= ?) GROUP BY a.code';

        $oldRows = $this->oldRows($old, $sql, [$asAt, $asAt]);
        $newRows = DB::select($sql, [$asAt, $asAt]);
        $this->compareKeyed('tax held', $this->keyed($oldRows, 'code'), $this->keyed($newRows, 'code'));
    }

    /** Version 2: the reports, through the new services, against the old figures from plain SQL. */
    private function reports(PDO $old, ?string $asAt): void
    {
        $years = OldReports::years($old);
        $newYears = YearEndService::availableFinancialYears();
        $this->compare('financial years', count($years), count($newYears));
        foreach ($years as $i => $year) {
            $this->compare("financial year {$year['label']} start year", $year['startYear'], isset($newYears[$i]) ? $newYears[$i]->startYear : null);
        }
        $latest = $asAt ?? OldReports::latestDate($old);
        $figures = 0;
        $check = function (string $label, array $oldFigures, array $newFigures) use (&$figures): void {
            ksort($oldFigures);
            ksort($newFigures);
            foreach (array_unique([...array_keys($oldFigures), ...array_keys($newFigures)]) as $key) {
                $this->compare("{$label} {$key}", $oldFigures[$key] ?? null, $newFigures[$key] ?? null);
                $figures++;
            }
        };

        $dates = array_map(fn (array $year): string => $year['end'], $years);
        if ($latest !== null) {
            $dates[] = $latest;
        }
        foreach (array_unique($dates) as $date) {
            $check("trial balance at {$date}", OldReports::trialBalance($old, $date), $this->newTrialBalance($date));
            $check("balance sheet at {$date}", OldReports::balanceSheet($old, $date), $this->newBalanceSheet($date));
            foreach (['receivables', 'payables'] as $side) {
                $check("{$side} ageing at {$date}", OldReports::ageing($old, $side, $date), $this->newAgeing($side, $date));
            }
        }
        foreach ($years as ['label' => $label, 'start' => $from, 'end' => $to]) {
            $check("P&L {$label}", OldReports::profitAndLoss($old, $from, $to), $this->newProfitAndLoss($from, $to));
            $check("tax return {$label}", OldReports::taxReturn($old, $from, $to), $this->newTaxReturn($from, $to));
            $check("cash flow {$label}", OldReports::cashFlow($old, $from, $to), $this->newCashFlow($from, $to));
            $check("sales by day {$label}", OldReports::salesByDay($old, $from, $to), $this->newSalesByDay($from, $to));
        }
        $check('stock valuation', OldReports::stockValuation($old), $this->newStockValuation());

        $this->checked['financial years, each with its P&L, tax return, cash flow and sales by day'] = count($years);
        $this->checked['report dates (each year end and the latest), each with its trial balance, balance sheet and ageing'] = count(array_unique($dates));
        $this->checked['report figures'] = $figures;
    }

    private function newTrialBalance(string $to): array
    {
        $lines = [];
        foreach (BalancesService::getTrialBalance(['to' => $to])['lines'] as $line) {
            $lines[$line['code']] = $line['debit'] - $line['credit'];
        }

        return $lines;
    }

    private function newProfitAndLoss(string $from, string $to): array
    {
        $pl = FinancialService::getProfitAndLoss(['from' => $from, 'to' => $to]);
        $figures = array_intersect_key($pl, array_flip(['salesRevenue', 'salesDiscounts', 'salesReturns', 'netSales', 'totalOtherIncome', 'costOfGoodsSold', 'grossProfit', 'totalExpenses', 'netProfit']));
        foreach ($pl['otherIncome'] as $line) {
            $figures["other income {$line['code']}"] = $line['amount'];
        }
        foreach ($pl['expenses'] as $line) {
            $figures["expense {$line['code']}"] = $line['amount'];
        }

        return $figures;
    }

    private function newBalanceSheet(string $asAt): array
    {
        $sheet = FinancialService::getBalanceSheet($asAt);
        $figures = array_intersect_key($sheet, array_flip([
            'totalCash', 'receivables', 'customerCredits', 'inventory', 'totalAssets', 'payables', 'supplierAdvances', 'taxPayable',
            'totalLiabilities', 'ownersCapital', 'drawings', 'openingBalanceEquity', 'retainedEarnings', 'totalEquity', 'difference',
        ]));
        foreach (['cash' => 'cashAccounts', 'other asset' => 'otherAssets', 'other liability' => 'otherLiabilities'] as $section => $key) {
            foreach ($sheet[$key] as $line) {
                $figures["{$section} {$line['code']}"] = $line['amount'];
            }
        }

        return $figures;
    }

    private function newCashFlow(string $from, string $to): array
    {
        $flow = FinancialService::getCashFlow(['from' => $from, 'to' => $to]);
        $figures = ['opening' => $flow['openingBalance'], 'total in' => $flow['totalIn'], 'total out' => $flow['totalOut'], 'closing' => $flow['closingBalance']];
        foreach ($flow['lines'] as $line) {
            $figures["{$line['sourceType']} in"] = $line['inMinor'];
            $figures["{$line['sourceType']} out"] = $line['outMinor'];
        }
        foreach ($flow['byAccount'] as $account) {
            foreach (['opening', 'in', 'out', 'closing'] as $field) {
                $figures["account {$account['id']} {$field}"] = $account[$field];
            }
        }

        return $figures;
    }

    private function newSalesByDay(string $from, string $to): array
    {
        $figures = [];
        foreach (OperationsService::getSalesByDay(['from' => $from, 'to' => $to]) as $day) {
            $figures["{$day['businessDate']} receipts"] = $day['saleCount'];
            $figures["{$day['businessDate']} total"] = $day['total'];
            $figures["{$day['businessDate']} tax"] = $day['tax'];
            $figures["{$day['businessDate']} cost"] = $day['cogs'];
        }

        return $figures;
    }

    private function newStockValuation(): array
    {
        $valuation = OperationsService::getStockValuation();
        $figures = ['total cost' => $valuation['totalCostValue'], 'total retail' => $valuation['totalRetailValue']];
        foreach ($valuation['rows'] as $row) {
            $figures["product {$row['productId']} qty"] = $row['qtyOnHand'];
            $figures["product {$row['productId']} cost"] = $row['stockValue'];
            $figures["product {$row['productId']} retail"] = $row['retailValue'];
        }

        return $figures;
    }

    private function newAgeing(string $side, string $asAt): array
    {
        $flat = [];
        foreach ($side === 'receivables' ? LedgerService::getReceivablesAgeing($asAt) : LedgerService::getPayablesAgeing($asAt) as $row) {
            foreach (['total', 'current', 'days1to30', 'days31to60', 'days61to90', 'over90'] as $bucket) {
                if ($row[$bucket] !== 0) {
                    $flat["{$row['partyId']} {$bucket}"] = $row[$bucket];
                }
            }
        }

        return $flat;
    }

    private function newTaxReturn(string $from, string $to): array
    {
        $tax = TaxReturnService::getTaxReturn(['from' => $from, 'to' => $to]);
        $figures = [
            'taxable sales' => $tax['taxableSalesMinor'],
            'taxable purchases' => $tax['taxablePurchasesMinor'],
            'sales documents' => $tax['saleCount'],
            'purchases documents' => $tax['purchaseCount'],
        ];
        foreach ($tax['components'] as $c) {
            $figures["{$c['code']} output"] = $c['outputMinor'];
            $figures["{$c['code']} recoverable input"] = $c['recoverableInputMinor'];
            $figures["{$c['code']} non-recoverable input"] = $c['nonRecoverableInputMinor'];
            $figures["{$c['code']} net"] = $c['netMinor'];
        }

        return $figures;
    }

    private function oldRows(PDO $old, string $sql, array $bindings): array
    {
        $statement = $old->prepare($sql);
        $statement->execute($bindings);

        return array_map(fn (array $r): object => (object) $r, $statement->fetchAll());
    }

    /** @return array<string, array<string, int>> key => other columns as integers */
    private function keyed(array $rows, string $key): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $values = (array) $row;
            $id = (string) $values[$key];
            unset($values[$key]);
            $keyed[$id] = array_map(fn (mixed $v): int => (int) $v, $values);
        }
        ksort($keyed);

        return $keyed;
    }

    private function compareKeyed(string $label, array $old, array $new): void
    {
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            foreach (array_unique([...array_keys($old[$key] ?? []), ...array_keys($new[$key] ?? [])]) as $column) {
                $this->compare("{$label} {$key} {$column}", $old[$key][$column] ?? null, $new[$key][$column] ?? null);
            }
        }
    }

    private function compare(string $what, ?int $old, ?int $new): void
    {
        if ($old !== $new) {
            $this->differences[] = "{$what}: old ".var_export($old, true).', new '.var_export($new, true);
        }
    }
}
