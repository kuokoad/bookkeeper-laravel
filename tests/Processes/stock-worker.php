<?php

declare(strict_types=1);

/*
| One till adjusting the same product as every other till, for
| StockConcurrencyTest. Alternates stock found (in, at an awkward cost) and
| stock damaged (out, at the running average) on product 1.
|
| Usage: php stock-worker.php setup
|        php stock-worker.php run <iterations> <worker-id>   (DB_DATABASE from the environment)
*/

use App\Enums\AdjustmentReason;
use App\Services\Actor;
use App\Services\CatalogService;
use App\Services\StockAdjustmentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

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

$go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nunabooks-stock-go';
while (! file_exists($go)) {
    usleep(5_000);
}

for ($i = 1; $i <= $iterations; $i++) {
    $in = ($i + $worker) % 2 === 0;
    StockAdjustmentService::createStockAdjustment([
        'businessDate' => '2026-11-05',
        'reason' => $in ? AdjustmentReason::Found : AdjustmentReason::Damaged,
        'items' => [$in
            ? ['productId' => $product, 'direction' => 'IN', 'qty' => 1_000, 'totalCost' => 331 + $worker]
            : ['productId' => $product, 'direction' => 'OUT', 'qty' => 1_000]],
    ], $actor);
    echo $in ? "in\n" : "out\n";
}
