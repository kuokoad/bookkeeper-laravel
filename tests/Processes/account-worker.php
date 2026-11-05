<?php

declare(strict_types=1);

/*
| A desk working on payment accounts, as a separate PHP process, for
| AccountCodeConcurrencyTest and ArchiveRaceTest.
|
| Usage: php account-worker.php setup
|        php account-worker.php run <accounts> <categories> <worker-id>
|        php account-worker.php setup-archive          (a product in stock and an empty "Spare till")
|        php account-worker.php hold-archive <account-id> (archives it, prints "held", waits 5s, commits)
|        php account-worker.php sell <account-id>      (a cash sale into it: prints "sold" or the refusal)
| (DB_DATABASE from the environment.)
*/

use App\Database\Tx;
use App\Domain\Errors\DomainError;
use App\Enums\AdjustmentReason;
use App\Enums\PaymentAccountKind;
use App\Services\Actor;
use App\Services\CatalogService;
use App\Services\PaymentAccountService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use Illuminate\Support\Facades\DB;

require __DIR__.'/boot.php';

const ACCOUNT_WORKER_DATE = '2026-11-05';

if ($argv[1] === 'setup' || $argv[1] === 'setup-archive') {
    DB::table('users')->insert(['username' => 'desk', 'display_name' => 'Desk', 'role' => 'OWNER', 'password_hash' => 'x']);
}
$actor = new Actor((int) DB::table('users')->where('username', 'desk')->value('id'), 'desk');

if ($argv[1] === 'setup') {
    exit(0);
}

if ($argv[1] === 'setup-archive') {
    $product = CatalogService::createProduct(['name' => 'Milo', 'costPrice' => 333, 'sellingPrice' => 500, 'unit' => 'pcs'], $actor);
    StockAdjustmentService::createStockAdjustment([
        'businessDate' => ACCOUNT_WORKER_DATE,
        'reason' => AdjustmentReason::OpeningStock,
        'items' => [['productId' => $product, 'direction' => 'IN', 'qty' => 10_000, 'totalCost' => 3_330]],
    ], $actor);
    PaymentAccountService::createPaymentAccount(['name' => 'Spare till', 'kind' => PaymentAccountKind::Cash], $actor);
    exit(0);
}

if ($argv[1] === 'hold-archive') {
    $account = (int) $argv[2];
    Tx::run(function () use ($account, $actor): void {
        PaymentAccountService::setPaymentAccountActive($account, false, $actor);
        echo "held\n";
        // Archived but not yet committed: a till rings up a sale meanwhile.
        usleep(5_000_000);
    });
    exit(0);
}

if ($argv[1] === 'sell') {
    try {
        SaleService::createSale([
            'businessDate' => ACCOUNT_WORKER_DATE,
            'items' => [['productId' => (int) DB::table('products')->value('id'), 'qty' => 1_000]],
            'tenders' => [['paymentAccountId' => (int) $argv[2], 'amount' => 500]],
        ], $actor);
        echo "sold\n";
    } catch (DomainError $e) {
        echo $e->getMessage(), "\n";
    }
    exit(0);
}

[$accounts, $categories, $worker] = [(int) $argv[2], (int) $argv[3], (int) $argv[4]];

$go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nunabooks-account-go';
while (! file_exists($go)) {
    usleep(5_000);
}

for ($i = 1; $i <= max($accounts, $categories); $i++) {
    if ($i <= $accounts) {
        $id = PaymentAccountService::createPaymentAccount(['name' => "Wallet {$worker}-{$i}", 'kind' => PaymentAccountKind::MobileMoney], $actor);
        echo 'account ', $id, "\n";
    }
    if ($i <= $categories) {
        $id = PaymentAccountService::createCategory('EXPENSE', "Cost {$worker}-{$i}", $actor);
        echo 'category ', $id, "\n";
    }
}
