<?php

declare(strict_types=1);

/*
| nuna:benchmark wipes its database. Whatever the name, it must never wipe
| one that holds a shop: a database with a user account in it is refused.
| A side connection does the DDL and the command runs in its own process,
| so neither touches this test's transaction or holds a lock it waits on.
*/

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

it('refuses a benchmark database that already holds a user account, and leaves it as it was', function () {
    $name = 'nunabooks_bench_guard_'.getmypid();
    config(['database.connections.side' => config('database.connections.mysql')]);
    $side = DB::connection('side');
    $side->statement("CREATE DATABASE IF NOT EXISTS `{$name}`");
    try {
        $side->statement("CREATE TABLE `{$name}`.users (id INT PRIMARY KEY)");
        $side->statement("INSERT INTO `{$name}`.users VALUES (1)");

        $run = Process::path(base_path())->timeout(60)->run([PHP_BINARY, 'artisan', 'nuna:benchmark', "--database={$name}", '--days=1', '--sales=1', '--products=1', '--movements=1']);

        expect($run->exitCode())->toBe(1)
            ->and($run->output().$run->errorOutput())->toContain('holds a user account')
            ->and($side->selectOne("SELECT COUNT(*) AS n FROM `{$name}`.users")->n)->toBe(1);
    } finally {
        $side->statement("DROP DATABASE IF EXISTS `{$name}`");
        $side->disconnect();
    }
});
