<?php

declare(strict_types=1);

/*
| SQLite gave the old app one writer at a time. MySQL does not, so this runs
| several real PHP processes against one database at the same moment and
| checks what the shop owner would: no two documents share a number, and the
| numbers run with no gaps, even though some transactions roll back.
|
| It uses its own database (nunabooks_concurrency_<run id>), because the workers
| commit, and committed rows would leak into the other tests.
*/

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/run.php';

define('CONCURRENCY_DB', runDatabase('nunabooks_concurrency'));

function concurrencyEnv(): array
{
    return ['DB_DATABASE' => CONCURRENCY_DB, 'APP_ENV' => 'testing'];
}

beforeEach(function () {
    DB::statement('CREATE DATABASE IF NOT EXISTS `'.CONCURRENCY_DB.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin');
    $fresh = Process::env(concurrencyEnv())->path(base_path())->timeout(300)
        ->run([PHP_BINARY, 'artisan', 'migrate:fresh', '--seed', '--force']);
    expect($fresh->successful())->toBeTrue($fresh->errorOutput().$fresh->output());

    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => CONCURRENCY_DB])]);
});

afterEach(fn () => dropRunDatabases(CONCURRENCY_DB));

it('gives every till a different number, with no gaps, when four tills write at once', function () {
    $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nunabooks-concurrency-go-'.CONCURRENCY_DB;
    clearStartLine($go);

    $workers = 4;
    $iterations = 25;
    $pool = Process::pool(function ($pool) use ($workers, $iterations) {
        for ($w = 1; $w <= $workers; $w++) {
            $pool->as("till-{$w}")->env([...concurrencyEnv(), 'NUNA_WORKERS' => (string) $workers])->path(base_path())->timeout(300)
                ->command([PHP_BINARY, 'tests/Processes/worker.php', (string) $iterations, (string) $w]);
        }
    })->start();

    usleep(1_500_000); // let every worker boot and reach the starting line
    touch($go);
    $results = $pool->wait();
    clearStartLine($go);

    $rows = workerRows($results);
    // The tills really did collide: every write, kept or rolled back, overlapped the others in its round.
    expectRoundsRaced($rows, $workers);
    $kept = array_filter($rows, fn (array $row): bool => isset($row['receipt']));
    $receipts = array_column($kept, 'receipt');
    $entries = array_column($kept, 'entry');

    // Writes 5, 10, 15... rolled back after the receipt number; 7, 14, 21 after the journal number too.
    $failed = count(array_filter(range(1, $iterations), fn (int $i): bool => $i % 5 === 0 || $i % 7 === 0));
    $committed = $workers * ($iterations - $failed);
    $expectedReceipts = array_map(fn (int $n): string => sprintf('RCP-%05d', $n), range(1, $committed));
    $expectedEntries = array_map(fn (int $n): string => sprintf('JE-%06d', $n), range(1, $committed));
    sort($receipts);
    sort($entries);

    expect($receipts)->toBe($expectedReceipts)
        ->and($entries)->toBe($expectedEntries);

    $db = DB::connection('concurrency');
    expect($db->table('sequences')->where('doc_type', 'RECEIPT')->value('next_number'))->toBe($committed + 1)
        ->and($db->table('journal_entries')->count())->toBe($committed)
        ->and((int) $db->table('journal_lines')->sum('debit_minor'))->toBe((int) $db->table('journal_lines')->sum('credit_minor'));
})->group('concurrency');
