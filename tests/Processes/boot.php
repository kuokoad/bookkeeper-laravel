<?php

declare(strict_types=1);

/*
| The start of every worker script here: boot Laravel, then make an uncaught
| exception fail the process. Laravel's own handler reports it and lets PHP
| exit 0, so a crashed worker would read to its test as one that succeeded.
*/

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(function (Throwable $e): never {
    fwrite(STDERR, $e::class.': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
});

/**
 * Wait at the start line until every worker (NUNA_WORKERS) has reached
 * round $round, then go together, so every round is a race and not only the
 * first. Each worker writes the moment it arrived; all of them leave at the
 * same instant, 50 ms after the last arrival, sleeping until just before it
 * and spinning on the clock for the final 3 ms (Windows sleeps in steps of up
 * to 15 ms, longer than some of the writes being raced). Gives up after 60 s,
 * so a worker that crashed fails the test instead of hanging it.
 */
function startRound(string $go, int $round, int $worker): void
{
    $workers = (int) env('NUNA_WORKERS', 1);
    // Written aside and renamed into place, so no worker reads half a time.
    file_put_contents("{$go}-tmp-r{$round}-w{$worker}", (string) microtime(true));
    rename("{$go}-tmp-r{$round}-w{$worker}", "{$go}-r{$round}-w{$worker}");
    $deadline = microtime(true) + 60;
    while (count($marks = glob("{$go}-r{$round}-w*") ?: []) < $workers) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("Round {$round}: not every worker reached the start line.");
        }
        usleep(1_000);
    }
    $arrivals = array_map(fn (string $mark): float => (float) file_get_contents($mark), $marks);
    $release = max($arrivals) + 0.05;
    while (microtime(true) < $release - 0.003) {
        usleep(1_000);
    }
    while (microtime(true) < $release) {
        // spin: the last 3 ms
    }
}
