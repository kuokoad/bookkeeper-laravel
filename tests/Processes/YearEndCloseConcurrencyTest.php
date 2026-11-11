<?php

declare(strict_types=1);

/*
| Closing a year while other desks work, as real PHP processes against one
| MySQL database. The old app's single SQLite writer made both races
| impossible; here every round starts all desks together.
|
|   1. Two closers, one year: four desks close the same finished year at
|      once, five years in a row. One closing row, one closing entry and one
|      lock move per year; the other desks are told it is already closed.
|   2. A close racing a sale dated on the year end, five years in a row:
|      either the sale commits first and the close sweeps it, or the sale is
|      refused as period-locked. Either way the year's P&L accounts end at
|      zero, the closing profit is the year's whole profit, retained earnings
|      hold every year's profit and the books balance.
*/

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/run.php';

define('YEAR_END_RACE_DB', runDatabase('nunabooks_concurrency'));

function yearEndRaceEnv(): array
{
    return ['DB_DATABASE' => YEAR_END_RACE_DB, 'APP_ENV' => 'testing'];
}

function yearEndRaceClearBarrier(string $dir): void
{
    foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
        @unlink($file);
    }
}

/** @param list<string> $modes one per desk */
function yearEndRaceRun(array $modes, int $rounds): array
{
    foreach ([['artisan', 'migrate:fresh', '--seed', '--force'], ['tests/Processes/year-end-worker.php', 'setup', (string) $rounds]] as $command) {
        $run = Process::env(yearEndRaceEnv())->path(base_path())->timeout(300)->run([PHP_BINARY, ...$command]);
        expect($run->successful())->toBeTrue($run->errorOutput().$run->output());
    }

    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nunabooks-year-end-'.YEAR_END_RACE_DB;
    @mkdir($dir);
    yearEndRaceClearBarrier($dir);
    $pool = Process::pool(function ($pool) use ($modes, $rounds) {
        foreach ($modes as $i => $mode) {
            $w = $i + 1;
            $pool->as("{$mode}-{$w}")->env([...yearEndRaceEnv(), 'NUNA_WORKERS' => (string) count($modes)])->path(base_path())->timeout(300)
                ->command([PHP_BINARY, 'tests/Processes/year-end-worker.php', $mode, (string) $rounds, (string) $w, (string) count($modes)]);
        }
    })->start();
    usleep(1_500_000); // every desk booted and waiting at the line
    touch($dir.DIRECTORY_SEPARATOR.'go');
    $results = $pool->wait();
    yearEndRaceClearBarrier($dir);

    $rows = workerRows($results);
    expectRoundsRaced($rows, count($modes));

    return ['rows' => $rows];
}

/** The year's revenue, contra-revenue, expense and COGS lines, closing entries in or out, credit minus debit. */
function yearEndRaceProfit(object $db, int $year, bool $withClosing): int
{
    return (int) $db->table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
        ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->whereIn('accounts.type', ['REVENUE', 'CONTRA_REVENUE', 'EXPENSE', 'COGS'])
        ->whereBetween('journal_entries.entry_date', ["{$year}-01-01", "{$year}-12-31"])
        ->when(! $withClosing, fn ($q) => $q->where('journal_entries.is_closing', false))
        ->selectRaw('COALESCE(SUM(journal_lines.credit_minor) - SUM(journal_lines.debit_minor), 0) AS p')->value('p');
}

/** What every year's close must leave, whoever won each race. */
function yearEndRaceAssertBooks(object $db, int $rounds): void
{
    $closings = $db->table('year_end_closings')->orderBy('start_year')->get();
    expect($closings)->toHaveCount($rounds)
        ->and($closings->pluck('start_year')->map(fn ($y): int => (int) $y)->all())->toBe(range(2020, 2019 + $rounds))
        ->and($closings->whereNotNull('reversed_at')->count())->toBe(0)
        ->and($db->table('journal_entries')->where('is_closing', true)->count())->toBe($rounds, 'one closing entry per year')
        ->and((string) $db->table('business_settings')->where('id', 1)->value('books_locked_before'))->toBe((2019 + $rounds).'-12-31')
        ->and($db->table('audit_logs')->where('entity_type', 'year_end_closing')->count())->toBe($rounds, 'the lock moved once per year');

    foreach ($closings as $closing) {
        $year = (int) $closing->start_year;
        expect(yearEndRaceProfit($db, $year, withClosing: true))->toBe(0, "{$year}: every P&L account swept to zero")
            ->and((int) $closing->profit_minor)->toBe(yearEndRaceProfit($db, $year, withClosing: false), "{$year}: the closing profit is the year's whole profit")
            ->and($db->table('journal_entries')->where('id', $closing->journal_entry_id)->value('entry_date'))->toBe("{$year}-12-31");
    }

    $retained = (int) $db->table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->where('accounts.code', '3200')
        ->selectRaw('COALESCE(SUM(journal_lines.credit_minor) - SUM(journal_lines.debit_minor), 0) AS b')->value('b');
    expect($retained)->toBe((int) $closings->sum('profit_minor'), 'retained earnings hold every closed year')
        ->and((int) $db->table('journal_lines')->sum('debit_minor'))->toBe((int) $db->table('journal_lines')->sum('credit_minor'));
}

beforeEach(function () {
    DB::statement('CREATE DATABASE IF NOT EXISTS `'.YEAR_END_RACE_DB.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin');
    config(['database.connections.yearendrace' => array_merge(config('database.connections.mysql'), ['database' => YEAR_END_RACE_DB])]);
});

afterEach(fn () => dropRunDatabases(YEAR_END_RACE_DB));

it('closes a year once when four desks close it at the same moment', function () {
    [$workers, $rounds] = [4, 5];
    $run = yearEndRaceRun(array_fill(0, $workers, 'close'), $rounds);
    $db = DB::connection('yearendrace');

    expect($run['rows'])->toHaveCount($workers * $rounds);
    foreach (range(1, $rounds) as $round) {
        $attempts = array_values(array_filter($run['rows'], fn (array $r): bool => $r['round'] === $round));
        $won = array_values(array_filter($attempts, fn (array $r): bool => $r['ok']));
        $lost = array_values(array_filter($attempts, fn (array $r): bool => ! $r['ok']));
        expect($won)->toHaveCount(1, "round {$round}: exactly one desk closes the year")
            ->and($lost)->toHaveCount($workers - 1);
        foreach ($lost as $refusal) {
            expect($refusal['code'])->toBe('CONFLICT')
                ->and(str_contains($refusal['error'], 'is already closed'))->toBeTrue("round {$round}: refused cleanly, not by the unique index: {$refusal['error']}");
        }
    }

    yearEndRaceAssertBooks($db, $rounds);
})->group('concurrency');

it('sweeps a sale dated on the year end or refuses it, when it races the close', function () {
    [$sellers, $rounds] = [3, 5];
    $run = yearEndRaceRun(['close', ...array_fill(0, $sellers, 'sell')], $rounds);
    $db = DB::connection('yearendrace');

    expect($run['rows'])->toHaveCount((1 + $sellers) * $rounds);
    $closes = array_values(array_filter($run['rows'], fn (array $r): bool => $r['mode'] === 'close'));
    expect(array_column($closes, 'ok'))->toBe(array_fill(0, $rounds, true), 'every close goes through');

    $sold = 0;
    foreach (array_filter($run['rows'], fn (array $r): bool => $r['mode'] === 'sell') as $sale) {
        if ($sale['ok']) {
            $sold++;

            continue;
        }
        expect($sale['code'])->toBe('CONFLICT')
            ->and(str_starts_with($sale['error'], 'Period locked'))->toBeTrue("round {$sale['round']}: a late sale is refused as period-locked: {$sale['error']}");
    }
    expect($db->table('sales')->where('business_date', 'like', '%-12-31')->count())->toBe($sold, 'every sale that said it was saved is there');

    yearEndRaceAssertBooks($db, $rounds);
})->group('concurrency');
