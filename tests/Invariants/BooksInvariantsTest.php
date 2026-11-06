<?php

declare(strict_types=1);

/*
| The five invariant tests of the old ARCHITECTURE §9, the ones that matter
| most, over a randomised but seeded run of real trading through the real
| services: sales (cash, credit, part-paid), customer returns, sale voids,
| deliveries (cash and credit), supplier returns, purchase voids, customer
| and supplier payments, expenses, transfers and stock adjustments. A step
| the shop would refuse (not enough stock, a refund over the cap) is
| refused and skipped, and invariant 5 holds that it left nothing behind.
|
|   1. every journal entry balances;
|   2. the balance sheet balances, at every month end and at the last day;
|   3. Σ stock_ledger value per product = the product cache, and their sum = GL 1200;
|   4. A/R subledger = GL 1100, A/P subledger = GL 2000;
|   5. a rolled-back operation leaves zero partial rows.
|
|   6. "outstanding", defined twice (SQL for the lists, PHP for one document),
|      agrees for every sale and purchase.
|
| Invariant 3 runs again with negative stock allowed and a thin opening shelf,
| so stock goes below zero and comes back at other prices (D26).
*/

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\BusinessDate;
use App\Domain\Errors\DomainError;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\FinancialService;
use App\Services\Reporting\LedgerService;
use App\Services\Reporting\SubledgerSplit;
use App\Services\ReturnsService;
use App\Services\SaleService;
use App\Services\SupplierPaymentService;
use App\Services\SupplierService;
use App\Services\TransferService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const INV_SEED = 20261105;
const INV_START = '2026-01-05';
const INV_STEPS = 80;

/** Every table's rows (and the sequence counters), to prove a refused write left nothing. */
function invSnapshot(): array
{
    static $sql = null;
    $sql ??= implode(' UNION ALL ', array_map(
        fn (object $t): string => "SELECT '{$t->t}' AS t, COUNT(*) AS n FROM `{$t->t}`",
        DB::select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\' ORDER BY TABLE_NAME'),
    ));
    $snapshot = [];
    foreach (DB::select($sql) as $row) {
        $snapshot[$row->t] = (int) $row->n;
    }
    $snapshot['sequences:values'] = DB::table('sequences')->orderBy('doc_type')->get()->map(fn (object $r): string => json_encode($r))->implode('|');
    // Row counts miss a refused step that only UPDATEs: hold the stock caches, every party row and the settings too.
    $stock = DB::table('products')->selectRaw('COALESCE(SUM(products.qty_on_hand_milli), 0) AS q, COALESCE(SUM(products.stock_value_minor), 0) AS v')->first();
    $snapshot['products:qty'] = (int) $stock->q;
    $snapshot['products:value'] = (int) $stock->v;
    // crc32 in PHP over the rows in id order: GROUP_CONCAT would cut off at group_concat_max_len.
    foreach (['products', 'customers', 'suppliers'] as $table) {
        $snapshot["{$table}:crc"] = crc32(DB::table($table)->orderBy("{$table}.id")->get()->toJson());
    }
    $snapshot['business_settings:row'] = json_encode(DB::table('business_settings')->get());

    return $snapshot;
}

/** Run one step; a refusal must roll back to exactly where it started. */
function invAttempt(callable $step): bool
{
    $before = invSnapshot();
    try {
        $step();

        return true;
    } catch (DomainError) {
        expect(invSnapshot())->toBe($before, 'a refused step left rows behind');

        return false;
    }
}

/** The seeded run. Returns the last trading day. */
function invTrade(bool $allowNegative = false): string
{
    mt_srand(INV_SEED);
    $pick = fn (array $list) => $list[mt_rand(0, count($list) - 1)];

    // The run states the settings it relies on, rather than trusting whatever the database holds.
    DB::table('business_settings')->where('id', 1)->update(['books_locked_before' => null, 'tax_enabled' => false, 'allow_negative_stock' => $allowNegative, 'allow_overpayment' => false, 'expiry_blocks_sales' => false]);
    $actor = Shop::owner();
    $cash = Shop::paymentAccount('CASH');
    $bank = Shop::paymentAccount('BANK');
    $momo = Shop::paymentAccount('MOBILE_MONEY');
    Shop::capital($actor, $cash, 2_000_000, INV_START);
    Shop::capital($actor, $bank, 5_000_000, INV_START);

    $products = [];
    foreach ([['Rice 5kg', 6_000, 9_500], ['Milo 400g', 3_200, 4_500], ['Oil 1L', 2_100, 3_000], ['Sugar 1kg', 1_150, 1_600]] as [$name, $cost, $price]) {
        $id = Shop::product($actor, $name, $cost, $price);
        $opening = $allowNegative ? 2 : 40;
        Shop::openingStock($actor, $id, $opening * 1000, $opening * $cost, INV_START);
        $products[$id] = $cost;
    }
    $customers = [Shop::customer($actor, 'Mensah Provisions'), Shop::customer($actor, 'Auntie Akos')];
    $suppliers = [Shop::supplier($actor, 'Kumasi Depot'), Shop::supplier($actor, 'Tema Wholesale')];
    $category = Shop::expenseCategory('Transport');

    $sales = [];
    $purchases = [];
    $date = INV_START;
    for ($i = 0; $i < INV_STEPS; $i++) {
        if (mt_rand(0, 2) === 0) {
            $date = BusinessDate::addDays($date, mt_rand(1, 3));
        }
        $product = array_rand($products);
        $units = mt_rand(1, 4);

        match (mt_rand(0, 11)) {
            0, 1 => invAttempt(function () use (&$sales, $actor, $date, $product, $units, $pick, $customers): void {
                $sale = SaleService::createSale(['businessDate' => $date, 'customerId' => $pick($customers), 'items' => [['productId' => $product, 'qty' => $units * 1000]], 'tenders' => []], $actor);
                $sales[] = $sale['saleId'];
            }),
            2, 3 => invAttempt(function () use (&$sales, $actor, $date, $product, $units, $pick, $cash, $momo): void {
                $sale = SaleService::createSale(['businessDate' => $date, 'items' => [['productId' => $product, 'qty' => $units * 1000]], 'tenders' => [['paymentAccountId' => $pick([$cash, $momo]), 'amount' => 1_000_000]]], $actor);
                $sales[] = $sale['saleId'];
            }),
            4 => invAttempt(function () use (&$sales, $actor, $date, $product, $units, $cash, $customers, $pick): void {
                $sale = SaleService::createSale(['businessDate' => $date, 'customerId' => $pick($customers), 'items' => [['productId' => $product, 'qty' => $units * 1000]], 'tenders' => [['paymentAccountId' => $cash, 'amount' => 1_000]]], $actor);
                $sales[] = $sale['saleId'];
            }),
            5 => $sales === [] ? null : invAttempt(function () use ($sales, $actor, $date, $pick, $cash): void {
                $saleId = $pick($sales);
                $item = SaleService::getSale($saleId)['items'][0];
                ReturnsService::createCustomerReturn($saleId, [
                    'businessDate' => $date,
                    'items' => [['itemId' => $item['id'], 'qty' => 1_000]],
                    'refunds' => mt_rand(0, 1) === 0 ? [] : [['paymentAccountId' => $cash, 'amount' => mt_rand(100, 3_000)]],
                ], $actor);
            }),
            6 => $sales === [] ? null : invAttempt(fn () => SaleService::voidSale($pick($sales), 'Rung up twice', $actor)),
            7 => invAttempt(function () use (&$purchases, $actor, $date, $product, $products, $pick, $suppliers, $bank): void {
                $purchase = Shop::purchase($actor, $pick($suppliers), [[$product, mt_rand(5, 20) * 1000, $products[$product] + mt_rand(-200, 200)]],
                    mt_rand(0, 1) === 0 ? [] : [['paymentAccountId' => $bank, 'amount' => mt_rand(1_000, 50_000)]], ['businessDate' => $date]);
                $purchases[] = $purchase['purchaseId'];
            }),
            8 => $purchases === [] ? null : invAttempt(function () use ($purchases, $actor, $date, $pick): void {
                $purchaseId = $pick($purchases);
                mt_rand(0, 1) === 0
                    ? PurchaseService::voidPurchase($purchaseId, 'Wrong delivery', $actor)
                    : ReturnsService::createSupplierReturn($purchaseId, ['businessDate' => $date, 'items' => [['itemId' => PurchaseService::getPurchase($purchaseId)['items'][0]['id'], 'qty' => 1_000]], 'refunds' => []], $actor);
            }),
            9 => invAttempt(fn () => mt_rand(0, 1) === 0
                ? CustomerPaymentService::recordCustomerPayment(['businessDate' => $date, 'customerId' => $pick($customers), 'paymentAccountId' => $pick([$cash, $momo]), 'amount' => mt_rand(500, 20_000)], $actor)
                : SupplierPaymentService::recordSupplierPayment(['businessDate' => $date, 'supplierId' => $pick($suppliers), 'paymentAccountId' => $bank, 'amount' => mt_rand(500, 40_000)], $actor)),
            10 => invAttempt(fn () => mt_rand(0, 1) === 0
                ? Shop::expense($actor, $date, $category, mt_rand(200, 5_000))
                : TransferService::recordTransfer(['businessDate' => $date, 'fromAccountId' => $cash, 'toAccountId' => $bank, 'amount' => mt_rand(1_000, 30_000)], $actor)),
            11 => invAttempt(fn () => mt_rand(0, 1) === 0
                ? Shop::stockOut($actor, $product, 1_000, $date)
                : Shop::openingStock($actor, $product, mt_rand(1, 5) * 1000, mt_rand(1_000, 10_000), $date)),
        };
    }

    // A fixed tail, so a run whose dice refused every return or void still holds one of each.
    $date = BusinessDate::addDays($date, 1);
    $credit = SaleService::createSale(['businessDate' => $date, 'customerId' => $customers[0], 'items' => [['productId' => array_key_first($products), 'qty' => 2_000]], 'tenders' => []], $actor)['saleId'];
    ReturnsService::createCustomerReturn($credit, ['businessDate' => $date, 'items' => [['itemId' => SaleService::getSale($credit)['items'][0]['id'], 'qty' => 1_000]], 'refunds' => []], $actor);
    $mistake = SaleService::createSale(['businessDate' => $date, 'items' => [['productId' => array_key_last($products), 'qty' => 1_000]], 'tenders' => [['paymentAccountId' => $cash, 'amount' => 1_000_000]]], $actor)['saleId'];
    SaleService::voidSale($mistake, 'Rung up twice', $actor);

    return $date;
}

describe('with negative stock refused', function () {
    beforeEach(function () {
        $this->lastDay = invTrade();
    });

    it('1. balances every journal entry', function () {
        $unbalanced = DB::select('SELECT journal_lines.entry_id FROM journal_lines GROUP BY journal_lines.entry_id HAVING SUM(journal_lines.debit_minor) <> SUM(journal_lines.credit_minor)');

        // Enough trading to mean something: voids, returns and deliveries among it.
        expect(DB::table('sales')->count())->toBeGreaterThan(15)
            ->and(DB::table('sales')->whereNotNull('voids_sale_id')->count())->toBeGreaterThan(0)
            ->and(DB::table('sales')->whereNotNull('returns_sale_id')->count())->toBeGreaterThan(0)
            ->and(DB::table('purchases')->count())->toBeGreaterThan(3)
            ->and(DB::table('expenses')->count() + DB::table('account_transfers')->count())->toBeGreaterThan(0);

        expect($unbalanced)->toBe([])
            ->and(LedgerService::checkBooksIntegrity()['unbalancedEntries'])->toBe([])
            ->and(BalancesService::getTrialBalance()['balanced'])->toBeTrue();
    });

    it('2. balances the balance sheet at every month end and on the last day', function () {
        $dates = [$this->lastDay];
        for ($month = '2026-01'; $month.'-01' <= $this->lastDay; $month = substr(BusinessDate::addDays($month.'-28', 7), 0, 7)) {
            $dates[] = Shop::monthOf($month.'-01')['to'];
        }

        foreach ($dates as $asAt) {
            $sheet = FinancialService::getBalanceSheet($asAt);
            expect($sheet['difference'])->toBe(0, "balance sheet as at {$asAt}")
                ->and($sheet['balances'])->toBeTrue()
                ->and(BalancesService::getTrialBalance(['to' => $asAt])['balanced'])->toBeTrue("trial balance as at {$asAt}");
        }
    });

    it('3. keeps each product\'s stock ledger, its cache and GL 1200 equal', function () {
        $total = 0;
        foreach (DB::table('products')->get(['id', 'qty_on_hand_milli', 'stock_value_minor']) as $product) {
            $ledger = DB::table('stock_ledger')->where('stock_ledger.product_id', $product->id)->selectRaw(
                'COALESCE(SUM(stock_ledger.qty_in_milli - stock_ledger.qty_out_milli), 0) AS q,
                 COALESCE(SUM(CASE WHEN stock_ledger.qty_in_milli > 0 THEN stock_ledger.total_cost_minor ELSE -stock_ledger.total_cost_minor END), 0) AS v'
            )->first();
            expect((int) $ledger->q)->toBe((int) $product->qty_on_hand_milli, "product {$product->id} quantity")
                ->and((int) $ledger->v)->toBe((int) $product->stock_value_minor, "product {$product->id} value");
            $total += (int) $ledger->v;
        }

        expect($total)->toBe(Shop::balance(ChartOfAccounts::INVENTORY));
    });

    it('4. keeps A/R equal to GL 1100 and A/P equal to GL 2000', function () {
        expect(CustomerService::getTotalReceivables())->toBe(Shop::balance(ChartOfAccounts::ACCOUNTS_RECEIVABLE))
            ->and(SupplierService::getTotalPayables())->toBe(Shop::balance(ChartOfAccounts::ACCOUNTS_PAYABLE))
            ->and(SubledgerSplit::getReceivablesSplit()['net'])->toBe(Shop::balance(ChartOfAccounts::ACCOUNTS_RECEIVABLE))
            ->and(SubledgerSplit::getPayablesSplit()['net'])->toBe(Shop::balance(ChartOfAccounts::ACCOUNTS_PAYABLE))
            ->and(LedgerService::checkBooksIntegrity())->toMatchArray(['receivablesMatch' => true, 'payablesMatch' => true]);
    });

    it('6. agrees on what each sale and purchase still owes, list (SQL) and document (PHP)', function () {
        foreach (['sale' => [SaleService::listSales(...), SaleService::getSaleOutstanding(...)], 'purchase' => [PurchaseService::listPurchases(...), PurchaseService::getPurchaseOutstanding(...)]] as $kind => [$list, $one]) {
            $seen = 0;
            for ($offset = 0; ($rows = $list(['limit' => 500, 'offset' => $offset])) !== []; $offset += 500) {
                foreach ($rows as $row) {
                    expect($row['outstandingMinor'])->toBe($one($row['id']), "{$kind} {$row['id']}");
                    $seen++;
                }
            }
            expect($seen)->toBe(DB::table("{$kind}s")->count());
        }
    });

    it('5. leaves zero partial rows when an operation rolls back', function () {
        $actor = Shop::owner('ama');
        $product = (int) DB::table('products')->orderBy('id')->value('id');
        $before = invSnapshot();

        // A whole sale written, then the write fails after it: everything goes.
        expect(fn () => Tx::run(function () use ($actor, $product): void {
            SaleService::createSale(['businessDate' => $this->lastDay, 'items' => [['productId' => $product, 'qty' => 1_000]], 'tenders' => [['paymentAccountId' => Shop::paymentAccount('CASH'), 'amount' => 1_000_000]]], $actor);
            throw new RuntimeException('power cut');
        }))->toThrow(RuntimeException::class, 'power cut');
        expect(invSnapshot())->toBe($before);

        // A sale whose second line cannot be filled: the first line's stock and journal go too.
        $empty = Shop::product($actor, 'Nothing on the shelf');
        $before = invSnapshot();
        expect(fn () => SaleService::createSale(['businessDate' => $this->lastDay, 'items' => [['productId' => $product, 'qty' => 1_000], ['productId' => $empty, 'qty' => 1_000]], 'tenders' => []], $actor))
            ->toThrow(DomainError::class);
        expect(invSnapshot())->toBe($before);

        // The snapshot itself sees a change that only UPDATEs (else the checks above prove nothing for one).
        foreach (['customers' => 'name', 'suppliers' => 'name', 'products' => 'qty_on_hand_milli', 'business_settings' => 'business_name'] as $table => $column) {
            DB::table($table)->orderBy("{$table}.id")->limit(1)->update([$column => $column === 'qty_on_hand_milli' ? DB::raw('qty_on_hand_milli + 1') : 'Renamed']);
            expect(invSnapshot())->not->toBe($before, "an UPDATE to {$table}");
            $before = invSnapshot();
        }
    });
});

describe('with negative stock allowed', function () {
    beforeEach(function () {
        $this->lastDay = invTrade(true);
    });

    it('3. keeps GL 1200 equal to the summed stock value after stock went below zero and came back', function () {
        // The run did go below zero and come back at other prices, or this proves nothing.
        expect(DB::table('stock_ledger')->where('stock_ledger.balance_qty_milli', '<', 0)->count())->toBeGreaterThan(0)
            ->and(DB::table('journal_lines')->where('journal_lines.description', 'like', '%costing difference')->count())->toBeGreaterThan(0)
            ->and(BalancesService::getTrialBalance()['balanced'])->toBeTrue();

        // A residual is posted to the accounts, not the stock ledger, so each cache is proved by replay.
        foreach (DB::table('products')->pluck('id') as $id) {
            expect(InventoryService::verifyProductStock((int) $id)['ok'])->toBeTrue("product {$id} replay");
        }
        expect((int) DB::table('products')->sum('products.stock_value_minor'))->toBe(Shop::balance(ChartOfAccounts::INVENTORY));
    });
});
