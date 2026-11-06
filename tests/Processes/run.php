<?php

declare(strict_types=1);

/*
| Every database a Processes test creates carries this run's id, so two runs
| on one MySQL server (two checkouts, two agents, a run beside the Laragon
| site) never share a database or a go-file. The workers get the name through
| DB_DATABASE as before. Each test drops what it made, and the first drop of a
| run also clears what a killed run (its process gone) left behind.
*/

use Illuminate\Support\Facades\DB;

/** The databases this suite makes, each followed by _<pid> (and a backup's _scratch_ copies after that). */
const RUN_DATABASE_PATTERN = '/^nunabooks_(concurrency|import|backup_source|backup_target)_(\d+)($|_)/';

function runDatabase(string $base): string
{
    return $base.'_'.getmypid();
}

function dropRunDatabases(string ...$databases): void
{
    static $swept = false;
    if (! $swept) {
        $swept = true;
        foreach (DB::select("SHOW DATABASES LIKE 'nunabooks\\_%'") as $row) {
            $name = (string) array_values((array) $row)[0];
            if (preg_match(RUN_DATABASE_PATTERN, $name, $m) === 1 && (int) $m[2] !== getmypid() && ! processRunning((int) $m[2])) {
                $databases[] = $name;
            }
        }
    }
    foreach (array_unique($databases) as $database) {
        DB::statement("DROP DATABASE IF EXISTS `{$database}`");
    }
}

function processRunning(int $pid): bool
{
    if (PHP_OS_FAMILY === 'Windows') {
        exec('tasklist /FI "PID eq '.$pid.'" /NH /FO CSV', $out);

        return str_contains(implode("\n", $out), '"'.$pid.'"');
    }

    return is_dir("/proc/{$pid}") || (function_exists('posix_kill') && posix_kill($pid, 0));
}

/** The go-file and every worker's start-line mark (see boot.php startRound). */
function clearStartLine(string $go): void
{
    @unlink($go);
    array_map('unlink', glob($go.'-r*') ?: []);
}

/**
 * Each worker printed one JSON line per round with when its write began and
 * ended. A round was a real race only if the last worker to start did so
 * before the first one finished: proved here per run, not from a server-wide
 * counter that other traffic also moves.
 *
 * @param  list<array{round: int, start: float, end: float}>  $rows
 */
function expectOverlapEachRound(array $rows, int $workers): void
{
    $rounds = [];
    foreach ($rows as $row) {
        $rounds[$row['round']][] = $row;
    }
    expect($rounds)->not->toBe([]);
    foreach ($rounds as $round => $attempts) {
        $lastStart = max(array_column($attempts, 'start'));
        $firstEnd = min(array_column($attempts, 'end'));
        expect($attempts)->toHaveCount($workers, "round {$round}: not every worker reported")
            ->and($lastStart)->toBeLessThan($firstEnd, "round {$round}: the workers ran one after another, not at once");
    }
}

/** @return list<array<string, mixed>> one decoded JSON line per round from every worker */
function workerRows(iterable $results): array
{
    $rows = [];
    foreach ($results as $name => $result) {
        expect($result->successful())->toBeTrue("{$name}: ".$result->errorOutput().$result->output());
        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            $row = json_decode($line, true);
            expect($row)->toBeArray("{$name} printed something other than a result: {$line}");
            $rows[] = $row;
        }
    }

    return $rows;
}
