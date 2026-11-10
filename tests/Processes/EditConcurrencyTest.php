<?php

declare(strict_types=1);

/*
| Audit finding 2: an edit read the old lines before it took any lock, so two
| edits of one stock adjustment at once could each reverse the same old lines.
| Four tills edit the same write-off at once; afterwards the adjustment has one
| line, the shelf is short by exactly that line, and the product cache, its
| stock ledger and the Inventory account all agree.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Inventory\Costing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/run.php';

define('EDIT_DB', runDatabase('nunabooks_concurrency'));

function editEnv(): array
{
    return ['DB_DATABASE' => EDIT_DB, 'APP_ENV' => 'testing'];
}

beforeEach(function () {
    DB::statement('CREATE DATABASE IF NOT EXISTS `'.EDIT_DB.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin');
    foreach ([['artisan', 'migrate:fresh', '--seed', '--force'], ['tests/Processes/edit-worker.php', 'setup']] as $command) {
        $run = Process::env(editEnv())->path(base_path())->timeout(300)->run([PHP_BINARY, ...$command]);
        expect($run->successful())->toBeTrue($run->errorOutput().$run->output());
    }
    config(['database.connections.edit' => array_merge(config('database.connections.mysql'), ['database' => EDIT_DB])]);
});

afterEach(fn () => dropRunDatabases(EDIT_DB));

it('leaves one write-off, and stock, its ledger and the books agreeing, when four tills edit it at once', function () {
    $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nunabooks-edit-go-'.EDIT_DB;
    clearStartLine($go);
    $workers = 4;
    $iterations = 8;

    $pool = Process::pool(function ($pool) use ($workers, $iterations) {
        for ($w = 1; $w <= $workers; $w++) {
            $pool->as("till-{$w}")->env([...editEnv(), 'NUNA_WORKERS' => (string) $workers])->path(base_path())->timeout(300)
                ->command([PHP_BINARY, 'tests/Processes/edit-worker.php', 'run', (string) $iterations, (string) $w]);
        }
    })->start();
    usleep(1_500_000);
    touch($go);
    $results = $pool->wait();
    clearStartLine($go);

    expectOverlapEachRound(workerRows($results), $workers);

    $db = DB::connection('edit');
    $product = $db->table('products')->first();
    $lines = $db->table('stock_adjustment_items')
        ->join('stock_adjustments', 'stock_adjustments.id', '=', 'stock_adjustment_items.adjustment_id')
        ->where('stock_adjustments.reason', 'DAMAGED')->get(['stock_adjustment_items.qty_milli']);
    $movements = $db->table('stock_ledger')->where('product_id', $product->id)->orderBy('id')->get()
        ->map(fn (object $m): array => ['qtyIn' => (int) $m->qty_in_milli, 'qtyOut' => (int) $m->qty_out_milli, 'totalCost' => (int) $m->total_cost_minor]);
    $replayed = Costing::replay($movements);
    $inventory = (int) $db->table('journal_lines')
        ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.code', ChartOfAccounts::INVENTORY)
        ->selectRaw('SUM(journal_lines.debit_minor) - SUM(journal_lines.credit_minor) AS b')->value('b');

    expect($lines)->toHaveCount(1)
        // Short by the last edit's count and nothing else: no old line reversed twice.
        ->and((int) $product->qty_on_hand_milli)->toBe(100_000 - (int) $lines[0]->qty_milli)
        ->and([$replayed->qty, $replayed->value])->toBe([(int) $product->qty_on_hand_milli, (int) $product->stock_value_minor])
        ->and($inventory)->toBe((int) $product->stock_value_minor);
})->group('concurrency');
