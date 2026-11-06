<?php

declare(strict_types=1);

/*
| One till adjusting the same product as every other till, for
| StockConcurrencyTest. Alternates stock in (at an awkward cost) and stock out
| (at the running average) on product 1, through InventoryService directly.
|
| Usage: php stock-worker.php setup
|        php stock-worker.php run <iterations> <worker-id>   (DB_DATABASE from the environment)
*/

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Enums\AdjustmentReason;
use App\Enums\JournalSourceType;
use App\Enums\MovementType;
use App\Services\Actor;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Services\JournalService;
use App\Services\StockAdjustmentService;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;

require __DIR__.'/boot.php';

if ($argv[1] === 'setup') {
    $id = (int) DB::table('users')->insertGetId(['username' => 'till', 'display_name' => 'Till', 'role' => 'OWNER', 'password_hash' => 'x']);
    $actor = new Actor($id, 'till');
    $product = CatalogService::createProduct(['name' => 'Milo', 'costPrice' => 333, 'sellingPrice' => 500, 'unit' => 'pcs'], $actor);
    StockAdjustmentService::createStockAdjustment([
        'businessDate' => '2026-11-05',
        'reason' => AdjustmentReason::OpeningStock,
        'items' => [['productId' => $product, 'direction' => 'IN', 'qty' => 100_000, 'totalCost' => 33_333]],
    ], $actor);
    echo $product, "\n";
    exit(0);
}

[$iterations, $worker] = [(int) $argv[2], (int) $argv[3]];
$actor = new Actor((int) DB::table('users')->where('username', 'till')->value('id'), 'till');
$product = (int) DB::table('products')->value('id');

$go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nunabooks-stock-go-'.env('DB_DATABASE');
while (! file_exists($go)) {
    usleep(5_000);
}

for ($i = 1; $i <= $iterations; $i++) {
    startRound($go, $i, $worker);
    $start = microtime(true);
    $in = ($i + $worker) % 2 === 0;
    // The stock-movement core every document runs, without a document number: an
    // adjustment takes its number before any movement, and that sequence lock
    // would serialise the tills by itself and hide a missing product lock.
    Tx::run(function () use ($in, $i, $worker, $product, $actor): void {
        InventoryService::lockProducts([$product]);
        JournalService::readLockDate();
        $moved = InventoryService::recordStockMovement([
            'productId' => $product,
            'direction' => $in ? 'IN' : 'OUT',
            'qty' => 1_000,
            ...($in ? ['totalCost' => 331 + $worker] : []),
            'movementType' => $in ? MovementType::AdjustmentIn : MovementType::AdjustmentOut,
            'sourceType' => 'TEST',
            'sourceId' => $worker * 1_000 + $i,
            'businessDate' => '2026-11-05',
            'occurredAt' => Clock::now(),
            'userId' => $actor->id,
        ]);
        [$inventory, $shrinkage] = [JournalService::accountIdByCode(ChartOfAccounts::INVENTORY), JournalService::accountIdByCode(ChartOfAccounts::INVENTORY_SHRINKAGE)];
        JournalService::post('2026-11-05', JournalSourceType::StockAdjustment, $in
            ? [Journal::debit($inventory, $moved['totalCost']), Journal::credit($shrinkage, $moved['totalCost'])]
            : [Journal::debit($shrinkage, $moved['totalCost']), Journal::credit($inventory, $moved['totalCost'])], sourceId: $worker * 1_000 + $i);
    });
    echo json_encode(['round' => $i, 'start' => $start, 'end' => microtime(true)]), "\n";
}
