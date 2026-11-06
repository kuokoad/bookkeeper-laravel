<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Database\Tx;
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
 * database at that volume (365 x 200 sales, about 292k ledger lines, plus
 * 200 products x 250 stock movements), then times the old benchmark's
 * queries and every report service the app calls, median of 5. A port of
 * the old scripts/benchmark.ts. Its own database only: never the dev or
 * test database, and dropped afterwards unless --keep.
 */
final class BenchmarkCommand extends Command
{
    protected $signature = 'nuna:benchmark
        {--days=365} {--sales=200} {--products=200} {--movements=250}
        {--database=nunabooks_benchmark : A throwaway database; its name must start with nunabooks_bench}
        {--keep : Leave the database in place afterwards}';

    protected $description = 'Time every report at a year of trading, on a throwaway database.';

    public function handle(): int
    {
        $database = (string) $this->option('database');
        $configured = (string) config('database.connections.mysql.database');
        if (! str_starts_with($database, 'nunabooks_bench') || $database === $configured || preg_match('/^\w+$/D', $database) !== 1) {
            $this->error("Refusing to benchmark on \"{$database}\": use a throwaway database whose name starts with nunabooks_bench.");

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
            $lineCount = $this->buildLedger($days, $salesPerDay);
            $movementCount = $this->buildStock($products, $movements);
            $this->line(sprintf('Built %s entries / %s lines and %s stock movements.', number_format($days * $salesPerDay), number_format($lineCount), number_format($movementCount)));
            DB::statement('ANALYZE TABLE journal_entries, journal_lines, stock_ledger, products, accounts');
            $this->timeAll();
        } finally {
            if (! $this->option('keep')) {
                DB::statement("DROP DATABASE IF EXISTS `{$database}`");
            }
        }

        return self::SUCCESS;
    }

    /** Insert directly: a volume fixture, not a test of the posting path. */
    private function buildLedger(int $days, int $salesPerDay): int
    {
        $accountIds = array_map(fn (object $r): int => (int) $r->id, DB::select(
            'SELECT accounts.id FROM accounts WHERE accounts.id NOT IN (SELECT parent.parent_id FROM accounts AS parent WHERE parent.parent_id IS NOT NULL) ORDER BY accounts.id',
        ));
        $count = count($accountIds);
        $entryNo = 0;
        $start = new \DateTimeImmutable('2025-01-01');

        for ($day = 0; $day < $days; $day++) {
            $date = $start->modify("+{$day} days")->format('Y-m-d');
            $at = "{$date} 12:00:00.000";
            Tx::run(function () use (&$entryNo, $salesPerDay, $date, $at, $accountIds, $count): void {
                $first = $entryNo + 1;
                $entries = [];
                for ($sale = 0; $sale < $salesPerDay; $sale++) {
                    $n = ++$entryNo;
                    $entries[] = ['entry_no' => sprintf('JE-%08d', $n), 'entry_date' => $date, 'occurred_at' => $at, 'source_type' => 'SALE', 'source_id' => $n, 'memo' => 'Sale', 'created_at' => $at];
                }
                DB::table('journal_entries')->insert($entries);
                $firstId = (int) DB::table('journal_entries')->where('entry_no', sprintf('JE-%08d', $first))->value('id');

                $lines = [];
                for ($n = $first; $n <= $entryNo; $n++) {
                    $id = $firstId + $n - $first;
                    $amount = 500 + (($n * 137) % 20000);
                    $cost = intdiv($amount * 6, 10);
                    // A realistic sale posts several lines: cash, revenue, COGS, inventory.
                    foreach ([[0, $amount, 0], [1, 0, $amount], [2, $cost, 0], [3, 0, $cost]] as $k => [$offset, $debit, $credit]) {
                        $lines[] = ['entry_id' => $id, 'line_no' => $k + 1, 'account_id' => $accountIds[($n + $offset) % $count], 'debit_minor' => $debit, 'credit_minor' => $credit, 'created_at' => $at];
                    }
                }
                foreach (array_chunk($lines, 1000) as $chunk) {
                    DB::table('journal_lines')->insert($chunk);
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
            'Chart of accounts with balances' => fn () => LedgerService::getChartOfAccounts(),
            'Books integrity check' => fn () => LedgerService::checkBooksIntegrity(),
            'Money by month (dashboard, 6 months)' => fn () => MoneyTrend::getMoneyByMonth('2025-12-31'),
            'Receivables ageing' => fn () => LedgerService::getReceivablesAgeing('2025-12-31'),
            'Payables ageing' => fn () => LedgerService::getPayablesAgeing('2025-12-31'),
            'Tax return, the year' => fn () => TaxReturnService::getTaxReturn(['from' => '2025-01-01', 'to' => '2025-12-31']),
            'Sales by product, one month' => fn () => OperationsService::getSalesByProduct($june),
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
