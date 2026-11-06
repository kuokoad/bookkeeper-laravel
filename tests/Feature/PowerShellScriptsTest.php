<?php

declare(strict_types=1);

/*
| Port of the old tests/scripts/powershell-ascii.test.ts, plus a parse
| check. The shop PC runs these with Windows PowerShell 5.1, which reads a
| script without a byte-order mark as Windows-1252: one em dash ended a
| string early and a script would not even parse. Plain ASCII reads the same
| everywhere. The scripts themselves are not run here.
*/

use Illuminate\Support\Facades\Process;

$scripts = glob(dirname(__DIR__, 2).'/scripts/*.ps1') ?: [];

it('has the install and update scripts', function () use ($scripts) {
    expect(array_map('basename', $scripts))->toContain('install.ps1', 'update.ps1');
});

it('is plain ASCII', function (string $path) {
    $offending = [];
    foreach (file($path) as $n => $line) {
        if (preg_match('/[^\x00-\x7F]/', $line) === 1) {
            $offending[] = ($n + 1).': '.trim($line);
        }
    }
    expect($offending)->toBe([]);
})->with($scripts);

it('parses under Windows PowerShell', function (string $path) {
    $check = '$e = $null; [System.Management.Automation.Language.Parser]::ParseFile($args[0], [ref]$null, [ref]$e) | Out-Null; $e | ForEach-Object { $_.Message }; exit $e.Count';
    $result = Process::run(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', "& { {$check} } '{$path}'"]);
    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
})->with($scripts)->skip(PHP_OS_FAMILY !== 'Windows', 'needs Windows PowerShell');

it('update.ps1 restores the books, not just the code, on any failure once migrate has begun', function () {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/update.ps1');
    $flag = strpos($script, '$migrateStarted = $true');
    $migrate = strpos($script, '"migrate", "--force"');
    $undo = (string) strstr((string) strstr($script, 'function Undo-Update'), "\ntry {", true);
    $branch = (string) strstr($undo, 'if ($migrateStarted)');

    expect($flag)->not->toBeFalse()
        ->and($migrate)->toBeGreaterThan($flag)
        // The only rollback is Undo-Update, and a migrated update takes its restore branch.
        ->and(substr_count($script, '"reset", "--hard"'))->toBe(1)
        ->and($undo)->toContain('"reset", "--hard"')
        ->and($branch)->toContain('nuna:restore')
        ->and(strpos($branch, 'exit 1'))->toBeLessThan(strpos($branch, 'if ($codeBack -and (Test-Serving))'))
        // Every failure after the flag ends in Undo-Update, the catch included.
        ->and(substr($script, $migrate))->toContain('Undo-Update')
        ->and((string) strstr($script, "\ncatch {"))->toContain('Undo-Update')
        // Apache is stopped before the backup is taken, so no sale lands after it.
        ->and(strpos($script, 'Stop-Service $service'))->toBeLessThan(strpos($script, '"nuna:backup"'));
});

it('install.ps1 and update.ps1 load the baseline data after migrating', function (string $name) {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/'.$name);
    $migrate = strpos($script, '"migrate", "--force"');
    $seed = strpos($script, '"db:seed", "--class=Database\Seeders\CoreSeeder", "--force"');

    expect($migrate)->not->toBeFalse()
        ->and($seed)->not->toBeFalse()
        ->and($seed)->toBeGreaterThan($migrate);
})->with(['install.ps1', 'update.ps1']);

it('install.ps1 registers a daily backup task the way it registers the update task, replacing it on a re-run', function () {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/install.ps1');
    $register = (string) strstr((string) strstr($script, 'Register-ScheduledTask -TaskName "NunaBooksBackup"'), "\n", true);

    expect($script)->toContain('New-ScheduledTaskAction -Execute $php -Argument "artisan nuna:backup" -WorkingDirectory $AppDir')
        ->and($script)->toContain('New-ScheduledTaskTrigger -Daily')
        ->and($register)->toContain('-Trigger $daily')
        ->and($register)->toContain('-User "SYSTEM"')
        ->and($register)->toContain('-Force');
});

it('install.ps1 skips the backup before migrating only on a positive no-owner answer, and backs up on anything else', function () {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/install.ps1');
    $backup = strpos($script, '"nuna:backup")');
    $before = substr($script, 0, (int) $backup);
    $guard = substr($before, (int) strrpos($before, "\nif "));

    expect($backup)->not->toBeFalse()
        ->and($backup)->toBeLessThan(strpos($script, '"migrate", "--force"'))
        // Only "no-owner" on a line of its own skips it (a new database, or no owner yet: nothing
        // traded). A probe that errors (MySQL down, a broken install) runs nuna:backup, which then
        // fails loudly rather than letting live books be migrated unprotected.
        ->and($guard)->toContain('if (-not $noOwner)')
        ->and($script)->toContain('$noOwner = ((& $php artisan tinker --execute=$probe 2>$null) -join "`n") -match "(?m)^no-owner\s*$"')
        ->and($script)->toContain("Schema::hasTable(''users'')")
        ->and($script)->toContain("where(''role'', ''OWNER'')->where(''is_active'', true)->exists()")
        ->and($script)->not->toContain('migrate:status');
});

it('install.ps1 sets each MySQL user password even when the user already exists, and never writes root\'s', function () {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/install.ps1');

    foreach (["'nunabooks'@'localhost' IDENTIFIED BY '\$appPassword'", "'nunabooks_backup'@'localhost' IDENTIFIED BY '\$backupPassword'"] as $user) {
        $create = strpos($script, "CREATE USER IF NOT EXISTS {$user};");
        expect($create)->not->toBeFalse()
            ->and(strpos($script, "ALTER USER {$user};"))->toBeGreaterThan($create);
    }
    expect($script)->not->toMatch('/Set-EnvValue[^\n]*rootPassword/')
        ->and($script)->not->toMatch('/(Set-Content|Add-Content|Out-File|WriteAll\w+)[^\n]*rootPassword/');
});
