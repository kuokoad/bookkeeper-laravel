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
