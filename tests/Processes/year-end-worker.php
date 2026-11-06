<?php

declare(strict_types=1);

/*
| One desk, as a separate PHP process, for YearEndCloseConcurrencyTest.
|
| Usage: php year-end-worker.php setup <years>
|        php year-end-worker.php close <rounds> <worker-id> <workers>
|        php year-end-worker.php sell <rounds> <worker-id> <workers>
| Setup stocks one product on 2020-01-01 and sells some of it mid-year in
| each of <years> years from 2020. Round N works on the year 2019 + N:
| "close" closes it, "sell" rings up one unit dated on its last day. Every
| worker waits for every other at the start of each round, so each round is
| a real race. (DB_DATABASE from the environment.) Prints one JSON line per
| attempt.
*/

use App\Domain\Errors\DomainError;
use App\Enums\AdjustmentReason;
use App\Services\Actor;
use App\Services\CatalogService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use App\Services\YearEndCloseService;
use Illuminate\Support\Facades\DB;

require __DIR__.'/boot.php';

const YEAR_END_FIRST = 2020;

if ($argv[1] === 'setup') {
    $id = (int) DB::table('users')->insertGetId(['username' => 'desk', 'display_name' => 'Desk', 'role' => 'OWNER', 'password_hash' => 'x']);
    $actor = new Actor($id, 'desk');
    DB::table('business_settings')->where('id', 1)->update(['allow_negative_stock' => false, 'financial_year_start_month' => 1]);
    $product = CatalogService::createProduct(['name' => 'Milo', 'costPrice' => 300, 'sellingPrice' => 500, 'unit' => 'pcs'], $actor);
    StockAdjustmentService::createStockAdjustment([
        'businessDate' => YEAR_END_FIRST.'-01-01',
        'reason' => AdjustmentReason::OpeningStock,
        'items' => [['productId' => $product, 'direction' => 'IN', 'qty' => 1_000_000, 'totalCost' => 300_000]],
    ], $actor);
    $cash = (int) DB::table('payment_accounts')->where('kind', 'CASH')->orderBy('id')->value('id');
    for ($y = YEAR_END_FIRST; $y < YEAR_END_FIRST + (int) $argv[2]; $y++) {
        SaleService::createSale(['businessDate' => "{$y}-06-01", 'items' => [['productId' => $product, 'qty' => 2_000]], 'tenders' => [['paymentAccountId' => $cash, 'amount' => 1_000]]], $actor);
    }
    exit(0);
}

[$mode, $rounds, $worker, $workers] = [$argv[1], (int) $argv[2], (int) $argv[3], (int) $argv[4]];
$actor = new Actor((int) DB::table('users')->where('username', 'desk')->value('id'), 'desk');
$product = (int) DB::table('products')->orderBy('id')->value('id');
$cash = (int) DB::table('payment_accounts')->where('kind', 'CASH')->orderBy('id')->value('id');

$dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nunabooks-year-end';
$go = $dir.DIRECTORY_SEPARATOR.'go';
while (! file_exists($go)) {
    usleep(2_000);
}

for ($round = 1; $round <= $rounds; $round++) {
    // Wait at the line for every other desk, then go together.
    // Give up waiting after 30 s, so a desk that crashed fails the test instead of hanging it.
    touch($dir.DIRECTORY_SEPARATOR."r{$round}-w{$worker}");
    $deadline = microtime(true) + 30;
    while (count(glob($dir.DIRECTORY_SEPARATOR."r{$round}-w*")) < $workers && microtime(true) < $deadline) {
        usleep(500);
    }
    $year = YEAR_END_FIRST + $round - 1;
    try {
        if ($mode === 'close') {
            $closed = YearEndCloseService::closeFinancialYear($year, $actor);
            echo json_encode(['round' => $round, 'mode' => $mode, 'ok' => true, 'entryId' => $closed['entryId'], 'profit' => $closed['profit']]), "\n";

            continue;
        }
        $sale = SaleService::createSale([
            'businessDate' => "{$year}-12-31",
            'items' => [['productId' => $product, 'qty' => 1_000]],
            'tenders' => [['paymentAccountId' => $cash, 'amount' => 500]],
        ], $actor);
        echo json_encode(['round' => $round, 'mode' => $mode, 'ok' => true, 'saleId' => $sale['saleId']]), "\n";
    } catch (DomainError $e) {
        echo json_encode(['round' => $round, 'mode' => $mode, 'ok' => false, 'code' => $e->errorCode, 'error' => $e->getMessage()]), "\n";
    }
}
