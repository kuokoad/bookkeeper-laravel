<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Services\InventoryService;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\FinancialService;
use App\Services\Reporting\LedgerService;
use App\Services\Reporting\MoneyTrend;
use App\Services\Reporting\OperationsService;
use App\Services\Reporting\TaxReturnService;
use Database\Seeders\CoreSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * How fast are the reports at a year of real trading? Builds a THROWAWAY
 * database at that volume (365 x 200 sales, each a sales row with two lines,
 * its tax and its tender, posted as five ledger lines: about 365k; plus 200
 * products x 250 stock movements), then times the old benchmark's
 * queries and every report service the app calls, median of 5. A port of
 * the old scripts/benchmark.ts. Its own database only: never the dev or
 * test database, never one holding a user account (a shop always has
 * one; the benchmark makes none), and dropped afterwards unless --keep.
 */
final class BenchmarkCommand extends Command
{
    protected $signature = 'nuna:benchmark
        {--days=365} {--sales=200} {--products=200} {--movements=250}
        {--database=nunabooks_benchmark : A throwaway database; its name must start with nunabooks_bench}
        {--keep : Leave the database in place afterwards}
        {--tax-inclusive : Shelf prices with the VAT inside: each line is gross, so every receipt is shared out like a discounted one}';

    protected $description = 'Time every report at a year of trading, on a throwaway database.';

    public function handle(): int
    {
        $database = (string) $this->option('database');
        $configured = (string) config('database.connections.mysql.database');
        if (! str_starts_with($database, 'nunabooks_bench') || $database === $configured || preg_match('/^\w+$/D', $database) !== 1) {
            $this->error("Refusing to benchmark on \"{$database}\": use a throwaway database whose name starts with nunabooks_bench.");

            return self::FAILURE;
        }
        $holdsUsers = DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE tables.table_schema = ? AND tables.table_name = ?', [$database, 'users'])->n > 0
            && DB::selectOne("SELECT EXISTS (SELECT 1 FROM `{$database}`.users) AS e")->e;
        if ($holdsUsers) {
            $this->error("Refusing to benchmark on \"{$database}\": it holds a user account, so it may be a real shop. The benchmark wipes its database.");

            return self::FAILURE;
        }
        [$days, $salesPerDay, $products, $movements] = array_map(fn (string $o): int => max(1, (int) $this->option($o)), ['days', 'sales', 'products', 'movements']);

        DB::statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin");
        config(['database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        DB::reconnect('mysql');

        try {
            Artisan::call('migrate:fresh', ['--force' => true]);
            Artisan::call('db:seed', ['--class' => CoreSeeder::class, '--force' => true]);
            $movementCount = $this->buildStock($products, $movements);
            $lineCount = $this->buildTrading($days, $salesPerDay);
            $this->line(sprintf('Built %s sales (entries) / %s ledger lines and %s stock movements.', number_format($days * $salesPerDay), number_format($lineCount), number_format($movementCount)));
            DB::statement('ANALYZE TABLE journal_entries, journal_lines, stock_ledger, products, accounts, sales, sale_items, sale_payments, sale_taxes, customers');
            $this->timeAll();
        } finally {
            if (! $this->option('keep')) {
                DB::statement("DROP DATABASE IF EXISTS `{$database}`");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Insert directly: a volume fixture, not a test of the posting path. Each
     * sale is a sales row with two lines, its VAT and (unless on credit) its
     * tender, posted as five balanced lines on the real accounts: till or
     * receivable, revenue, VAT, cost of sales, inventory. One sale in four
     * names a customer; one in twenty is on 30 days' credit.
     */
    private function buildTrading(int $days, int $salesPerDay): int
    {
        $code = fn (string $c): int => (int) DB::table('accounts')->where('code', $c)->value('id');
        [$receivable, $revenue, $vat, $cogs, $inventory] = array_map($code, [ChartOfAccounts::ACCOUNTS_RECEIVABLE, ChartOfAccounts::SALES_REVENUE, ChartOfAccounts::TAX_PAYABLE, ChartOfAccounts::COST_OF_GOODS_SOLD, ChartOfAccounts::INVENTORY]);
        $tills = DB::table('payment_accounts')->orderBy('payment_accounts.id')->get(['payment_accounts.id', 'payment_accounts.gl_account_id'])->all();
        $products = DB::table('products')->orderBy('products.id')->get(['products.id', 'products.name', 'products.unit'])->all();

        $at = '2025-01-01 12:00:00.000';
        DB::table('categories')->insert(array_map(fn (int $c): array => ['name' => "Bench category {$c}", 'created_at' => $at, 'updated_at' => $at], range(1, 12)));
        foreach (DB::table('categories')->orderBy('categories.id')->pluck('categories.id')->all() as $k => $categoryId) {
            DB::table('products')->whereRaw('MOD(products.id, 12) = ?', [$k])->update(['category_id' => $categoryId]);
        }
        DB::table('customers')->insert(array_map(fn (int $c): array => ['name' => "Bench customer {$c}", 'created_at' => $at, 'updated_at' => $at], range(1, 50)));
        $customers = DB::table('customers')->orderBy('customers.id')->pluck('customers.id')->all();

        $n = 0;
        $start = new \DateTimeImmutable('2025-01-01');
        for ($day = 0; $day < $days; $day++) {
            $date = $start->modify("+{$day} days");
            $businessDate = $date->format('Y-m-d');
            $due = $date->modify('+30 days')->format('Y-m-d');
            $at = "{$businessDate} 12:00:00.000";
            $inclusive = (bool) $this->option('tax-inclusive');
            Tx::run(function () use (&$n, $inclusive, $salesPerDay, $businessDate, $due, $at, $receivable, $revenue, $vat, $cogs, $inventory, $tills, $products, $customers): void {
                $first = $n + 1;
                $sales = [];
                for ($i = 0; $i < $salesPerDay; $i++) {
                    $s = ++$n;
                    $total = 500 + (($s * 137) % 20000);
                    $tax = intdiv($total * 15, 115);
                    $credit = $s % 20 === 0;
                    $sales[$s] = ['total' => $total, 'tax' => $tax, 'subtotal' => $total - $tax, 'cost' => intdiv(($total - $tax) * 6, 10), 'credit' => $credit,
                        'customer' => $s % 4 === 0 ? $customers[$s % count($customers)] : null, 'till' => $tills[$s % count($tills)]];
                }
                DB::table('sales')->insert(array_map(fn (int $s, array $x): array => ['receipt_no' => sprintf('RCT-%08d', $s), 'kind' => 'SALE', 'customer_id' => $x['customer'],
                    'terms_days' => $x['credit'] ? 30 : null, 'due_date' => $x['credit'] ? $due : null, 'subtotal_minor' => $x['subtotal'], 'tax_minor' => $x['tax'], 'total_minor' => $x['total'], 'tax_inclusive' => $inclusive,
                    'cogs_minor' => $x['cost'], 'business_date' => $businessDate, 'occurred_at' => $at, 'created_at' => $at, 'updated_at' => $at], array_keys($sales), $sales));
                $firstSale = (int) DB::table('sales')->where('receipt_no', sprintf('RCT-%08d', $first))->value('id');
                DB::table('journal_entries')->insert(array_map(fn (int $s): array => ['entry_no' => sprintf('JE-%08d', $s), 'entry_date' => $businessDate, 'occurred_at' => $at,
                    'source_type' => 'SALE', 'source_id' => $firstSale + $s - $first, 'memo' => 'Sale', 'created_at' => $at], array_keys($sales)));
                $firstEntry = (int) DB::table('journal_entries')->where('entry_no', sprintf('JE-%08d', $first))->value('id');
                DB::update('UPDATE sales SET sales.journal_entry_id = sales.id + ? WHERE sales.id BETWEEN ? AND ?', [$firstEntry - $firstSale, $firstSale, $firstSale + $n - $first]);

                $items = $payments = $taxes = $lines = [];
                foreach ($sales as $s => $x) {
                    $saleId = $firstSale + $s - $first;
                    $entryId = $firstEntry + $s - $first;
                    // Exclusive: the lines add up to the subtotal. Inclusive: to the total, tax inside.
                    $lineSum = $inclusive ? $x['total'] : $x['subtotal'];
                    $half = intdiv($lineSum, 2);
                    $halfCost = intdiv($x['cost'], 2);
                    foreach ([[1, $s, $half, $halfCost], [2, $s * 7 + 3, $lineSum - $half, $x['cost'] - $halfCost]] as [$lineNo, $pick, $amount, $cost]) {
                        $p = $products[$pick % count($products)];
                        $items[] = ['sale_id' => $saleId, 'line_no' => $lineNo, 'product_id' => $p->id, 'product_name' => $p->name, 'unit' => $p->unit, 'qty_milli' => 1000,
                            'unit_price_minor' => $amount, 'line_total_minor' => $amount, 'unit_cost_minor' => $cost, 'total_cost_minor' => $cost, 'created_at' => $at];
                    }
                    $taxes[] = ['sale_id' => $saleId, 'code' => 'VAT', 'name' => 'VAT', 'rate_bp' => 1_500, 'basis' => 'NET', 'amount_minor' => $x['tax'], 'created_at' => $at];
                    // On credit the receivable carries the customer; paid, the till carries its payment account.
                    $money = $x['credit']
                        ? ['account_id' => $receivable, 'payment_account_id' => null, 'customer_id' => $x['customer']]
                        : ['account_id' => (int) $x['till']->gl_account_id, 'payment_account_id' => (int) $x['till']->id, 'customer_id' => $x['customer']];
                    if (! $x['credit']) {
                        $payments[] = ['sale_id' => $saleId, 'payment_account_id' => $x['till']->id, 'amount_minor' => $x['total'], 'created_at' => $at];
                    }
                    $none = ['payment_account_id' => null, 'customer_id' => null];
                    foreach ([[$money, $x['total'], 0], [['account_id' => $revenue] + $none, 0, $x['subtotal']], [['account_id' => $vat] + $none, 0, $x['tax']],
                        [['account_id' => $cogs] + $none, $x['cost'], 0], [['account_id' => $inventory] + $none, 0, $x['cost']]] as $k => [$on, $debit, $credit]) {
                        $lines[] = ['entry_id' => $entryId, 'line_no' => $k + 1, ...$on, 'debit_minor' => $debit, 'credit_minor' => $credit, 'created_at' => $at];
                    }
                }
                foreach (['sale_items' => $items, 'sale_payments' => $payments, 'sale_taxes' => $taxes, 'journal_lines' => $lines] as $table => $rows) {
                    foreach (array_chunk($rows, 1000) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                }
            });
        }

        return DB::table('journal_lines')->count();
    }

    /** Big receipt, then one unit out at a time: every movement stays on the chain. */
    private function buildStock(int $products, int $movements): int
    {
        $at = '2025-01-01 12:00:00.000';
        for ($p = 0; $p < $products; $p++) {
            Tx::run(function () use ($p, $movements, $at): void {
                $productId = (int) DB::table('products')->insertGetId(['name' => "Bench product {$p}", 'unit' => 'pcs', 'cost_price_minor' => 500, 'selling_price_minor' => 800, 'created_at' => $at, 'updated_at' => $at]);
                $qty = 0;
                $value = 0;
                $rows = [];
                for ($m = 0; $m < $movements; $m++) {
                    $in = $m === 0;
                    $moveQty = $in ? $movements * 1000 : 1000;
                    $cost = $in ? $movements * 500 : 500;
                    $qty += $in ? $moveQty : -$moveQty;
                    $value += $in ? $cost : -$cost;
                    $rows[] = ['product_id' => $productId, 'business_date' => '2025-01-01', 'occurred_at' => $at, 'movement_type' => $in ? 'PURCHASE' : 'SALE', 'source_type' => 'STOCK_ADJUSTMENT',
                        'source_id' => $productId, 'qty_in_milli' => $in ? $moveQty : 0, 'qty_out_milli' => $in ? 0 : $moveQty, 'unit_cost_minor' => 500, 'total_cost_minor' => $cost,
                        'balance_qty_milli' => $qty, 'balance_value_minor' => $value, 'created_at' => $at];
                }
                foreach (array_chunk($rows, 1000) as $chunk) {
                    DB::table('stock_ledger')->insert($chunk);
                }
                DB::table('products')->where('id', $productId)->update(['qty_on_hand_milli' => $qty, 'stock_value_minor' => $value]);
            });
        }

        return DB::table('stock_ledger')->count();
    }

    private function median(callable $work, int $runs = 5): float
    {
        $work(); // warm
        $times = [];
        for ($i = 0; $i < $runs; $i++) {
            $start = hrtime(true);
            $work();
            $times[] = (hrtime(true) - $start) / 1e6;
        }
        sort($times);

        return $times[intdiv($runs, 2)];
    }

    private function timeAll(): void
    {
        $account = (int) DB::table('accounts')->whereNotIn('id', DB::table('accounts')->whereNotNull('parent_id')->select('parent_id'))->orderBy('id')->value('id');
        $june = ['from' => '2025-06-01', 'to' => '2025-06-30'];

        $queries = [
            'Trial balance (every account, all time)' => ['SELECT account_id, SUM(debit_minor) AS d, SUM(credit_minor) AS c FROM journal_lines GROUP BY account_id', []],
            'Balance sheet (one aggregate to a date)' => ['SELECT l.account_id, SUM(l.debit_minor) AS d, SUM(l.credit_minor) AS c FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE e.entry_date <= ? GROUP BY l.account_id', ['2025-12-31']],
            'Profit & loss (one month)' => ['SELECT l.account_id, SUM(l.debit_minor) AS d, SUM(l.credit_minor) AS c FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE e.entry_date BETWEEN ? AND ? GROUP BY l.account_id', ['2025-06-01', '2025-06-30']],
            'General ledger for one account (one month, paged)' => ['SELECT l.id, l.debit_minor, l.credit_minor, e.entry_date FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE l.account_id = ? AND e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date, l.id LIMIT 50', [$account, '2025-06-01', '2025-06-30']],
            'Journal browser, page then total (page)' => ['SELECT id, entry_no FROM journal_entries ORDER BY entry_date DESC, id DESC LIMIT 50', []],
            'Books integrity check (does everything balance)' => ['SELECT SUM(debit_minor) AS d, SUM(credit_minor) AS c FROM journal_lines', []],
        ];

        $this->line('');
        $this->line('The old benchmark\'s queries, median of 5:');
        foreach ($queries as $name => [$sql, $bindings]) {
            $this->line(sprintf('  %8.1f ms  %s', $this->median(fn () => DB::select($sql, $bindings)), $name));
        }

        $reports = [
            'Journal page of 50 (listJournalEntries)' => fn () => LedgerService::listJournalEntries(['limit' => 50]),
            'General ledger, one account, one month' => fn () => LedgerService::getGeneralLedger($account, $june),
            'General ledger, one account, all time' => fn () => LedgerService::getGeneralLedger($account),
            'Profit & loss, one month' => fn () => FinancialService::getProfitAndLoss($june),
            'Profit & loss, the year' => fn () => FinancialService::getProfitAndLoss(['from' => '2025-01-01', 'to' => '2025-12-31']),
            'Trial balance, all time' => fn () => BalancesService::getTrialBalance(),
            'Balance sheet, all time' => fn () => FinancialService::getBalanceSheet('2025-12-31'),
            'Cash flow, one month' => fn () => FinancialService::getCashFlow($june),
            'Cash flow, the year' => fn () => FinancialService::getCashFlow(['from' => '2025-01-01', 'to' => '2025-12-31']),
            'Chart of accounts with balances' => fn () => LedgerService::getChartOfAccounts(),
            'Books integrity check' => fn () => LedgerService::checkBooksIntegrity(),
            'Money by month (dashboard, 6 months)' => fn () => MoneyTrend::getMoneyByMonth('2025-12-31'),
            'Receivables ageing' => fn () => LedgerService::getReceivablesAgeing('2025-12-31'),
            'Payables ageing' => fn () => LedgerService::getPayablesAgeing('2025-12-31'),
            'Tax return, the year' => fn () => TaxReturnService::getTaxReturn(['from' => '2025-01-01', 'to' => '2025-12-31']),
            'Sales by product, one month' => fn () => OperationsService::getSalesByProduct($june),
            'Sales by product, the year' => fn () => OperationsService::getSalesByProduct(['from' => '2025-01-01', 'to' => '2025-12-31']),
            'Sales by category, the year' => fn () => OperationsService::getSalesByCategory(['from' => '2025-01-01', 'to' => '2025-12-31']),
            'Stock valuation' => fn () => OperationsService::getStockValuation(),
            'Stock movement summary, the year' => fn () => OperationsService::getStockMovementSummary(['from' => '2025-01-01', 'to' => '2025-12-31']),
            'Stock check, cache vs last ledger balance' => fn () => InventoryService::verifyStockAgainstLedger(),
        ];

        $this->line('');
        $this->line('The reports as the app calls them, median of 5:');
        $slowest = 0.0;
        foreach ($reports as $name => $work) {
            $ms = $this->median($work);
            $slowest = max($slowest, $ms);
            $this->line(sprintf('  %8.1f ms  %s', $ms, $name));
        }
        $this->line(sprintf('  %8.1f ms  %s', $this->median(fn () => InventoryService::verifyAllStock(), 3), 'Stock check, full replay of every movement (preflight)'));

        $this->line('');
        $slowest < 1000 ? $this->info(sprintf('Slowest report: %.0f ms.', $slowest)) : $this->warn(sprintf('SLOW: %.0f ms. Investigate before shipping.', $slowest));
    }
}
