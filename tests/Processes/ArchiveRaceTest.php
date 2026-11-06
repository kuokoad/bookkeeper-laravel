<?php

declare(strict_types=1);

/*
| A sale tendered into an account at the moment someone archives it. SQLite's
| single writer made them take turns. Here the archive is written but not yet
| committed when the till rings the sale up. The till must wait for the
| archive's lock on the account, then see it archived and refuse, rather than
| read it as still active and post money onto an archived account.
*/

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/run.php';

define('ARCHIVE_RACE_DB', runDatabase('nunabooks_concurrency'));

function archiveRaceRun(array $args): ProcessResult
{
    return Process::env(['DB_DATABASE' => ARCHIVE_RACE_DB, 'APP_ENV' => 'testing'])->path(base_path())->timeout(300)->run([PHP_BINARY, ...$args]);
}

beforeEach(function () {
    config(['database.connections.archiverace' => array_merge(config('database.connections.mysql'), ['database' => ARCHIVE_RACE_DB])]);
});

afterEach(fn () => dropRunDatabases(ARCHIVE_RACE_DB));

it('refuses a sale tendered into an account while it is being archived', function () {
    DB::statement('CREATE DATABASE IF NOT EXISTS `'.ARCHIVE_RACE_DB.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin');
    foreach ([['artisan', 'migrate:fresh', '--seed', '--force'], ['tests/Processes/account-worker.php', 'setup-archive']] as $command) {
        $run = archiveRaceRun($command);
        expect($run->successful())->toBeTrue($run->errorOutput().$run->output());
    }
    $db = DB::connection('archiverace');
    $spare = (int) $db->table('payment_accounts')->where('name', 'Spare till')->value('id');

    $archive = Process::env(['DB_DATABASE' => ARCHIVE_RACE_DB, 'APP_ENV' => 'testing'])->path(base_path())->timeout(300)
        ->start([PHP_BINARY, 'tests/Processes/account-worker.php', 'hold-archive', (string) $spare]);
    $deadline = microtime(true) + 60;
    while (! str_contains($archive->output(), 'held') && $archive->running() && microtime(true) < $deadline) {
        usleep(20_000);
    }
    expect($archive->output())->toContain('held');

    $sale = archiveRaceRun(['tests/Processes/account-worker.php', 'sell', (string) $spare]);
    $archived = $archive->wait();

    expect($archived->successful())->toBeTrue($archived->errorOutput())
        ->and($sale->successful())->toBeTrue($sale->errorOutput())
        ->and(trim($sale->output()))->toBe('Payment account "Spare till" is not active.')
        ->and((bool) $db->table('payment_accounts')->where('id', $spare)->value('is_active'))->toBeFalse()
        ->and($db->table('sales')->count())->toBe(0)
        ->and($db->table('journal_lines')->where('payment_account_id', $spare)->count())->toBe(0);
})->group('concurrency');
