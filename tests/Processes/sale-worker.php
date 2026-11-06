<?php

declare(strict_types=1);

/*
| One till, as a separate PHP process, for SaleConcurrencyTest.
|
| Usage: php sale-worker.php setup <products> <units-each> [sales|quotes]
|        php sale-worker.php last-unit <rounds> <worker-id>
|        php sale-worker.php duplicate <rounds> <worker-id>
|        php sale-worker.php return <rounds> <worker-id>
|        php sale-worker.php convert <rounds> <worker-id>
| "sales" rings up one cash sale of 6 units per product, for "return";
| "quotes" writes one quote for 1 unit per product, for "convert".
| (DB_DATABASE from the environment.) Prints one JSON line per attempt.
*/

use App\Domain\Errors\DomainError;
use App\Enums\AdjustmentReason;
use App\Services\Actor;
use App\Services\CatalogService;
use App\Services\QuotationService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use Illuminate\Support\Facades\DB;

require __DIR__.'/boot.php';

const SALE_WORKER_DATE = '2026-11-05';

if ($argv[1] === 'setup') {
    [$products, $units] = [(int) $argv[2], (int) $argv[3]];
    $id = (int) DB::table('users')->insertGetId(['username' => 'till', 'display_name' => 'Till', 'role' => 'OWNER', 'password_hash' => 'x']);
    $actor = new Actor($id, 'till');
    DB::table('business_settings')->where('id', 1)->update(['allow_negative_stock' => false]);
    for ($p = 1; $p <= $products; $p++) {
        $product = CatalogService::createProduct(['name' => "Milo {$p}", 'costPrice' => 333, 'sellingPrice' => 500, 'unit' => 'pcs'], $actor);
        StockAdjustmentService::createStockAdjustment([
            'businessDate' => SALE_WORKER_DATE,
            'reason' => AdjustmentReason::OpeningStock,
            'items' => [['productId' => $product, 'direction' => 'IN', 'qty' => $units * 1_000, 'totalCost' => $units * 333]],
        ], $actor);
        $cash = (int) DB::table('payment_accounts')->where('kind', 'CASH')->orderBy('id')->value('id');
        if (($argv[4] ?? null) === 'sales') {
            SaleService::createSale([
                'businessDate' => SALE_WORKER_DATE,
                'items' => [['productId' => $product, 'qty' => 6_000]],
                'tenders' => [['paymentAccountId' => $cash, 'amount' => 3_000]],
            ], $actor);
        }
        if (($argv[4] ?? null) === 'quotes') {
            QuotationService::createQuotation([
                'businessDate' => SALE_WORKER_DATE, 'validUntil' => SALE_WORKER_DATE, 'customerName' => "Customer {$p}",
                'lines' => [['productId' => $product, 'qty' => 1_000]],
            ], $actor);
        }
    }
    exit(0);
}

[$mode, $rounds, $worker] = [$argv[1], (int) $argv[2], (int) $argv[3]];
$actor = new Actor((int) DB::table('users')->where('username', 'till')->value('id'), 'till');
$products = DB::table('products')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
$cash = (int) DB::table('payment_accounts')->where('kind', 'CASH')->orderBy('id')->value('id');
$saleLines = DB::table('sale_items')->orderBy('id')->get(['id', 'sale_id', 'qty_milli'])->all();
$quotes = DB::table('quotations')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

$go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nunabooks-sale-go-'.env('DB_DATABASE');
while (! file_exists($go)) {
    usleep(2_000);
}

for ($round = 1; $round <= $rounds; $round++) {
    startRound($go, $round, $worker);
    $start = microtime(true);
    // Every till rings up the same thing at once: round N's product, or round N's cart.
    $product = $mode === 'last-unit' ? $products[$round - 1] : $products[0];
    try {
        if ($mode === 'return') {
            // Every till takes back the whole of round N's cash sale, refunding it in full.
            $line = $saleLines[$round - 1];
            $back = ReturnsService::createCustomerReturn((int) $line->sale_id, [
                'businessDate' => SALE_WORKER_DATE,
                'items' => [['itemId' => (int) $line->id, 'qty' => (int) $line->qty_milli]],
                'refunds' => [['paymentAccountId' => $cash, 'amount' => 3_000]],
            ], $actor);
            echo json_encode(['round' => $round, 'start' => $start, 'end' => microtime(true), 'ok' => true, 'receipt' => $back['receiptNo']]), "\n";

            continue;
        }
        if ($mode === 'convert') {
            $sale = QuotationService::convertQuotation($quotes[$round - 1], [
                'businessDate' => SALE_WORKER_DATE,
                'tenders' => [['paymentAccountId' => $cash, 'amount' => 500]],
            ], $actor);
            echo json_encode(['round' => $round, 'start' => $start, 'end' => microtime(true), 'ok' => true, 'receipt' => $sale['receiptNo'], 'saleId' => $sale['saleId']]), "\n";

            continue;
        }
        $sale = SaleService::createSale([
            'businessDate' => SALE_WORKER_DATE,
            'items' => [['productId' => $product, 'qty' => 1_000]],
            'tenders' => [['paymentAccountId' => $cash, 'amount' => 500]],
            ...($mode === 'duplicate' ? ['clientRef' => "cart-{$round}"] : []),
        ], $actor);
        echo json_encode(['round' => $round, 'start' => $start, 'end' => microtime(true), 'ok' => true, 'receipt' => $sale['receiptNo'], 'saleId' => $sale['saleId']]), "\n";
    } catch (DomainError $e) {
        echo json_encode(['round' => $round, 'start' => $start, 'end' => microtime(true), 'ok' => false, 'code' => $e->errorCode, 'error' => $e->getMessage()]), "\n";
    }
}
