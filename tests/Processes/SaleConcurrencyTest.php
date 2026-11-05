<?php

declare(strict_types=1);

/*
| Tills selling at the same moment, as real PHP processes against one MySQL
| database. SQLite's single writer hid both races; here they are made to
| happen, and InnoDB's row-lock waits prove the tills really collided.
|
|   1. The last unit: four tills sell the last unit of a product at once, five
|      products in a row. One sale per product; the rest are told there is
|      not enough stock; the shelf ends at 0 with value 0, and the receipt
|      numbers run with no gap although every loser took one and rolled back.
|   2. Double submission: four tills send the same cart (clientRef) at once,
|      five carts in a row. One sale, one stock movement and one journal
|      entry per cart, and every till is answered with the same receipt.
|   3. Double return: four tills take back the whole of the same cash sale at
|      once. One return; the stock comes back and the cash goes out once.
|   4. Double convert: four tills turn the same quote into a sale at once. One
|      sale, one stock movement, and the quote points at it.
*/

use App\Domain\Accounting\ChartOfAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

const SALE_RACE_DB = 'nunabooks_concurrency';

function saleRaceEnv(): array
{
    return ['DB_DATABASE' => SALE_RACE_DB, 'APP_ENV' => 'testing'];
}

function saleRaceLockWaits(): int
{
    return (int) DB::selectOne("SHOW GLOBAL STATUS LIKE 'Innodb_row_lock_waits'")->Value;
}

/** Set up the shop, start the tills, release them together, and collect what each printed. */
function saleRaceRun(string $mode, int $workers, int $rounds, int $products, int $unitsEach, ?string $documents = null): array
{
    $setup = ['tests/Processes/sale-worker.php', 'setup', (string) $products, (string) $unitsEach, ...($documents === null ? [] : [$documents])];
    foreach ([['artisan', 'migrate:fresh', '--seed', '--force'], $setup] as $command) {
        $run = Process::env(saleRaceEnv())->path(base_path())->timeout(300)->run([PHP_BINARY, ...$command]);
        expect($run->successful())->toBeTrue($run->errorOutput().$run->output());
    }

    $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nunabooks-sale-go';
    @unlink($go);
    $pool = Process::pool(function ($pool) use ($mode, $workers, $rounds) {
        for ($w = 1; $w <= $workers; $w++) {
            $pool->as("till-{$w}")->env(saleRaceEnv())->path(base_path())->timeout(300)
                ->command([PHP_BINARY, 'tests/Processes/sale-worker.php', $mode, (string) $rounds, (string) $w]);
        }
    })->start();
    usleep(1_500_000); // every till booted and waiting at the line
    $waitsBefore = saleRaceLockWaits();
    touch($go);
    $results = $pool->wait();
    @unlink($go);

    $rows = [];
    foreach ($results as $name => $result) {
        expect($result->successful())->toBeTrue("{$name}: ".$result->errorOutput().$result->output());
        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            $row = json_decode($line, true);
            expect($row)->toBeArray("{$name} printed something other than a result: {$line}");
            $rows[] = $row;
        }
    }

    return ['rows' => $rows, 'waits' => saleRaceLockWaits() - $waitsBefore];
}

beforeEach(function () {
    DB::statement('CREATE DATABASE IF NOT EXISTS `'.SALE_RACE_DB.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin');
    config(['database.connections.salerace' => array_merge(config('database.connections.mysql'), ['database' => SALE_RACE_DB])]);
});

it('sells the last unit once when four tills ring it up at the same moment', function () {
    [$workers, $rounds] = [4, 5];
    $run = saleRaceRun('last-unit', $workers, $rounds, $rounds, 1);
    $db = DB::connection('salerace');

    expect($run['rows'])->toHaveCount($workers * $rounds)
        ->and($run['waits'])->toBeGreaterThanOrEqual($rounds);
    foreach (range(1, $rounds) as $round) {
        $attempts = array_values(array_filter($run['rows'], fn (array $r): bool => $r['round'] === $round));
        $won = array_values(array_filter($attempts, fn (array $r): bool => $r['ok']));
        $lost = array_values(array_filter($attempts, fn (array $r): bool => ! $r['ok']));
        expect($won)->toHaveCount(1, "round {$round}: exactly one till sells the last unit")
            ->and(array_unique(array_column($lost, 'code')))->toBe(['INSUFFICIENT_STOCK'], "round {$round}: the others are told there is no stock");
    }

    // The shelf is empty and worth nothing, and the books say the same.
    foreach ($db->table('products')->get() as $product) {
        expect([(int) $product->qty_on_hand_milli, (int) $product->stock_value_minor])->toBe([0, 0], "{$product->name} on the shelf");
    }
    $inventory = (int) $db->table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.code', ChartOfAccounts::INVENTORY)->selectRaw('COALESCE(SUM(debit_minor) - SUM(credit_minor), 0) AS b')->value('b');
    expect($inventory)->toBe(0);

    // Every loser took a receipt number and gave it back: no gaps.
    $receipts = $db->table('sales')->orderBy('receipt_no')->pluck('receipt_no')->all();
    expect($receipts)->toBe(array_map(fn (int $n): string => sprintf('RCP-%05d', $n), range(1, $rounds)))
        ->and((int) $db->table('sequences')->where('doc_type', 'RECEIPT')->value('next_number'))->toBe($rounds + 1)
        ->and($db->table('stock_ledger')->where('movement_type', 'SALE')->count())->toBe($rounds);
})->group('concurrency');

it('records one sale when the same cart is submitted from four tills at once', function () {
    [$workers, $rounds] = [4, 5];
    $run = saleRaceRun('duplicate', $workers, $rounds, 1, 100);
    $db = DB::connection('salerace');

    expect($run['rows'])->toHaveCount($workers * $rounds)
        ->and($run['waits'])->toBeGreaterThanOrEqual($rounds);
    foreach (range(1, $rounds) as $round) {
        $answers = array_values(array_filter($run['rows'], fn (array $r): bool => $r['round'] === $round));
        $failed = array_filter($answers, fn (array $r): bool => ! $r['ok']);
        expect($failed)->toBe([], "cart {$round}: no till was refused")
            ->and(array_unique(array_column($answers, 'receipt')))->toHaveCount(1, "cart {$round}: every till got the same receipt");

        $sales = $db->table('sales')->where('client_ref', "cart-{$round}")->pluck('id')->all();
        expect($sales)->toHaveCount(1, "cart {$round}: one sale")
            ->and($db->table('stock_ledger')->where('source_type', 'SALE')->where('source_id', $sales[0])->count())->toBe(1, "cart {$round}: one stock movement")
            ->and($db->table('journal_entries')->where('source_type', 'SALE')->where('source_id', $sales[0])->count())->toBe(1, "cart {$round}: one journal entry");
    }

    expect($db->table('sales')->count())->toBe($rounds)
        ->and((int) $db->table('products')->value('qty_on_hand_milli'))->toBe((100 - $rounds) * 1_000)
        ->and($db->table('sales')->orderBy('receipt_no')->pluck('receipt_no')->all())->toBe(array_map(fn (int $n): string => sprintf('RCP-%05d', $n), range(1, $rounds)))
        ->and((int) $db->table('journal_lines')->sum('debit_minor'))->toBe((int) $db->table('journal_lines')->sum('credit_minor'));
})->group('concurrency');

/** @return array{won: list<array>, lost: list<array>} for one round */
function saleRaceRound(array $rows, int $round): array
{
    $attempts = array_values(array_filter($rows, fn (array $r): bool => $r['round'] === $round));

    return [
        'won' => array_values(array_filter($attempts, fn (array $r): bool => $r['ok'])),
        'lost' => array_values(array_filter($attempts, fn (array $r): bool => ! $r['ok'])),
    ];
}

it('takes goods back once when four tills return the same sale at the same moment', function () {
    [$workers, $rounds] = [4, 5];
    $run = saleRaceRun('return', $workers, $rounds, $rounds, 10, 'sales');
    $db = DB::connection('salerace');

    expect($run['rows'])->toHaveCount($workers * $rounds)
        ->and($run['waits'])->toBeGreaterThanOrEqual($rounds);
    foreach (range(1, $rounds) as $round) {
        ['won' => $won, 'lost' => $lost] = saleRaceRound($run['rows'], $round);
        expect($won)->toHaveCount(1, "round {$round}: exactly one till takes the goods back")
            ->and($lost)->toHaveCount($workers - 1, "round {$round}: the others are refused");
    }

    // 10 in, 6 sold, 6 back: once, not four times.
    foreach ($db->table('products')->get() as $product) {
        expect((int) $product->qty_on_hand_milli)->toBe(10_000, "{$product->name} on the shelf");
    }
    foreach ($db->table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->where('sales.kind', 'SALE')->get(['sale_items.*']) as $line) {
        expect((int) $line->returned_qty_milli)->toBe((int) $line->qty_milli, "line {$line->id} returned once");
    }
    expect($db->table('sales')->where('kind', 'RETURN')->count())->toBe($rounds)
        ->and($db->table('stock_ledger')->where('movement_type', 'SALE_RETURN')->count())->toBe($rounds)
        ->and((int) $db->table('sale_payments')->where('amount_minor', '<', 0)->sum('amount_minor'))->toBe(-3_000 * $rounds, 'each sale refunded once');

    // The books balance and the stock account agrees with the shelf.
    $inventory = (int) $db->table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.code', ChartOfAccounts::INVENTORY)->selectRaw('COALESCE(SUM(debit_minor) - SUM(credit_minor), 0) AS b')->value('b');
    expect((int) $db->table('journal_lines')->sum('debit_minor'))->toBe((int) $db->table('journal_lines')->sum('credit_minor'))
        ->and($inventory)->toBe((int) $db->table('products')->sum('stock_value_minor'));
})->group('concurrency');

it('makes one sale when four tills convert the same quote at the same moment', function () {
    [$workers, $rounds] = [4, 5];
    $run = saleRaceRun('convert', $workers, $rounds, $rounds, 10, 'quotes');
    $db = DB::connection('salerace');

    expect($run['rows'])->toHaveCount($workers * $rounds)
        ->and($run['waits'])->toBeGreaterThanOrEqual($rounds);
    $quotes = $db->table('quotations')->orderBy('id')->get();
    foreach (range(1, $rounds) as $round) {
        ['won' => $won, 'lost' => $lost] = saleRaceRound($run['rows'], $round);
        expect($won)->toHaveCount(1, "round {$round}: exactly one till converts the quote")
            ->and($lost)->toHaveCount($workers - 1, "round {$round}: the others are refused")
            ->and($quotes[$round - 1]->status)->toBe('CONVERTED')
            ->and((int) $quotes[$round - 1]->converted_sale_id)->toBe($won[0]['saleId'], "round {$round}: the quote points at the one sale");
    }

    expect($db->table('sales')->count())->toBe($rounds)
        ->and($db->table('stock_ledger')->where('movement_type', 'SALE')->count())->toBe($rounds);
    foreach ($db->table('products')->get() as $product) {
        expect((int) $product->qty_on_hand_milli)->toBe(9_000, "{$product->name} on the shelf");
    }
    expect((int) $db->table('journal_lines')->sum('debit_minor'))->toBe((int) $db->table('journal_lines')->sum('credit_minor'));
})->group('concurrency');
