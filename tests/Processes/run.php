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

/**
 * The databases a test run makes, each followed by _<pid> (and a backup's _scratch_ copies
 * after that): this suite's, and every run's own nunabooks_test_run_<pid> (TestCase). Only
 * these are ever swept, so a database pinned with DB_DATABASE is never dropped.
 */
const RUN_DATABASE_PATTERN = '/^nunabooks_(test_run|concurrency|import|backup_source|backup_target)_(\d+)($|_)/';

function runDatabase(string $base): string
{
    return $base.'_'.getmypid();
}

/**
 * Of these database names, the ones a run that has since ended left behind.
 *
 * @param  iterable<string>  $names
 * @return list<string>
 */
function staleRunDatabases(iterable $names): array
{
    $stale = [];
    foreach ($names as $name) {
        if (preg_match(RUN_DATABASE_PATTERN, $name, $m) === 1 && (int) $m[2] !== getmypid() && ! processRunning((int) $m[2])) {
            $stale[] = $name;
        }
    }

    return $stale;
}

function dropRunDatabases(string ...$databases): void
{
    static $swept = false;
    if (! $swept) {
        $swept = true;
        array_push($databases, ...staleRunDatabases(array_map(fn (object $row): string => (string) array_values((array) $row)[0], DB::select("SHOW DATABASES LIKE 'nunabooks\\_%'"))));
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
 * Most rounds, not every round: startRound releases every worker at the same
 * instant, but a busy PC (a second test run, a build) can deschedule one for
 * longer than a few-millisecond write, so that round runs one after another.
 * That says nothing about the code under test, and failing on it made these
 * tests flake. What must fail is a run that never really raced (a broken start
 * line runs nearly every round serially), so at least a quarter must have
 * overlapped: four race runs fighting over one PC's cores still lost up to 11
 * of 20 rounds, and a half bar failed that.
 *
 * @param  list<array{round: int, start: float, end: float}>  $rows
 */
function expectRoundsRaced(array $rows, int $workers): void
{
    $rounds = [];
    foreach ($rows as $row) {
        $rounds[$row['round']][] = $row;
    }
    expect($rounds)->not->toBe([]);
    $serial = [];
    foreach ($rounds as $round => $attempts) {
        expect($attempts)->toHaveCount($workers, "round {$round}: not every worker reported");
        if (max(array_column($attempts, 'start')) >= min(array_column($attempts, 'end'))) {
            $serial[] = $round;
        }
    }
    $raced = count($rounds) - count($serial);
    expect($raced * 4)->toBeGreaterThanOrEqual(count($rounds),
        "only {$raced} of ".count($rounds).' rounds ran at once; rounds '.implode(', ', $serial).' ran one after another, so this run did not test a race');
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
