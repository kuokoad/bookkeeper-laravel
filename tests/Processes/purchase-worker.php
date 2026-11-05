<?php

declare(strict_types=1);

/*
| One back-office desk, as a separate PHP process, for PurchaseConcurrencyTest.
|
| Usage: php purchase-worker.php setup <rounds> <paid|credit>
|        php purchase-worker.php return <rounds> <worker-id>
|        php purchase-worker.php void-or-pay <rounds> <worker-id>
| Setup makes one supplier per round and one delivery of 10 units at 5.00 from
| each, paid in cash ("paid") or on credit ("credit").
| (DB_DATABASE from the environment.) Prints one JSON line per attempt.
*/

use App\Domain\Errors\DomainError;
use App\Services\Actor;
use App\Services\CatalogService;
use App\Services\PurchaseService;
use App\Services\ReturnsService;
use App\Services\SupplierPaymentService;
use App\Services\SupplierService;
use Illuminate\Support\Facades\DB;

require __DIR__.'/boot.php';

const PURCHASE_WORKER_DATE = '2026-11-05';

$cash = (int) DB::table('payment_accounts')->where('kind', 'CASH')->orderBy('id')->value('id');

if ($argv[1] === 'setup') {
    [$rounds, $paid] = [(int) $argv[2], $argv[3] === 'paid'];
    $id = (int) DB::table('users')->insertGetId(['username' => 'desk', 'display_name' => 'Desk', 'role' => 'OWNER', 'password_hash' => 'x']);
    $actor = new Actor($id, 'desk');
    DB::table('business_settings')->where('id', 1)->update(['allow_negative_stock' => false, 'allow_overpayment' => false]);
    for ($r = 1; $r <= $rounds; $r++) {
        $supplier = SupplierService::createSupplier(['name' => "Depot {$r}"], $actor);
        $product = CatalogService::createProduct(['name' => "Milo {$r}", 'costPrice' => 500, 'sellingPrice' => 800, 'unit' => 'pcs'], $actor);
        PurchaseService::createPurchase([
            'supplierId' => $supplier,
            'businessDate' => PURCHASE_WORKER_DATE,
            'items' => [['productId' => $product, 'qty' => 10_000, 'unitCost' => 500]],
            'tenders' => $paid ? [['paymentAccountId' => $cash, 'amount' => 5_000]] : [],
        ], $actor);
    }
    exit(0);
}

[$mode, $rounds, $worker] = [$argv[1], (int) $argv[2], (int) $argv[3]];
$actor = new Actor((int) DB::table('users')->where('username', 'desk')->value('id'), 'desk');
$purchases = DB::table('purchases')->where('kind', 'PURCHASE')->orderBy('id')->get(['id', 'supplier_id'])->all();
$lines = DB::table('purchase_items')->orderBy('id')->get(['id', 'purchase_id'])->all();

$go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nunabooks-purchase-go';
while (! file_exists($go)) {
    usleep(2_000);
}

for ($round = 1; $round <= $rounds; $round++) {
    $purchase = $purchases[$round - 1];
    try {
        if ($mode === 'return') {
            // Every desk sends 6 of round N's 10 back and takes their 30.00 in cash.
            $back = ReturnsService::createSupplierReturn((int) $purchase->id, [
                'businessDate' => PURCHASE_WORKER_DATE,
                'items' => [['itemId' => (int) $lines[$round - 1]->id, 'qty' => 6_000]],
                'refunds' => [['paymentAccountId' => $cash, 'amount' => 3_000]],
            ], $actor);
            echo json_encode(['round' => $round, 'ok' => true, 'doc' => $back['purchaseNo']]), "\n";

            continue;
        }
        // Odd desks void round N's delivery; even desks pay what is owed on it.
        if ($worker % 2 === 1) {
            $void = PurchaseService::voidPurchase((int) $purchase->id, 'Delivered to the wrong shop', $actor);
            echo json_encode(['round' => $round, 'ok' => true, 'did' => 'void', 'doc' => $void['purchaseNo']]), "\n";
        } else {
            $paid = SupplierPaymentService::recordSupplierPayment([
                'supplierId' => (int) $purchase->supplier_id,
                'businessDate' => PURCHASE_WORKER_DATE,
                'paymentAccountId' => $cash,
                'amount' => 5_000,
            ], $actor);
            echo json_encode(['round' => $round, 'ok' => true, 'did' => 'pay', 'doc' => $paid['paymentNo']]), "\n";
        }
    } catch (DomainError $e) {
        echo json_encode(['round' => $round, 'ok' => false, 'code' => $e->errorCode, 'error' => $e->getMessage()]), "\n";
    }
}
