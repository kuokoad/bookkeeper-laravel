<?php

declare(strict_types=1);

/*
| Second audit finding 8: a backup killed part-way (the web server cutting off
| a long download, the PC switched off at 21:00) never reaches its `finally`,
| so its scratch copy of the books and its password file stayed behind. Each
| backup now clears those first. Here, not under RefreshDatabase: creating a
| database commits whatever transaction is open.
*/

use App\Services\BackupService;
use Illuminate\Support\Facades\DB;

function databaseExists(string $name): bool
{
    return DB::table('information_schema.SCHEMATA')->where('SCHEMA_NAME', $name)->exists();
}

it('drops a left-behind scratch copy of these books and its password file, and nothing younger or not ours', function () {
    $mine = substr((string) DB::getDatabaseName(), 0, 40).'_scratch_left0001';
    $other = 'nunabooks_someoneelse_scratch_left0001';
    $temp = sys_get_temp_dir().DIRECTORY_SEPARATOR;
    [$oldFile, $newFile] = ["{$temp}nuna-my-oldtest.cnf", "{$temp}nuna-my-newtest.cnf"];
    try {
        foreach ([$mine, $other] as $db) {
            DB::statement("CREATE DATABASE `{$db}`");
            DB::statement("CREATE TABLE `{$db}`.`journal_entries` (id INT)");
        }
        file_put_contents($oldFile, 'password=x');
        touch($oldFile, time() - 7_200);
        file_put_contents($newFile, 'password=x');

        // A backup started a minute ago is still running: left alone.
        BackupService::sweepLeftovers();
        expect(databaseExists($mine))->toBeTrue()
            ->and(is_file($oldFile))->toBeFalse()
            ->and(is_file($newFile))->toBeTrue();

        // Once it is older than the cut-off, it goes; another database's copy never does.
        BackupService::sweepLeftovers(-1);
        expect(databaseExists($mine))->toBeFalse()
            ->and(databaseExists($other))->toBeTrue();
    } finally {
        foreach ([$mine, $other] as $db) {
            DB::statement("DROP DATABASE IF EXISTS `{$db}`");
        }
        @unlink($oldFile);
        @unlink($newFile);
    }
});
