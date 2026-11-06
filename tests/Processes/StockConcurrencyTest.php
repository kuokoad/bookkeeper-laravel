<?php

declare(strict_types=1);

/*
| The running pair (Q, V) is read, changed and written back on every stock
| movement. SQLite let one writer at a time do that; here four tills adjust the
| same product at once, and afterwards the product cache must equal a replay
| of its own stock ledger, and the Inventory account must hold exactly the
| stock's value. A lost update would break all three.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Inventory\Costing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/run.php';

define('STOCK_DB', runDatabase('nunabooks_concurrency'));

function stockEnv(): array
{
    return ['DB_DATABASE' => STOCK_DB, 'APP_ENV' => 'testing'];
}

beforeEach(function () {
    DB::statement('CREATE DATABASE IF NOT EXISTS `'.STOCK_DB.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin');
    foreach ([['artisan', 'migrate:fresh', '--seed', '--force'], ['tests/Processes/stock-worker.php', 'setup']] as $command) {
        $run = Process::env(stockEnv())->path(base_path())->timeout(300)->run([PHP_BINARY, ...$command]);
        expect($run->successful())->toBeTrue($run->errorOutput().$run->output());
    }
    config(['database.connections.stock' => array_merge(config('database.connections.mysql'), ['database' => STOCK_DB])]);
});

afterEach(fn () => dropRunDatabases(STOCK_DB));

it('keeps the stock pair, its ledger and the Inventory account in step when four tills adjust one product at once', function () {
    $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nunabooks-stock-go-'.STOCK_DB;
    clearStartLine($go);
    $workers = 4;
    $iterations = 20;

    $pool = Process::pool(function ($pool) use ($workers, $iterations) {
        for ($w = 1; $w <= $workers; $w++) {
            $pool->as("till-{$w}")->env([...stockEnv(), 'NUNA_WORKERS' => (string) $workers])->path(base_path())->timeout(300)
                ->command([PHP_BINARY, 'tests/Processes/stock-worker.php', 'run', (string) $iterations, (string) $w]);
        }
    })->start();
    usleep(1_500_000);
    touch($go);
    $results = $pool->wait();
    clearStartLine($go);

    expectOverlapEachRound(workerRows($results), $workers);

    $db = DB::connection('stock');
    $product = $db->table('products')->first();
    $movements = $db->table('stock_ledger')->where('product_id', $product->id)->orderBy('id')->get()
        ->map(fn (object $m): array => ['qtyIn' => (int) $m->qty_in_milli, 'qtyOut' => (int) $m->qty_out_milli, 'totalCost' => (int) $m->total_cost_minor]);
    $replayed = Costing::replay($movements);
    $inventory = (int) $db->table('journal_lines')
        ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.code', ChartOfAccounts::INVENTORY)
        ->selectRaw('SUM(journal_lines.debit_minor) - SUM(journal_lines.credit_minor) AS b')->value('b');

    // Each till moved as much in as out, so the count is back where it started.
    expect($movements)->toHaveCount(1 + $workers * $iterations)
        ->and((int) $product->qty_on_hand_milli)->toBe(100_000)
        ->and([$replayed->qty, $replayed->value])->toBe([(int) $product->qty_on_hand_milli, (int) $product->stock_value_minor])
        ->and($inventory)->toBe((int) $product->stock_value_minor)
        // The ledger's own running pair, row by row, is unbroken.
        ->and((int) $db->table('stock_ledger')->orderByDesc('id')->value('balance_value_minor'))->toBe((int) $product->stock_value_minor);
})->group('concurrency');
