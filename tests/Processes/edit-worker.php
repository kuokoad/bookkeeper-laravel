<?php

declare(strict_types=1);

/*
| One owner editing the same stock adjustment as every other till, for
| EditConcurrencyTest. Each round rewrites the damage write-off to a quantity
| of its own, through StockAdjustmentService::editStockAdjustment.
|
| Usage: php edit-worker.php setup
|        php edit-worker.php run <iterations> <worker-id>   (DB_DATABASE from the environment)
*/

use App\Database\Tx;
use App\Enums\AdjustmentReason;
use App\Services\Actor;
use App\Services\CatalogService;
use App\Services\StockAdjustmentService;
use Illuminate\Support\Facades\DB;

require __DIR__.'/boot.php';

const EDIT_RACE_DAY = '2026-11-05';

$damage = fn (int $product, int $qty): array => ['businessDate' => EDIT_RACE_DAY, 'reason' => AdjustmentReason::Damaged, 'items' => [['productId' => $product, 'direction' => 'OUT', 'qty' => $qty]]];

if ($argv[1] === 'setup') {
    $id = (int) DB::table('users')->insertGetId(['username' => 'till', 'display_name' => 'Till', 'role' => 'OWNER', 'password_hash' => 'x']);
    $actor = new Actor($id, 'till');
    $product = CatalogService::createProduct(['name' => 'Milo', 'costPrice' => 333, 'sellingPrice' => 500, 'unit' => 'pcs'], $actor);
    StockAdjustmentService::createStockAdjustment([
        'businessDate' => EDIT_RACE_DAY,
        'reason' => AdjustmentReason::OpeningStock,
        'items' => [['productId' => $product, 'direction' => 'IN', 'qty' => 100_000, 'totalCost' => 33_333]],
    ], $actor);
    echo StockAdjustmentService::createStockAdjustment($damage($product, 10_000), $actor)['adjustmentId'], "\n";
    exit(0);
}

[$iterations, $worker] = [(int) $argv[2], (int) $argv[3]];
$actor = new Actor((int) DB::table('users')->where('username', 'till')->value('id'), 'till');
$product = (int) DB::table('products')->value('id');
$adjustment = (int) DB::table('stock_adjustments')->where('reason', AdjustmentReason::Damaged->value)->value('id');

$go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nunabooks-edit-go-'.env('DB_DATABASE');
while (! file_exists($go)) {
    usleep(5_000);
}

for ($i = 1; $i <= $iterations; $i++) {
    startRound($go, $i, $worker);
    $start = microtime(true);
    // Every till asks for a different count, so a lost update shows as stock that matches no single edit.
    $qty = ($worker * 10 + $i) * 1_000;
    // Held a moment after the edit, locks and all, so every till is inside its own edit at once even on a busy PC.
    // After, not before: the edit itself still reads first and then waits, as it would at a real till.
    Tx::run(function () use ($adjustment, $damage, $product, $qty, $worker, $i, $actor): void {
        StockAdjustmentService::editStockAdjustment($adjustment, $damage($product, $qty), "Recount {$worker}.{$i}", $actor);
        usleep(200_000);
    });
    echo json_encode(['round' => $i, 'start' => $start, 'end' => microtime(true)]), "\n";
}
