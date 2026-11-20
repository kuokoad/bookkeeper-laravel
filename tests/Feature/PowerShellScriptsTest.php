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
        ->and(strpos($branch, 'exit 1'))->toBeLessThan(strpos($branch, '$serving = Test-Serving'))
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

it('install.ps1 gives SYSTEM a read-only deploy key that Windows OpenSSH accepts and update.ps1 picks up', function () {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/install.ps1');
    $section = (string) strstr($script, '# --- 6b.');
    $command = (string) strstr((string) strstr($section, '$sshCommand = '), "\n", true);
    $update = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/update.ps1');

    expect($section)->not->toBe('')
        // After Lock-Folder exists, and the key folder is locked and owned by Administrators:
        // Windows' OpenSSH refuses a key another user owns, which SYSTEM would see.
        ->and(strpos($script, '# --- 6b.'))->toBeGreaterThan(strpos($script, 'function Lock-Folder'))
        ->and($section)->toContain('Lock-Folder $DeployKeyDir')
        ->and($section)->toContain('"/setowner", "*S-1-5-32-544"')
        ->and($section)->toContain('\System32\OpenSSH\ssh.exe')
        // GitHub's host key pinned, never trusted on first use, and never a password prompt.
        ->and($section)->toContain('github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl')
        ->and($command)->toContain('StrictHostKeyChecking=yes')
        ->and($command)->toContain('BatchMode=yes')
        ->and($command)->toContain('IdentitiesOnly=yes')
        // PowerShell 5.1 mangles a double quote inside a native argument, so the command has none.
        ->and(substr_count($command, '`"'))->toBe(0)
        ->and($section)->toContain('"config", "core.sshCommand", $sshCommand')
        ->and($section)->toContain('"ls-remote", "--heads", "origin"')
        // update.ps1 reads core.sshCommand from .git/config, once safe.directory lets git trust the folder.
        ->and($update)->toContain('$env:GIT_CONFIG_KEY_0 = "safe.directory"')
        ->and($update)->toContain('"pull", "--ff-only"');
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

it('runs Composer as composer.phar under the installed PHP, never composer.bat, which needs php on SYSTEM\'s PATH', function () {
    $install = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/install.ps1');
    $update = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/update.ps1');

    // install.ps1 keeps its own copy in the PHP folder, which Lock-Folder restricts to Administrators and SYSTEM.
    expect($install)->toContain('$composer = Join-Path $PhpDir "composer.phar"')
        ->toContain('Invoke-Checked "Installing PHP packages" $php @($composer, "install"')
        ->toContain('$env:Path = "$PhpDir;$env:Path"')
        ->toContain('$PhpDir, $ApacheDir, $MysqlDir)) { Lock-Folder $dir }')
        ->not->toContain('composer.bat"')
        ->and($update)->toContain('$composer = Join-Path (Split-Path $php) "composer.phar"')
        ->toContain('Invoke-Native "Installing PHP packages" $php @($composer, "install"')
        ->toContain('$env:Path = (Split-Path $php) + ";" + $env:Path');
});

it('update.ps1 opens the shop again after a failed package install, since the books were not touched', function () {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/update.ps1');
    $undo = (string) strstr((string) strstr($script, 'function Undo-Update'), "\ntry {", true);
    $untouched = substr($undo, (int) strrpos($undo, "exit 1\n    }") ?: 0);

    // Not behind "$codeBack -and": a failed composer step must not keep Apache stopped.
    expect($untouched)->toContain('$serving = Test-Serving')
        ->and(strpos($untouched, '$serving = Test-Serving'))->toBeLessThan(strpos($untouched, 'if ($codeBack -and $serving)'));
});

it('install.ps1 copies composer.phar into the locked PHP folder only once, so an edited copy cannot ride in on a re-run', function () {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/install.ps1');

    expect($script)->toContain('if (-not (Test-Path $composer) -and (Test-Path $sourcePhar)) { Copy-Item $sourcePhar $composer }')
        ->and(substr_count($script, 'Copy-Item $sourcePhar'))->toBe(1);
});

it('install.ps1 sets NUNA_MYSQL_BIN on every run, not only when it writes a new .env', function () {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/install.ps1');
    $block = (string) strstr((string) strstr($script, 'if (-not (Test-Path $envFile))'), "\n}\n", true);

    expect($script)->toContain('Set-EnvValue $envFile "NUNA_MYSQL_BIN"')
        ->and($block)->not->toContain('NUNA_MYSQL_BIN');
});

it('install.ps1 writes APP_URL on every run from the address the desktop shortcut opens, port included', function () {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/install.ps1');
    $block = (string) strstr((string) strstr($script, 'if (-not (Test-Path $envFile))'), "\n}\n", true);

    expect($script)->toContain('$appUrl = if ($Port -eq 80) { "http://$HostName" } else { "http://${HostName}:$Port" }')
        ->toContain('Set-EnvValue $envFile "APP_URL" $appUrl')
        ->toContain('"URL=$appUrl/"')
        ->not->toContain('$url')
        ->and($block)->toContain('APP_ENV')->not->toContain('APP_URL')
        ->and(strpos($script, 'Set-EnvValue $envFile "APP_URL"'))->toBeLessThan(strpos($script, '# --- 4.'));
});

it('install.ps1 runs its has-an-owner probe under Continue, so stderr from it backs up instead of ending the install', function () {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/install.ps1');
    $probe = strpos($script, 'artisan tinker --execute=$probe');
    $before = substr($script, 0, (int) $probe);

    expect($probe)->not->toBeFalse()
        ->and(strrpos($before, '$ErrorActionPreference = "Continue"'))->toBeGreaterThan((int) strrpos($before, '$ErrorActionPreference = "Stop"'));
});

it('install.ps1 restricts the app folder before it writes the database passwords into .env', function () {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/install.ps1');
    $defined = strpos($script, 'function Lock-Folder(');
    $locked = strpos($script, 'Lock-Folder $AppDir');
    $written = strpos($script, 'Copy-Item (Join-Path $AppDir ".env.example") $envFile');

    expect($defined)->not->toBeFalse()->and($locked)->not->toBeFalse()->and($written)->not->toBeFalse()
        ->and($defined)->toBeLessThan($locked)
        ->and($locked)->toBeLessThan($written);
});
