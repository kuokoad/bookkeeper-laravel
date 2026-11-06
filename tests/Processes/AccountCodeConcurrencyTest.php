<?php

declare(strict_types=1);

/*
| A new payment account or expense category takes the next free code under its
| heading. SQLite let one writer at a time read the heading's children and
| insert; here four desks add accounts under Mobile Money (1012-1019, exactly
| filling the block) and categories under Operating Expenses at once. Each must
| get its own code, and none may fail: the heading row is locked before its
| children are read, so the desks take turns rather than colliding on the
| unique code (which Tx would report as "someone else saved the same code").
*/

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/run.php';

define('ACCOUNT_DB', runDatabase('nunabooks_concurrency'));

function accountEnv(): array
{
    return ['DB_DATABASE' => ACCOUNT_DB, 'APP_ENV' => 'testing'];
}

beforeEach(function () {
    DB::statement('CREATE DATABASE IF NOT EXISTS `'.ACCOUNT_DB.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin');
    foreach ([['artisan', 'migrate:fresh', '--seed', '--force'], ['tests/Processes/account-worker.php', 'setup']] as $command) {
        $run = Process::env(accountEnv())->path(base_path())->timeout(300)->run([PHP_BINARY, ...$command]);
        expect($run->successful())->toBeTrue($run->errorOutput().$run->output());
    }
    config(['database.connections.accounts' => array_merge(config('database.connections.mysql'), ['database' => ACCOUNT_DB])]);
});

afterEach(fn () => dropRunDatabases(ACCOUNT_DB));

it('gives every account and category added at once its own code, with no failures', function () {
    $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nunabooks-account-go-'.ACCOUNT_DB;
    clearStartLine($go);
    [$workers, $accounts, $categories] = [4, 2, 10];

    $pool = Process::pool(function ($pool) use ($workers, $accounts, $categories) {
        for ($w = 1; $w <= $workers; $w++) {
            $pool->as("desk-{$w}")->env([...accountEnv(), 'NUNA_WORKERS' => (string) $workers])->path(base_path())->timeout(300)
                ->command([PHP_BINARY, 'tests/Processes/account-worker.php', 'run', (string) $accounts, (string) $categories, (string) $w]);
        }
    })->start();
    usleep(1_500_000);
    touch($go);
    $results = $pool->wait();
    clearStartLine($go);

    expectOverlapEachRound(workerRows($results), $workers);

    $db = DB::connection('accounts');
    $wallets = $db->table('accounts')->where('parent_id', $db->table('accounts')->where('code', '1010')->value('id'))->orderBy('code')->pluck('code')->all();
    $costs = $db->table('accounts')->where('name', 'like', 'Cost %-%')->pluck('code')->all();
    $paymentGl = $db->table('payment_accounts')->where('name', 'like', 'Wallet %')->pluck('gl_account_id')->all();

    // The seeded MTN line plus eight new wallets: the whole 1011-1019 block, each code once.
    expect($wallets)->toBe(['1011', '1012', '1013', '1014', '1015', '1016', '1017', '1018', '1019'])
        ->and($paymentGl)->toHaveCount($workers * $accounts)
        ->and(array_unique($paymentGl))->toHaveCount($workers * $accounts)
        ->and($costs)->toHaveCount($workers * $categories)
        ->and(array_unique($costs))->toHaveCount($workers * $categories)
        ->and(array_filter($costs, fn (string $c): bool => (int) $c % 10 !== 0 || (int) $c <= 6000 || (int) $c >= 6900))->toBe([]);
})->group('concurrency');
