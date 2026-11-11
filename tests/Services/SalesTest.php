<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/sales.test.ts: the till, credit, stock
| rules, voids and reads. Account balances come from Shop::balance, which is
| the real BalancesService::getAccountBalanceByCode.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\InsufficientStockError;
use App\Domain\Errors\ValidationError;
use App\Domain\Quantity;
use App\Enums\AdjustmentReason;
use App\Services\CatalogService;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\PaymentAccountService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use App\Services\TransferService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const SALES_TODAY = '2026-08-16';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->momo = Shop::paymentAccount('MOBILE_MONEY');
    $this->make = fn (string $name, int $cost = 500, int $price = 800, string $unit = 'pcs'): int => Shop::product($this->actor, $name, $cost, $price, ['unit' => $unit]);
    $this->addStock = fn (int $id, int $units, int $cost): array => Shop::openingStock($this->actor, $id, $units * 1000, $cost, SALES_TODAY);
    $this->sale = fn (array $input): array => SaleService::createSale(['businessDate' => SALES_TODAY, ...$input], $this->actor);
    $this->pay = fn (int $customerId, int $amount): array => CustomerPaymentService::recordCustomerPayment(
        ['customerId' => $customerId, 'businessDate' => SALES_TODAY, 'paymentAccountId' => $this->cash, 'amount' => $amount],
        $this->actor,
    );
});

describe('cash sale', function () {
    it('reduces stock, records revenue and COGS, and increases cash', function () {
        $id = ($this->make)('Milo 400g', 500, 800);
        ($this->addStock)($id, 10, 5_000);

        $sale = ($this->sale)(['items' => [['productId' => $id, 'qty' => 3_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 2_400]]]);

        expect($sale['total'])->toBe(2_400)
            ->and($sale['cogs'])->toBe(1_500)
            ->and($sale['change'])->toBe(0)
            ->and($sale['outstanding'])->toBe(0)
            ->and(CatalogService::getProduct($id)['qtyOnHand'])->toBe(7_000)
            ->and(Shop::balance('1001'))->toBe(2_400)
            ->and(Shop::balance(ChartOfAccounts::SALES_REVENUE))->toBe(2_400)
            ->and(Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD))->toBe(1_500)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(3_500);
        Shop::assertBooksHealthy('cash sale');
    });

    it('gives change without recording it as revenue', function () {
        $id = ($this->make)('Bread', 800, 1_200);
        ($this->addStock)($id, 10, 8_000);

        $sale = ($this->sale)(['items' => [['productId' => $id, 'qty' => 1_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 2_000]]]);

        expect($sale['total'])->toBe(1_200)
            ->and($sale['change'])->toBe(800)
            ->and(Shop::balance('1001'))->toBe(1_200)
            ->and(Shop::balance(ChartOfAccounts::SALES_REVENUE))->toBe(1_200);
        Shop::assertBooksHealthy('change given');
    });

    it('writes a stock ledger row tagged as a sale', function () {
        $id = ($this->make)('Milo 400g');
        ($this->addStock)($id, 10, 5_000);
        ($this->sale)(['items' => [['productId' => $id, 'qty' => 2_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 1_600]]]);

        $rows = DB::table('stock_ledger')->where('product_id', $id)->orderBy('id')->get();
        expect($rows->pluck('movement_type')->all())->toBe(['OPENING_STOCK', 'SALE'])
            ->and((int) $rows[1]->qty_out_milli)->toBe(2_000)
            ->and((int) $rows[1]->total_cost_minor)->toBe(1_000)
            ->and($rows[1]->source_ref)->toStartWith('RCP-');
    });
});

describe('MoMo and split payments', function () {
    it('records a MoMo sale against the MoMo account', function () {
        $id = ($this->make)('Milo 400g', 500, 800);
        ($this->addStock)($id, 10, 5_000);
        ($this->sale)(['items' => [['productId' => $id, 'qty' => 2_000]], 'tenders' => [['paymentAccountId' => $this->momo, 'amount' => 1_600, 'reference' => 'MM123456']]]);

        expect(Shop::balance('1011'))->toBe(1_600)->and(Shop::balance('1001'))->toBe(0);
        Shop::assertBooksHealthy('momo sale');
    });

    it('splits one sale across cash and MoMo', function () {
        $id = ($this->make)('Milo 400g', 500, 800);
        ($this->addStock)($id, 10, 5_000);
        $sale = ($this->sale)(['items' => [['productId' => $id, 'qty' => 5_000]], 'tenders' => [
            ['paymentAccountId' => $this->cash, 'amount' => 1_500],
            ['paymentAccountId' => $this->momo, 'amount' => 2_500],
        ]]);

        expect($sale['outstanding'])->toBe(0)
            ->and(Shop::balance('1001'))->toBe(1_500)
            ->and(Shop::balance('1011'))->toBe(2_500)
            ->and(DB::table('sale_payments')->where('sale_id', $sale['saleId'])->count())->toBe(2);
        Shop::assertBooksHealthy('split payment');
    });

    it('gives change from the cash, whatever order the payments were typed in', function () {
        $id = ($this->make)('Milo 400g', 500, 800);
        ($this->addStock)($id, 20, 10_000);
        // GHS 120.00 of Milo; cash 100 typed first, then MoMo 50: the GHS 30 change leaves the drawer.
        $sale = ($this->sale)(['items' => [['productId' => $id, 'qty' => 15_000]], 'tenders' => [
            ['paymentAccountId' => $this->cash, 'amount' => 10_000],
            ['paymentAccountId' => $this->momo, 'amount' => 5_000],
        ]]);

        expect($sale['change'])->toBe(3_000)
            ->and(Shop::balance('1001'))->toBe(7_000)
            ->and(Shop::balance('1011'))->toBe(5_000);
        Shop::assertBooksHealthy('change from cash');
    });

    it('keeps the change handed back on the sale for the receipt, and an edit replaces it', function () {
        $id = ($this->make)('Milo 400g', 500, 800);
        ($this->addStock)($id, 10, 5_000);
        $sale = ($this->sale)(['items' => [['productId' => $id, 'qty' => 2_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 2_000]]]);
        $change = fn (): int => (int) DB::table('sales')->where('id', $sale['saleId'])->value('change_minor');
        expect($change())->toBe(400);

        SaleService::editSale($sale['saleId'], ['businessDate' => SALES_TODAY, 'items' => [['productId' => $id, 'qty' => 2_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 5_000]]], 'Customer gave a 50', $this->actor);
        expect($change())->toBe(3_400);
    });

    it('refuses change on a sale paid only by MoMo, since nobody can give change from a transfer', function () {
        $id = ($this->make)('Milo 400g', 500, 800);
        ($this->addStock)($id, 10, 5_000);

        expect(fn () => ($this->sale)(['items' => [['productId' => $id, 'qty' => 5_000]], 'tenders' => [['paymentAccountId' => $this->momo, 'amount' => 5_000]]]))
            ->toThrow(ValidationError::class, 'Change can only be given from cash')
            ->and(DB::table('sales')->count())->toBe(0);
    });
});

describe('discounts', function () {
    it('records a discount as contra-revenue, keeping gross sales visible', function () {
        $id = ($this->make)('Milo 400g', 500, 1_000);
        ($this->addStock)($id, 10, 5_000);
        $sale = ($this->sale)([
            'items' => [['productId' => $id, 'qty' => 5_000]],
            'invoiceDiscount' => 500,
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 4_500]],
            'allowPriceOverride' => true,
        ]);

        expect($sale['total'])->toBe(4_500)
            ->and(Shop::balance(ChartOfAccounts::SALES_REVENUE))->toBe(5_000)
            ->and(Shop::balance(ChartOfAccounts::SALES_DISCOUNTS))->toBe(500)
            ->and(Shop::balance('1001'))->toBe(4_500);
        Shop::assertBooksHealthy('discount');
    });
});

describe('credit sales', function () {
    it('handles the brief’s worked example: sell 500, pay 200, owe 300', function () {
        $customerId = Shop::customer($this->actor, 'Ama Serwaa');
        $id = ($this->make)('Rice', 1_400, 1_900);
        ($this->addStock)($id, 100, 140_000);

        $sale = ($this->sale)([
            'customerId' => $customerId,
            'items' => [['productId' => $id, 'qty' => 1_000, 'unitPrice' => 50_000]],
            'tenders' => [['paymentAccountId' => $this->momo, 'amount' => 20_000]],
            'allowPriceOverride' => true,
        ]);

        expect($sale['total'])->toBe(50_000)
            ->and($sale['outstanding'])->toBe(30_000)
            ->and(CustomerService::getCustomerBalance($customerId))->toBe(30_000)
            ->and(Shop::balance(ChartOfAccounts::ACCOUNTS_RECEIVABLE))->toBe(30_000)
            ->and(Shop::balance('1011'))->toBe(20_000);
        Shop::assertBooksHealthy('credit sale');

        ($this->pay)($customerId, 30_000);

        expect(CustomerService::getCustomerBalance($customerId))->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::ACCOUNTS_RECEIVABLE))->toBe(0)
            ->and(Shop::balance('1001'))->toBe(30_000);
        Shop::assertBooksHealthy('after settlement');
    });

    it('refuses an unpaid sale with no customer to bill', function () {
        $id = ($this->make)('Milo 400g');
        ($this->addStock)($id, 10, 5_000);

        expect(fn () => ($this->sale)(['items' => [['productId' => $id, 'qty' => 1_000]], 'tenders' => []]))
            ->toThrow(ValidationError::class, 'Choose a customer');
    });

    it('enforces the credit limit', function () {
        $customerId = Shop::customer($this->actor, 'Kofi', 10_000);
        $id = ($this->make)('Rice', 1_400, 1_900);
        ($this->addStock)($id, 100, 140_000);

        expect(fn () => ($this->sale)([
            'customerId' => $customerId,
            'items' => [['productId' => $id, 'qty' => 1_000, 'unitPrice' => 15_000]],
            'tenders' => [],
            'allowPriceOverride' => true,
        ]))->toThrow(ValidationError::class, 'credit limit');

        expect(CatalogService::getProduct($id)['qtyOnHand'])->toBe(100_000);
        Shop::assertBooksHealthy('credit limit refused');
    });

    it('treats a zero credit limit as no credit at all', function () {
        $customerId = Shop::customer($this->actor, 'Yaw', 0);
        $id = ($this->make)('Milo 400g');
        ($this->addStock)($id, 10, 5_000);

        expect(fn () => ($this->sale)(['customerId' => $customerId, 'items' => [['productId' => $id, 'qty' => 1_000]], 'tenders' => []]))
            ->toThrow(ValidationError::class, 'credit limit');
    });

    it('allows unlimited credit when no limit is set', function () {
        $customerId = Shop::customer($this->actor, 'Trusted');
        $id = ($this->make)('Rice', 1_400, 1_900);
        ($this->addStock)($id, 1_000, 1_400_000);

        ($this->sale)(['customerId' => $customerId, 'items' => [['productId' => $id, 'qty' => 500_000]], 'tenders' => []]);

        expect(CustomerService::getCustomerBalance($customerId))->toBeGreaterThan(0);
        Shop::assertBooksHealthy('unlimited credit');
    });

    it('refuses a payment larger than what is owed', function () {
        $customerId = Shop::customer($this->actor, 'Ama');
        $id = ($this->make)('Milo 400g', 500, 800);
        ($this->addStock)($id, 10, 5_000);
        ($this->sale)(['customerId' => $customerId, 'items' => [['productId' => $id, 'qty' => 1_000]], 'tenders' => []]);

        expect(fn () => ($this->pay)($customerId, 999_999))->toThrow(ValidationError::class, 'cannot receive more');
    });

    it('allocates a partial payment to the oldest sale first', function () {
        $customerId = Shop::customer($this->actor, 'Ama');
        $id = ($this->make)('Milo 400g', 500, 1_000);
        ($this->addStock)($id, 100, 50_000);
        SaleService::createSale(['businessDate' => '2026-08-01', 'customerId' => $customerId, 'items' => [['productId' => $id, 'qty' => 2_000]], 'tenders' => []], $this->actor);
        SaleService::createSale(['businessDate' => '2026-08-10', 'customerId' => $customerId, 'items' => [['productId' => $id, 'qty' => 3_000]], 'tenders' => []], $this->actor);

        expect(CustomerService::getCustomerBalance($customerId))->toBe(5_000);

        ($this->pay)($customerId, 2_500);
        $open = CustomerPaymentService::getOpenSales($customerId);

        expect($open)->toHaveCount(1)
            ->and($open[0]['outstandingMinor'])->toBe(2_500)
            ->and(CustomerService::getCustomerBalance($customerId))->toBe(2_500);
        Shop::assertBooksHealthy('partial allocation');
    });

    it('refuses to archive a customer who still owes money', function () {
        $customerId = Shop::customer($this->actor, 'Ama');
        $id = ($this->make)('Milo 400g', 500, 800);
        ($this->addStock)($id, 10, 5_000);
        ($this->sale)(['customerId' => $customerId, 'items' => [['productId' => $id, 'qty' => 1_000]], 'tenders' => []]);

        expect(fn () => CustomerService::setCustomerActive($customerId, false, $this->actor))->toThrow(ConflictError::class);
    });
});

describe('stock rules', function () {
    it('refuses to sell more than is on the shelf', function () {
        $id = ($this->make)('Milo 400g');
        ($this->addStock)($id, 2, 1_000);

        expect(fn () => ($this->sale)(['items' => [['productId' => $id, 'qty' => 3_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 2_400]]]))
            ->toThrow(InsufficientStockError::class);

        expect(DB::table('sales')->count())->toBe(0)
            ->and(CatalogService::getProduct($id)['qtyOnHand'])->toBe(2_000);
        Shop::assertBooksHealthy('insufficient stock');
    });

    it('allows overselling once the shop enables negative stock', function () {
        $id = ($this->make)('Milo 400g', 500, 800);
        ($this->addStock)($id, 2, 1_000);
        Shop::setting('allow_negative_stock', true);

        ($this->sale)(['items' => [['productId' => $id, 'qty' => 3_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 2_400]]]);

        expect(CatalogService::getProduct($id)['qtyOnHand'])->toBe(-1_000);
        Shop::assertBooksHealthy('negative stock');
    });

    it('rejects a sale with no items', function () {
        expect(fn () => ($this->sale)(['items' => [], 'tenders' => []]))->toThrow(ValidationError::class);
    });
});

describe('profit uses the snapshotted cost, not today’s price', function () {
    it('does not change historic profit when the product cost later changes', function () {
        $id = ($this->make)('Milo 400g', 500, 1_000);
        ($this->addStock)($id, 10, 5_000);
        $sale = ($this->sale)(['items' => [['productId' => $id, 'qty' => 2_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 2_000]]]);
        expect($sale['cogs'])->toBe(1_000);

        ($this->addStock)($id, 10, 20_000);
        DB::table('products')->where('id', $id)->update(['cost_price_minor' => 9_999]);

        $stored = SaleService::getSale($sale['saleId']);
        expect($stored['cogsMinor'])->toBe(1_000)
            ->and($stored['items'][0]['totalCostMinor'])->toBe(1_000)
            ->and(SaleService::getSalesSummary(SALES_TODAY, SALES_TODAY)['grossProfit'])->toBe(1_000);
    });

    it('uses the weighted average at the moment of sale', function () {
        $id = ($this->make)('Milo 400g', 500, 1_000);
        ($this->addStock)($id, 10, 5_000);
        ($this->addStock)($id, 10, 7_000);

        $sale = ($this->sale)(['items' => [['productId' => $id, 'qty' => 5_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 5_000]]]);

        expect($sale['cogs'])->toBe(3_000);
        Shop::assertBooksHealthy('weighted average cogs');
    });
});

describe('voiding a sale', function () {
    it('puts stock and money back without deleting anything', function () {
        $id = ($this->make)('Milo 400g', 500, 800);
        ($this->addStock)($id, 10, 5_000);
        $sale = ($this->sale)(['items' => [['productId' => $id, 'qty' => 3_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 2_400]]]);
        expect(CatalogService::getProduct($id)['qtyOnHand'])->toBe(7_000)->and(Shop::balance('1001'))->toBe(2_400);

        SaleService::voidSale($sale['saleId'], 'Customer changed their mind', $this->actor);

        $product = CatalogService::getProduct($id);
        expect($product['qtyOnHand'])->toBe(10_000)
            ->and($product['stockValue'])->toBe(5_000)
            ->and(Shop::balance('1001'))->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::SALES_REVENUE))->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD))->toBe(0);
        Shop::assertBooksHealthy('after void');

        $original = DB::table('sales')->where('id', $sale['saleId'])->first();
        expect($original->status)->toBe('VOIDED')
            ->and($original->void_reason)->toBe('Customer changed their mind')
            ->and((int) $original->voided_by_sale_id)->toBeGreaterThan(0)
            ->and(DB::table('sale_items')->where('sale_id', $sale['saleId'])->count())->toBe(1);
    });

    it('restores stock at the original cost even after the average moved', function () {
        $id = ($this->make)('Milo 400g', 500, 1_000);
        ($this->addStock)($id, 10, 5_000);
        $sale = ($this->sale)(['items' => [['productId' => $id, 'qty' => 2_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 2_000]]]);
        expect($sale['cogs'])->toBe(1_000);

        ($this->addStock)($id, 8, 16_000);
        $valueBefore = CatalogService::getProduct($id)['stockValue'];

        SaleService::voidSale($sale['saleId'], 'Wrong item scanned', $this->actor);

        expect(CatalogService::getProduct($id)['stockValue'])->toBe($valueBefore + 1_000);
        Shop::assertBooksHealthy('void after average moved');
    });

    it('reverses the receivable on a credit sale', function () {
        $customerId = Shop::customer($this->actor, 'Ama');
        $id = ($this->make)('Milo 400g', 500, 800);
        ($this->addStock)($id, 10, 5_000);
        $sale = ($this->sale)(['customerId' => $customerId, 'items' => [['productId' => $id, 'qty' => 2_000]], 'tenders' => []]);
        expect(CustomerService::getCustomerBalance($customerId))->toBe(1_600);

        SaleService::voidSale($sale['saleId'], 'Entered twice', $this->actor);

        expect(CustomerService::getCustomerBalance($customerId))->toBe(0);
        Shop::assertBooksHealthy('void credit sale');
    });

    it('refuses to void twice, and refuses if a payment was received', function () {
        $customerId = Shop::customer($this->actor, 'Ama');
        $id = ($this->make)('Milo 400g', 500, 800);
        ($this->addStock)($id, 10, 5_000);
        $paid = ($this->sale)(['customerId' => $customerId, 'items' => [['productId' => $id, 'qty' => 2_000]], 'tenders' => []]);
        ($this->pay)($customerId, 1_600);

        expect(fn () => SaleService::voidSale($paid['saleId'], 'Mistake', $this->actor))->toThrow(ConflictError::class, 'Void those payments first');

        $other = ($this->sale)(['items' => [['productId' => $id, 'qty' => 1_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 800]]]);
        SaleService::voidSale($other['saleId'], 'Mistake', $this->actor);
        expect(fn () => SaleService::voidSale($other['saleId'], 'Again', $this->actor))->toThrow(ConflictError::class);
    });

    it('requires a reason', function () {
        $id = ($this->make)('Milo 400g');
        ($this->addStock)($id, 10, 5_000);
        $sale = ($this->sale)(['items' => [['productId' => $id, 'qty' => 1_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 800]]]);

        expect(fn () => SaleService::voidSale($sale['saleId'], 'x', $this->actor))->toThrow(ValidationError::class);
    });
});

describe('voiding through an account archived since (third audit 6)', function () {
    it('refuses the void instead of leaving money in an account nobody can see', function () {
        $id = ($this->make)('Milo 400g', 500, 800);
        ($this->addStock)($id, 10, 5_000);
        $customer = Shop::customer($this->actor);
        ($this->sale)(['items' => [['productId' => $id, 'qty' => 1_000]], 'tenders' => [], 'customerId' => $customer]);
        $payment = CustomerPaymentService::recordCustomerPayment(['customerId' => $customer, 'businessDate' => SALES_TODAY, 'paymentAccountId' => $this->momo, 'amount' => 800], $this->actor);
        TransferService::recordTransfer(['businessDate' => SALES_TODAY, 'fromAccountId' => $this->momo, 'toAccountId' => $this->cash, 'amount' => 800], $this->actor);
        PaymentAccountService::setPaymentAccountActive($this->momo, false, $this->actor);

        expect(fn () => CustomerPaymentService::voidCustomerPayment($payment['paymentId'], 'Typed twice', $this->actor))->toThrow(ValidationError::class, 'archived since')
            ->and(Shop::balance('1011'))->toBe(0);
    });
});

describe('voiding a customer payment', function () {
    it('restores the debt and takes the money back out', function () {
        $customerId = Shop::customer($this->actor, 'Ama');
        $id = ($this->make)('Milo 400g', 500, 800);
        ($this->addStock)($id, 10, 5_000);
        ($this->sale)(['customerId' => $customerId, 'items' => [['productId' => $id, 'qty' => 2_000]], 'tenders' => []]);
        $payment = ($this->pay)($customerId, 1_000);
        expect(CustomerService::getCustomerBalance($customerId))->toBe(600);

        CustomerPaymentService::voidCustomerPayment($payment['paymentId'], 'Bounced', $this->actor);

        expect(CustomerService::getCustomerBalance($customerId))->toBe(1_600)->and(Shop::balance('1001'))->toBe(0);
        Shop::assertBooksHealthy('void payment');
    });
});

describe('reads', function () {
    it('reports outstanding and profit on the sales list', function () {
        $customerId = Shop::customer($this->actor, 'Ama');
        $id = ($this->make)('Milo 400g', 500, 1_000);
        ($this->addStock)($id, 10, 5_000);
        ($this->sale)(['customerId' => $customerId, 'items' => [['productId' => $id, 'qty' => 3_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 1_000]]]);

        $rows = SaleService::listSales();
        expect($rows)->toHaveCount(1)
            ->and($rows[0]['totalMinor'])->toBe(3_000)
            ->and($rows[0]['outstandingMinor'])->toBe(2_000)
            ->and($rows[0]['profitMinor'])->toBe(1_500)
            ->and(SaleService::listSales(['unpaidOnly' => true]))->toHaveCount(1);
    });

    it('snapshots the product name onto the receipt line', function () {
        $id = ($this->make)('Milo 400g', 500, 800);
        ($this->addStock)($id, 10, 5_000);
        $sale = ($this->sale)(['items' => [['productId' => $id, 'qty' => 1_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 800]]]);
        DB::table('products')->where('id', $id)->update(['name' => 'Milo Tin (new packaging)']);

        expect(SaleService::getSale($sale['saleId'])['items'][0]['productName'])->toBe('Milo 400g');
    });

    it('summarises a period', function () {
        $id = ($this->make)('Milo 400g', 500, 1_000);
        ($this->addStock)($id, 100, 50_000);
        SaleService::createSale(['businessDate' => '2026-08-01', 'items' => [['productId' => $id, 'qty' => 2_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 2_000]]], $this->actor);
        SaleService::createSale(['businessDate' => '2026-08-16', 'items' => [['productId' => $id, 'qty' => 3_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 3_000]]], $this->actor);

        $august = SaleService::getSalesSummary('2026-08-01', '2026-08-31');
        expect($august['count'])->toBe(2)
            ->and($august['total'])->toBe(5_000)
            ->and($august['cogs'])->toBe(2_500)
            ->and($august['grossProfit'])->toBe(2_500)
            ->and(SaleService::getSalesSummary('2026-08-16', '2026-08-16')['total'])->toBe(3_000);
    });
});

describe('fractional quantities end to end', function () {
    it('sells 2.5 kg of rice correctly', function () {
        $id = ($this->make)('Rice', 1_400, 1_900, 'kg');
        StockAdjustmentService::createStockAdjustment(['businessDate' => SALES_TODAY, 'reason' => AdjustmentReason::OpeningStock, 'items' => [
            ['productId' => $id, 'direction' => 'IN', 'qty' => Quantity::parse('25.5'), 'totalCost' => 35_700],
        ]], $this->actor);

        $sale = ($this->sale)(['items' => [['productId' => $id, 'qty' => Quantity::parse('2.5')]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 4_750]]]);

        expect($sale['total'])->toBe(4_750)
            ->and($sale['cogs'])->toBe(3_500)
            ->and(CatalogService::getProduct($id)['qtyOnHand'])->toBe(23_000);
        Shop::assertBooksHealthy('fractional sale');
    });
});
