<?php

declare(strict_types=1);

/*
| Purchases written at the same moment, as real PHP processes against one
| MySQL database (nunabooks_concurrency), released together by a go-file.
| The outcome is what is checked: stock leaves once, money lands once.
|
|   1. Double supplier return: four desks send back 6 of the same 10-unit
|      delivery at once, five deliveries in a row. One return each; the stock
|      leaves once and the cash refund lands once.
|   2. A void racing a payment: two desks void a credit delivery while two pay
|      the supplier what is owed on it, five deliveries in a row. Under the
|      old rule (a void needs no payments) exactly one of the four goes
|      through, and the supplier ends owing nothing either way.
| After both, the books balance, the stock account agrees with the shelf and
| the payables control agrees with the suppliers.
*/

use App\Domain\Accounting\ChartOfAccounts;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/run.php';

define('PURCHASE_RACE_DB', runDatabase('nunabooks_concurrency'));

function purchaseRaceEnv(): array
{
    return ['DB_DATABASE' => PURCHASE_RACE_DB, 'APP_ENV' => 'testing'];
}

/** Set up the shop, start the desks, release them together, and collect what each printed. */
function purchaseRaceRun(string $mode, string $tender, int $workers, int $rounds): array
{
    foreach ([['artisan', 'migrate:fresh', '--seed', '--force'], ['tests/Processes/purchase-worker.php', 'setup', (string) $rounds, $tender]] as $command) {
        $run = Process::env(purchaseRaceEnv())->path(base_path())->timeout(300)->run([PHP_BINARY, ...$command]);
        expect($run->successful())->toBeTrue($run->errorOutput().$run->output());
    }

    $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nunabooks-purchase-go-'.PURCHASE_RACE_DB;
    clearStartLine($go);
    $pool = Process::pool(function ($pool) use ($mode, $workers, $rounds) {
        for ($w = 1; $w <= $workers; $w++) {
            $pool->as("desk-{$w}")->env([...purchaseRaceEnv(), 'NUNA_WORKERS' => (string) $workers])->path(base_path())->timeout(300)
                ->command([PHP_BINARY, 'tests/Processes/purchase-worker.php', $mode, (string) $rounds, (string) $w]);
        }
    })->start();
    usleep(1_500_000); // every desk booted and waiting at the line
    touch($go);
    $results = $pool->wait();
    clearStartLine($go);

    $rows = workerRows($results);
    expectRoundsRaced($rows, $workers);

    return ['rows' => $rows];
}

/** @return array{won: list<array>, lost: list<array>} */
function purchaseRaceRound(array $rows, int $round): array
{
    $attempts = array_values(array_filter($rows, fn (array $r): bool => $r['round'] === $round));

    return [
        'won' => array_values(array_filter($attempts, fn (array $r): bool => $r['ok'])),
        'lost' => array_values(array_filter($attempts, fn (array $r): bool => ! $r['ok'])),
    ];
}

function purchaseRaceGl(Connection $db, string $code): int
{
    return (int) $db->table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.code', $code)->selectRaw('COALESCE(SUM(debit_minor) - SUM(credit_minor), 0) AS b')->value('b');
}

/** Trial balance, stock account against the shelf, payables control against the suppliers. */
function purchaseRaceBooksHealthy(Connection $db): void
{
    $payables = (int) $db->table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.code', ChartOfAccounts::ACCOUNTS_PAYABLE)->whereNotNull('journal_lines.supplier_id')
        ->selectRaw('COALESCE(SUM(debit_minor) - SUM(credit_minor), 0) AS b')->value('b');
    expect((int) $db->table('journal_lines')->sum('debit_minor'))->toBe((int) $db->table('journal_lines')->sum('credit_minor'), 'trial balance')
        ->and(purchaseRaceGl($db, ChartOfAccounts::INVENTORY))->toBe((int) $db->table('products')->sum('stock_value_minor'), 'inventory vs shelf')
        ->and($payables)->toBe(purchaseRaceGl($db, ChartOfAccounts::ACCOUNTS_PAYABLE), 'A/P subledger vs control');
}

beforeEach(function () {
    DB::statement('CREATE DATABASE IF NOT EXISTS `'.PURCHASE_RACE_DB.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin');
    config(['database.connections.purchaserace' => array_merge(config('database.connections.mysql'), ['database' => PURCHASE_RACE_DB])]);
});

afterEach(fn () => dropRunDatabases(PURCHASE_RACE_DB));

it('sends goods back once when four desks return the same delivery line at the same moment', function () {
    [$workers, $rounds] = [4, 5];
    $run = purchaseRaceRun('return', 'paid', $workers, $rounds);
    $db = DB::connection('purchaserace');

    expect($run['rows'])->toHaveCount($workers * $rounds);
    foreach (range(1, $rounds) as $round) {
        ['won' => $won, 'lost' => $lost] = purchaseRaceRound($run['rows'], $round);
        expect($won)->toHaveCount(1, "round {$round}: exactly one desk sends the goods back")
            ->and($lost)->toHaveCount($workers - 1, "round {$round}: the others are refused")
            ->and(array_unique(array_column($lost, 'code')))->toBe(['VALIDATION'], "round {$round}: refused as no longer returnable");
    }

    // 10 in, 6 back: once, not four times.
    foreach ($db->table('products')->get() as $product) {
        expect([(int) $product->qty_on_hand_milli, (int) $product->stock_value_minor])->toBe([4_000, 2_000], "{$product->name} on the shelf");
    }
    foreach ($db->table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')->where('purchases.kind', 'PURCHASE')->get(['purchase_items.*']) as $line) {
        expect((int) $line->returned_qty_milli)->toBe(6_000, "line {$line->id} returned once");
    }
    expect($db->table('purchases')->where('kind', 'RETURN')->count())->toBe($rounds)
        ->and($db->table('stock_ledger')->where('movement_type', 'PURCHASE_RETURN')->count())->toBe($rounds)
        ->and((int) $db->table('purchase_payments')->where('amount_minor', '<', 0)->sum('amount_minor'))->toBe(-3_000 * $rounds, 'each refund landed once')
        ->and($db->table('purchases')->where('kind', 'RETURN')->orderBy('purchase_no')->pluck('purchase_no')->all())
        ->toBe(array_map(fn (int $n): string => sprintf('PRT-%05d', $n), range(1, $rounds)), 'no gaps in the return numbers');
    purchaseRaceBooksHealthy($db);
})->group('concurrency');

it('lets either the void or the payment through, never both, when they race on one delivery', function () {
    [$workers, $rounds] = [4, 5];
    $run = purchaseRaceRun('void-or-pay', 'credit', $workers, $rounds);
    $db = DB::connection('purchaserace');

    expect($run['rows'])->toHaveCount($workers * $rounds);
    $purchases = $db->table('purchases')->where('kind', 'PURCHASE')->orderBy('id')->get();
    foreach (range(1, $rounds) as $round) {
        ['won' => $won, 'lost' => $lost] = purchaseRaceRound($run['rows'], $round);
        expect($won)->toHaveCount(1, "round {$round}: exactly one of void and payment goes through")
            ->and($lost)->toHaveCount($workers - 1, "round {$round}: the rest are refused");

        $purchase = $purchases[$round - 1];
        $payments = $db->table('supplier_payments')->where('supplier_id', $purchase->supplier_id)->where('status', 'POSTED')->count();
        $owed = (int) $db->table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->where('accounts.code', ChartOfAccounts::ACCOUNTS_PAYABLE)
            ->where('journal_lines.supplier_id', $purchase->supplier_id)->selectRaw('COALESCE(SUM(credit_minor) - SUM(debit_minor), 0) AS b')->value('b');
        if ($won[0]['did'] === 'void') {
            expect($purchase->status)->toBe('VOIDED')->and($payments)->toBe(0, "round {$round}: no payment after the void");
        } else {
            expect($purchase->status)->toBe('POSTED')->and($payments)->toBe(1, "round {$round}: one payment, and the delivery stands");
        }
        expect($owed)->toBe(0, "round {$round}: the supplier is owed nothing, and owes nothing");
    }
    purchaseRaceBooksHealthy($db);
})->group('concurrency');
