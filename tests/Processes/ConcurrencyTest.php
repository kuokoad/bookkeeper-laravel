<?php

declare(strict_types=1);

/*
| SQLite gave the old app one writer at a time. MySQL does not, so this runs
| several real PHP processes against one database at the same moment and
| checks what the shop owner would: no two documents share a number, and the
| numbers run with no gaps, even though some transactions roll back.
|
| It uses its own database (nunabooks_concurrency), because the workers
| commit, and committed rows would leak into the other tests.
*/

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

const CONCURRENCY_DB = 'nunabooks_concurrency';

function concurrencyEnv(): array
{
    return ['DB_DATABASE' => CONCURRENCY_DB, 'APP_ENV' => 'testing'];
}

function rowLockWaits(): int
{
    return (int) DB::selectOne("SHOW GLOBAL STATUS LIKE 'Innodb_row_lock_waits'")->Value;
}

beforeEach(function () {
    DB::statement('CREATE DATABASE IF NOT EXISTS `'.CONCURRENCY_DB.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin');
    $fresh = Process::env(concurrencyEnv())->path(base_path())->timeout(300)
        ->run([PHP_BINARY, 'artisan', 'migrate:fresh', '--seed', '--force']);
    expect($fresh->successful())->toBeTrue($fresh->errorOutput().$fresh->output());

    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => CONCURRENCY_DB])]);
});

it('gives every till a different number, with no gaps, when four tills write at once', function () {
    $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nunabooks-concurrency-go';
    @unlink($go);

    $workers = 4;
    $iterations = 25;
    $pool = Process::pool(function ($pool) use ($workers, $iterations) {
        for ($w = 1; $w <= $workers; $w++) {
            $pool->as("till-{$w}")->env(concurrencyEnv())->path(base_path())->timeout(300)
                ->command([PHP_BINARY, 'tests/Processes/worker.php', (string) $iterations, (string) $w]);
        }
    })->start();

    usleep(1_500_000); // let every worker boot and reach the starting line
    $waitsBefore = rowLockWaits();
    touch($go);
    $results = $pool->wait();
    @unlink($go);

    $receipts = [];
    $entries = [];
    foreach ($results as $name => $result) {
        expect($result->successful())->toBeTrue("{$name}: ".$result->errorOutput());
        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            $row = json_decode($line, true);
            expect($row)->toBeArray("{$name} printed something other than a result: {$line}");
            $receipts[] = $row['receipt'];
            $entries[] = $row['entry'];
        }
    }

    // The tills really did collide: InnoDB counted transactions waiting on a row lock.
    expect(rowLockWaits() - $waitsBefore)->toBeGreaterThan(10);

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
