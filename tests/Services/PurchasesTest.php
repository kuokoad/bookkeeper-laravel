<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/purchases.test.ts: deliveries, supplier
| payments, voids, supplier returns, reads and dated batches. The customer
| returns block of that file is already covered by the Phase 4 ports
| (SalesTest, RefundCapTest, CreditedReturnsTest) and is repeated here because
| it sits in the same old file and the same figures must still hold.
| Account balances come from Shop::balance, which is the real
| BalancesService::getAccountBalanceByCode.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\InsufficientStockError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\PeriodLockedError;
use App\Domain\Errors\ValidationError;
use App\Services\CatalogService;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use App\Services\SupplierPaymentService;
use App\Services\SupplierService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const PUR_TODAY = '2026-08-17';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->momo = Shop::paymentAccount('MOBILE_MONEY');
    $this->bank = Shop::paymentAccount('BANK');
    $this->buy = fn (int $supplierId, array $items, array $tenders = [], array $extra = []): array => Shop::purchase($this->actor, $supplierId, $items, $tenders, ['businessDate' => PUR_TODAY, ...$extra]);
    $this->pay = fn (int $amount): array => [['paymentAccountId' => $this->cash, 'amount' => $amount]];
    $this->sell = fn (int $productId, int $qty, int $paid): array => SaleService::createSale([
        'businessDate' => PUR_TODAY,
        'items' => [['productId' => $productId, 'qty' => $qty]],
        'tenders' => [['paymentAccountId' => $this->cash, 'amount' => $paid]],
    ], $this->actor);
});

function purBatchesOf(int $productId): array
{
    return DB::table('product_batches')->where('product_id', $productId)->orderBy('id')->get()->all();
}

describe('purchases', function () {
    it('handles the brief’s worked example: buy 1000, pay 400, owe 600', function () {
        $supplierId = Shop::supplier($this->actor, 'Kasapreko Depot');
        $id = Shop::product($this->actor, 'Milo 400g');

        $purchase = ($this->buy)($supplierId, [[$id, 100_000, 1_000]], [['paymentAccountId' => $this->bank, 'amount' => 40_000]], ['invoiceNo' => 'INV-9912']);

        expect($purchase['total'])->toBe(100_000)
            ->and($purchase['paid'])->toBe(40_000)
            ->and($purchase['outstanding'])->toBe(60_000);

        // Stock arrived at the price actually paid.
        $product = CatalogService::getProduct($id);
        expect($product['qtyOnHand'])->toBe(100_000)
            ->and($product['stockValue'])->toBe(100_000)
            ->and($product['averageCost'])->toBe(1_000);

        expect(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(100_000)
            ->and(Shop::balance('1021'))->toBe(-40_000)
            ->and(Shop::balance(ChartOfAccounts::ACCOUNTS_PAYABLE))->toBe(60_000)
            ->and(SupplierService::getSupplierBalance($supplierId))->toBe(60_000);
        Shop::assertBooksHealthy('credit purchase');

        SupplierPaymentService::recordSupplierPayment(['supplierId' => $supplierId, 'businessDate' => PUR_TODAY, 'paymentAccountId' => $this->cash, 'amount' => 60_000], $this->actor);

        expect(SupplierService::getSupplierBalance($supplierId))->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::ACCOUNTS_PAYABLE))->toBe(0)
            ->and(Shop::balance('1001'))->toBe(-60_000)
            ->and(PurchaseService::getPurchaseOutstanding($purchase['purchaseId']))->toBe(0);
        Shop::assertBooksHealthy('after supplier payment');
    });

    it('re-averages stock cost across deliveries at different prices', function () {
        $supplierId = Shop::supplier($this->actor);
        $id = Shop::product($this->actor, 'Milo 400g');
        ($this->buy)($supplierId, [[$id, 10_000, 500]], ($this->pay)(5_000));
        ($this->buy)($supplierId, [[$id, 10_000, 700]], ($this->pay)(7_000));

        $product = CatalogService::getProduct($id);
        expect($product['qtyOnHand'])->toBe(20_000)
            ->and($product['stockValue'])->toBe(12_000)
            ->and($product['averageCost'])->toBe(600);
        Shop::assertBooksHealthy('re-average');
    });

    it('spreads an invoice discount so inventory equals what was actually paid', function () {
        $supplierId = Shop::supplier($this->actor);
        $a = Shop::product($this->actor, 'Bread');
        $b = Shop::product($this->actor, 'Biscuits');

        $purchase = ($this->buy)($supplierId, [[$a, 10_000, 750], [$b, 10_000, 250]], ($this->pay)(9_000), ['invoiceDiscount' => 1_000]);

        expect($purchase['total'])->toBe(9_000)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(9_000)
            ->and(CatalogService::getProduct($a)['stockValue'])->toBe(6_750)
            ->and(CatalogService::getProduct($b)['stockValue'])->toBe(2_250);
        Shop::assertBooksHealthy('purchase discount');
    });

    it('an awkward discount still puts every pesewa into inventory', function () {
        $supplierId = Shop::supplier($this->actor);
        $a = Shop::product($this->actor, 'A');
        $b = Shop::product($this->actor, 'B');
        $c = Shop::product($this->actor, 'C');

        foreach ([1, 7, 33, 99, 101, 1_234] as $discount) {
            $purchase = ($this->buy)($supplierId, [[$a, 3_000, 1_111], [$b, 3_000, 2_222], [$c, 3_000, 3_333]], [], ['invoiceDiscount' => $discount]);

            expect(Shop::balance('6900'))->toBe(0, "discount {$discount} leaked to Miscellaneous")
                ->and($purchase['total'])->toBe(19_998 - $discount);
            Shop::assertBooksHealthy("purchase discount {$discount}");
        }
    });

    it('writes a stock ledger row tagged as a purchase', function () {
        $supplierId = Shop::supplier($this->actor);
        $id = Shop::product($this->actor, 'Milo 400g');
        ($this->buy)($supplierId, [[$id, 10_000, 500]], ($this->pay)(5_000));

        $rows = DB::table('stock_ledger')->where('product_id', $id)->get();
        expect($rows)->toHaveCount(1)
            ->and($rows[0]->movement_type)->toBe('PURCHASE')
            ->and((int) $rows[0]->qty_in_milli)->toBe(10_000)
            ->and($rows[0]->source_ref)->toMatch('/^PUR-/');
    });

    it('refuses to pay more than the purchase total, or more than is owed', function () {
        $supplierId = Shop::supplier($this->actor);
        $id = Shop::product($this->actor, 'Milo 400g');

        expect(fn () => ($this->buy)($supplierId, [[$id, 1_000, 500]], ($this->pay)(9_999)))->toThrow(ValidationError::class, 'cannot pay more');

        ($this->buy)($supplierId, [[$id, 1_000, 500]]);

        expect(fn () => SupplierPaymentService::recordSupplierPayment(['supplierId' => $supplierId, 'businessDate' => PUR_TODAY, 'paymentAccountId' => $this->cash, 'amount' => 99_999], $this->actor))
            ->toThrow(ValidationError::class, 'cannot pay more');
    });

    it('rejects a purchase with no items', function () {
        $supplierId = Shop::supplier($this->actor);
        expect(fn () => ($this->buy)($supplierId, []))->toThrow(ValidationError::class);
    });

    it('leaves nothing behind when a line fails', function () {
        $supplierId = Shop::supplier($this->actor);
        $good = Shop::product($this->actor, 'Bread');

        expect(fn () => ($this->buy)($supplierId, [[$good, 10_000, 500], [999_999, 1_000, 100]]))->toThrow(NotFoundError::class);

        expect(DB::table('purchases')->count())->toBe(0)
            ->and(CatalogService::getProduct($good)['qtyOnHand'])->toBe(0);
        Shop::assertBooksHealthy('purchase rollback');
    });

    it('allocates a supplier payment to the oldest purchase first', function () {
        $supplierId = Shop::supplier($this->actor);
        $id = Shop::product($this->actor, 'Milo 400g');
        ($this->buy)($supplierId, [[$id, 10_000, 500]], [], ['businessDate' => '2026-08-01']);
        ($this->buy)($supplierId, [[$id, 10_000, 700]], [], ['businessDate' => '2026-08-10']);

        expect(SupplierService::getSupplierBalance($supplierId))->toBe(12_000);

        SupplierPaymentService::recordSupplierPayment(['supplierId' => $supplierId, 'businessDate' => PUR_TODAY, 'paymentAccountId' => $this->cash, 'amount' => 6_000], $this->actor);

        $open = PurchaseService::getOpenPurchases($supplierId);
        expect($open)->toHaveCount(1)
            ->and($open[0]['outstandingMinor'])->toBe(6_000);
        Shop::assertBooksHealthy('supplier allocation');
    });

    it('refuses to archive a supplier who is still owed money', function () {
        $supplierId = Shop::supplier($this->actor);
        $id = Shop::product($this->actor, 'Milo 400g');
        ($this->buy)($supplierId, [[$id, 1_000, 500]]);

        expect(fn () => SupplierService::setSupplierActive($supplierId, false, $this->actor))->toThrow(ConflictError::class);
    });
});

describe('voiding a purchase', function () {
    it('takes the stock back out and reverses the payable', function () {
        $supplierId = Shop::supplier($this->actor);
        $id = Shop::product($this->actor, 'Milo 400g');
        $purchase = ($this->buy)($supplierId, [[$id, 10_000, 500]], ($this->pay)(2_000));

        expect(CatalogService::getProduct($id)['qtyOnHand'])->toBe(10_000)
            ->and(SupplierService::getSupplierBalance($supplierId))->toBe(3_000);

        PurchaseService::voidPurchase($purchase['purchaseId'], 'Delivered to the wrong shop', $this->actor);

        expect(CatalogService::getProduct($id)['qtyOnHand'])->toBe(0)
            ->and(CatalogService::getProduct($id)['stockValue'])->toBe(0)
            ->and(SupplierService::getSupplierBalance($supplierId))->toBe(0)
            ->and(Shop::balance('1001'))->toBe(0);
        Shop::assertBooksHealthy('void purchase');

        $original = DB::table('purchases')->where('id', $purchase['purchaseId'])->first();
        expect($original->status)->toBe('VOIDED')
            ->and($original->void_reason)->toBe('Delivered to the wrong shop');
    });

    it('refuses to void a purchase that has been paid down', function () {
        $supplierId = Shop::supplier($this->actor);
        $id = Shop::product($this->actor, 'Milo 400g');
        $purchase = ($this->buy)($supplierId, [[$id, 10_000, 500]]);
        SupplierPaymentService::recordSupplierPayment(['supplierId' => $supplierId, 'businessDate' => PUR_TODAY, 'paymentAccountId' => $this->cash, 'amount' => 5_000], $this->actor);

        expect(fn () => PurchaseService::voidPurchase($purchase['purchaseId'], 'Mistake', $this->actor))->toThrow(ConflictError::class, 'Void those payments first');
    });

    it('void of a supplier payment restores the debt', function () {
        $supplierId = Shop::supplier($this->actor);
        $id = Shop::product($this->actor, 'Milo 400g');
        ($this->buy)($supplierId, [[$id, 10_000, 500]]);
        $payment = SupplierPaymentService::recordSupplierPayment(['supplierId' => $supplierId, 'businessDate' => PUR_TODAY, 'paymentAccountId' => $this->cash, 'amount' => 3_000], $this->actor);
        expect(SupplierService::getSupplierBalance($supplierId))->toBe(2_000);

        SupplierPaymentService::voidSupplierPayment($payment['paymentId'], 'Cheque bounced', $this->actor);

        expect(SupplierService::getSupplierBalance($supplierId))->toBe(5_000)
            ->and(Shop::balance('1001'))->toBe(0);
        Shop::assertBooksHealthy('void supplier payment');
    });
});

describe('a delivery that came free', function () {
    beforeEach(function () {
        $this->freeDelivery = fn (int $productId, string $businessDate = PUR_TODAY): array => ($this->buy)(Shop::supplier($this->actor), [[$productId, 10_000, 0]], [], ['businessDate' => $businessDate]);
        $this->entriesFor = fn (int $purchaseId): int => DB::table('journal_entries')->where('source_type', 'PURCHASE')->where('source_id', $purchaseId)->count();
    });

    it('brings the stock in at no cost, posts nothing, and pulls the average cost down', function () {
        $id = Shop::product($this->actor, 'Milo 400g');
        ($this->buy)(Shop::supplier($this->actor, 'Kasapreko Depot'), [[$id, 10_000, 500]]);

        $free = ($this->freeDelivery)($id);

        expect($free['total'])->toBe(0)
            ->and($free['journalEntryId'])->toBeNull()
            ->and(($this->entriesFor)($free['purchaseId']))->toBe(0)
            ->and(CatalogService::getProduct($id)['qtyOnHand'])->toBe(20_000)
            ->and(CatalogService::getProduct($id)['stockValue'])->toBe(5_000);
        Shop::assertBooksHealthy('free delivery');
    });

    it('can be voided: the stock goes back out, and there is still nothing to post', function () {
        $id = Shop::product($this->actor, 'Milo 400g');
        $free = ($this->freeDelivery)($id);

        $voided = PurchaseService::voidPurchase($free['purchaseId'], 'Samples went to the wrong shop', $this->actor);

        expect(CatalogService::getProduct($id)['qtyOnHand'])->toBe(0)
            ->and(CatalogService::getProduct($id)['stockValue'])->toBe(0)
            ->and(($this->entriesFor)($voided['reversalPurchaseId']))->toBe(0)
            ->and(DB::table('purchases')->where('id', $free['purchaseId'])->value('status'))->toBe('VOIDED');
        Shop::assertBooksHealthy('free delivery voided');
    });

    it('still keeps to the books lock, though no entry is posted to check it', function () {
        $cartage = Shop::product($this->actor, 'Cartage to site', 5_000, 0, ['trackInventory' => false]);
        Shop::setting('books_locked_before', '2026-08-31');

        expect(fn () => ($this->freeDelivery)($cartage, PUR_TODAY))->toThrow(PeriodLockedError::class, 'Period locked');
    });
});

describe('customer returns', function () {
    beforeEach(function () {
        $this->setUpSale = function (array $tenders): array {
            $customerId = Shop::customer($this->actor, 'Ama');
            $id = Shop::product($this->actor, 'Milo 400g', 500, 1_000);
            Shop::openingStock($this->actor, $id, 20_000, 10_000, PUR_TODAY);
            $sale = SaleService::createSale(['businessDate' => PUR_TODAY, 'customerId' => $customerId, 'items' => [['productId' => $id, 'qty' => 5_000]], 'tenders' => $tenders], $this->actor);

            return ['customerId' => $customerId, 'productId' => $id, 'sale' => $sale];
        };
    });

    it('returns part of a sale, restoring stock at the original cost', function () {
        ['productId' => $productId, 'sale' => $sale] = ($this->setUpSale)(($this->pay)(5_000));

        expect(CatalogService::getProduct($productId)['qtyOnHand'])->toBe(15_000);
        $items = ReturnsService::getReturnableSaleItems($sale['saleId']);
        expect($items)->toHaveCount(1)->and($items[0]['returnableMilli'])->toBe(5_000);

        $result = ReturnsService::createCustomerReturn($sale['saleId'], [
            'businessDate' => PUR_TODAY,
            'items' => [['itemId' => $items[0]['id'], 'qty' => 2_000]],
            'refunds' => ($this->pay)(2_000),
            'reason' => 'Damaged tin',
        ], $this->actor);

        expect($result['refunded'])->toBe(2_000);
        $product = CatalogService::getProduct($productId);
        expect($product['qtyOnHand'])->toBe(17_000)
            ->and($product['stockValue'])->toBe(8_500)
            ->and(Shop::balance(ChartOfAccounts::SALES_REVENUE))->toBe(5_000)
            ->and(Shop::balance(ChartOfAccounts::SALES_RETURNS))->toBe(2_000)
            ->and(Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD))->toBe(1_500)
            ->and(Shop::balance('1001'))->toBe(3_000);
        Shop::assertBooksHealthy('customer return');
    });

    it('will not let the same goods be returned twice', function () {
        ['sale' => $sale] = ($this->setUpSale)(($this->pay)(5_000));
        $items = ReturnsService::getReturnableSaleItems($sale['saleId']);
        ReturnsService::createCustomerReturn($sale['saleId'], ['businessDate' => PUR_TODAY, 'items' => [['itemId' => $items[0]['id'], 'qty' => 3_000]], 'refunds' => ($this->pay)(3_000)], $this->actor);

        expect(ReturnsService::getReturnableSaleItems($sale['saleId'])[0]['returnableMilli'])->toBe(2_000);
        expect(fn () => ReturnsService::createCustomerReturn($sale['saleId'], ['businessDate' => PUR_TODAY, 'items' => [['itemId' => $items[0]['id'], 'qty' => 3_000]]], $this->actor))
            ->toThrow(ValidationError::class, 'can still be returned');
    });

    it('credits the customer’s account when no cash is refunded', function () {
        ['customerId' => $customerId, 'sale' => $sale] = ($this->setUpSale)([]);
        expect(CustomerService::getCustomerBalance($customerId))->toBe(5_000);

        $items = ReturnsService::getReturnableSaleItems($sale['saleId']);
        $result = ReturnsService::createCustomerReturn($sale['saleId'], ['businessDate' => PUR_TODAY, 'items' => [['itemId' => $items[0]['id'], 'qty' => 2_000]]], $this->actor);

        expect($result['refunded'])->toBe(0)
            ->and($result['creditApplied'])->toBe(2_000)
            ->and(CustomerService::getCustomerBalance($customerId))->toBe(3_000);
        Shop::assertBooksHealthy('return credited');
    });

    it('refuses to refund more than the goods are worth', function () {
        ['sale' => $sale] = ($this->setUpSale)(($this->pay)(5_000));
        $items = ReturnsService::getReturnableSaleItems($sale['saleId']);

        expect(fn () => ReturnsService::createCustomerReturn($sale['saleId'], ['businessDate' => PUR_TODAY, 'items' => [['itemId' => $items[0]['id'], 'qty' => 1_000]], 'refunds' => ($this->pay)(99_999)], $this->actor))
            ->toThrow(ValidationError::class, 'can be refunded in cash');
    });

    it('keeps the original sale untouched', function () {
        ['sale' => $sale] = ($this->setUpSale)(($this->pay)(5_000));
        $items = ReturnsService::getReturnableSaleItems($sale['saleId']);
        ReturnsService::createCustomerReturn($sale['saleId'], ['businessDate' => PUR_TODAY, 'items' => [['itemId' => $items[0]['id'], 'qty' => 2_000]], 'refunds' => ($this->pay)(2_000)], $this->actor);

        $original = DB::table('sale_items')->where('id', $items[0]['id'])->first();
        expect((int) $original->qty_milli)->toBe(5_000)
            ->and((int) $original->line_total_minor)->toBe(5_000)
            ->and((int) $original->returned_qty_milli)->toBe(2_000)
            ->and((int) DB::table('sales')->where('kind', 'RETURN')->value('returns_sale_id'))->toBe($sale['saleId']);
    });
});

describe('supplier returns', function () {
    it('sends goods back at the price the supplier charged, not the blended average', function () {
        $supplierId = Shop::supplier($this->actor);
        $id = Shop::product($this->actor, 'Milo 400g');
        $cheap = ($this->buy)($supplierId, [[$id, 10_000, 500]]);
        ($this->buy)($supplierId, [[$id, 10_000, 700]]);
        expect(CatalogService::getProduct($id)['averageCost'])->toBe(600);

        $items = ReturnsService::getReturnablePurchaseItems($cheap['purchaseId']);
        ReturnsService::createSupplierReturn($cheap['purchaseId'], ['businessDate' => PUR_TODAY, 'items' => [['itemId' => $items[0]['id'], 'qty' => 5_000]]], $this->actor);

        $product = CatalogService::getProduct($id);
        expect($product['qtyOnHand'])->toBe(15_000)
            ->and($product['stockValue'])->toBe(9_500)
            ->and($product['averageCost'])->toBe(633)
            ->and(SupplierService::getSupplierBalance($supplierId))->toBe(9_500);
        Shop::assertBooksHealthy('supplier return');
    });

    it('refunds to a payment account when the supplier gives money back', function () {
        $supplierId = Shop::supplier($this->actor);
        $id = Shop::product($this->actor, 'Milo 400g');
        $purchase = ($this->buy)($supplierId, [[$id, 10_000, 500]], [['paymentAccountId' => $this->momo, 'amount' => 5_000]]);
        expect(Shop::balance('1011'))->toBe(-5_000);

        $items = ReturnsService::getReturnablePurchaseItems($purchase['purchaseId']);
        $result = ReturnsService::createSupplierReturn($purchase['purchaseId'], [
            'businessDate' => PUR_TODAY,
            'items' => [['itemId' => $items[0]['id'], 'qty' => 4_000]],
            'refunds' => [['paymentAccountId' => $this->momo, 'amount' => 2_000]],
        ], $this->actor);

        expect($result['refunded'])->toBe(2_000)
            ->and(Shop::balance('1011'))->toBe(-3_000)
            ->and(CatalogService::getProduct($id)['qtyOnHand'])->toBe(6_000);
        Shop::assertBooksHealthy('supplier refund');
    });

    it('will not return more than was delivered', function () {
        $supplierId = Shop::supplier($this->actor);
        $id = Shop::product($this->actor, 'Milo 400g');
        $purchase = ($this->buy)($supplierId, [[$id, 10_000, 500]]);
        $items = ReturnsService::getReturnablePurchaseItems($purchase['purchaseId']);

        expect(fn () => ReturnsService::createSupplierReturn($purchase['purchaseId'], ['businessDate' => PUR_TODAY, 'items' => [['itemId' => $items[0]['id'], 'qty' => 11_000]]], $this->actor))
            ->toThrow(ValidationError::class, 'can still be returned');
    });

    it('refuses the same line twice in one return', function () {
        // A guard the old app lacked: two requests for one line each passed the check.
        $supplierId = Shop::supplier($this->actor);
        $id = Shop::product($this->actor, 'Milo 400g');
        $purchase = ($this->buy)($supplierId, [[$id, 6_000, 500]]);
        $items = ReturnsService::getReturnablePurchaseItems($purchase['purchaseId']);

        expect(fn () => ReturnsService::createSupplierReturn($purchase['purchaseId'], ['businessDate' => PUR_TODAY, 'items' => [['itemId' => $items[0]['id'], 'qty' => 5_000], ['itemId' => $items[0]['id'], 'qty' => 5_000]]], $this->actor))
            ->toThrow(ValidationError::class, 'Each item can appear only once in a return.');
        expect(CatalogService::getProduct($id)['qtyOnHand'])->toBe(6_000);
    });

    it('refuses to return stock that has already been sold on', function () {
        $supplierId = Shop::supplier($this->actor);
        $id = Shop::product($this->actor, 'Milo 400g', 500, 1_000);
        $purchase = ($this->buy)($supplierId, [[$id, 10_000, 500]]);
        ($this->sell)($id, 9_000, 9_000);

        $items = ReturnsService::getReturnablePurchaseItems($purchase['purchaseId']);
        expect(fn () => ReturnsService::createSupplierReturn($purchase['purchaseId'], ['businessDate' => PUR_TODAY, 'items' => [['itemId' => $items[0]['id'], 'qty' => 5_000]]], $this->actor))
            ->toThrow(InsufficientStockError::class);
    });
});

describe('reads', function () {
    it('reports outstanding on the purchase list', function () {
        $supplierId = Shop::supplier($this->actor);
        $id = Shop::product($this->actor, 'Milo 400g');
        ($this->buy)($supplierId, [[$id, 10_000, 500]], ($this->pay)(2_000));

        $rows = PurchaseService::listPurchases();
        expect($rows)->toHaveCount(1)
            ->and((int) $rows[0]['totalMinor'])->toBe(5_000)
            ->and($rows[0]['outstandingMinor'])->toBe(3_000)
            ->and(PurchaseService::listPurchases(['unpaidOnly' => true]))->toHaveCount(1);
    });

    it('snapshots the product name onto the purchase line', function () {
        $supplierId = Shop::supplier($this->actor);
        $id = Shop::product($this->actor, 'Milo 400g');
        $purchase = ($this->buy)($supplierId, [[$id, 1_000, 500]], ($this->pay)(500));

        DB::table('products')->where('id', $id)->update(['name' => 'Renamed']);
        expect(PurchaseService::getPurchase($purchase['purchaseId'])['items'][0]['productName'])->toBe('Milo 400g');
    });

    it('links a return document to its original purchase', function () {
        $supplierId = Shop::supplier($this->actor);
        $id = Shop::product($this->actor, 'Milo 400g');
        $purchase = ($this->buy)($supplierId, [[$id, 10_000, 500]]);
        $items = ReturnsService::getReturnablePurchaseItems($purchase['purchaseId']);
        ReturnsService::createSupplierReturn($purchase['purchaseId'], ['businessDate' => PUR_TODAY, 'items' => [['itemId' => $items[0]['id'], 'qty' => 2_000]]], $this->actor);

        expect((int) DB::table('purchases')->where('kind', 'RETURN')->value('returns_purchase_id'))->toBe($purchase['purchaseId']);
        $line = DB::table('purchase_items')->where('id', $items[0]['id'])->first();
        expect((int) $line->returned_qty_milli)->toBe(2_000)
            ->and((int) $line->qty_milli)->toBe(10_000);
    });
});

describe('a delivery that carries a date', function () {
    beforeEach(function () {
        $this->expectBatchesIntact = function (string $label): void {
            foreach (InventoryService::verifyBatchCoverage() as $row) {
                expect($row['ok'])->toBeTrue("{$label}: product {$row['productId']}");
            }
            foreach (DB::table('products')->pluck('id') as $productId) {
                foreach (InventoryService::verifyProductBatches((int) $productId) as $check) {
                    expect($check['ok'])->toBeTrue("{$label}: batch {$check['batchId']}");
                }
            }
        };
        $this->dated = fn (int $productId, int $qty, int $cost, ?string $expiry): array => array_filter(
            ['productId' => $productId, 'qty' => $qty, 'unitCost' => $cost, 'expiryDate' => $expiry],
            fn ($v): bool => $v !== null,
        );
    });

    it('opens a batch carrying that date', function () {
        $supplierId = Shop::supplier($this->actor);
        $milk = Shop::product($this->actor, 'Evaporated Milk');
        ($this->buy)($supplierId, [($this->dated)($milk, 24_000, 300, '2027-03-31')], ($this->pay)(7_200));

        [$batch] = purBatchesOf($milk);
        expect($batch->expiry_date)->toBe('2027-03-31')
            ->and((int) $batch->qty_milli)->toBe(24_000)
            ->and((int) $batch->supplier_id)->toBe($supplierId)
            ->and($batch->source_type)->toBe('PURCHASE')
            ->and($batch->batch_ref)->toMatch('/^BAT-\d{5}$/');
        ($this->expectBatchesIntact)('dated delivery');
    });

    it('lands in the undated batch when no date is given', function () {
        $supplierId = Shop::supplier($this->actor);
        $rice = Shop::product($this->actor, 'Rice 5kg');
        ($this->buy)($supplierId, [[$rice, 10_000, 1_000]], ($this->pay)(10_000));

        [$batch] = purBatchesOf($rice);
        expect($batch->expiry_date)->toBeNull()
            ->and((int) $batch->qty_milli)->toBe(10_000);
        ($this->expectBatchesIntact)('undated delivery');
    });

    it('keeps two dates of the same product apart', function () {
        $supplierId = Shop::supplier($this->actor);
        $milk = Shop::product($this->actor, 'Evaporated Milk');
        ($this->buy)($supplierId, [($this->dated)($milk, 12_000, 300, '2026-11-30'), ($this->dated)($milk, 12_000, 300, '2027-03-31')], ($this->pay)(7_200));

        expect(array_map(fn (object $b): array => [$b->expiry_date, (int) $b->qty_milli], purBatchesOf($milk)))->toBe([['2026-11-30', 12_000], ['2027-03-31', 12_000]]);
        ($this->expectBatchesIntact)('two dates on one invoice');
    });

    it('sells the tighter date first, and leaves the other alone', function () {
        $supplierId = Shop::supplier($this->actor);
        $milk = Shop::product($this->actor, 'Evaporated Milk');
        ($this->buy)($supplierId, [($this->dated)($milk, 6_000, 300, '2027-03-31'), ($this->dated)($milk, 6_000, 300, '2026-11-30')], ($this->pay)(3_600));
        ($this->sell)($milk, 4_000, 3_200);

        expect(array_map(fn (object $b): array => [$b->expiry_date, (int) $b->qty_milli], purBatchesOf($milk)))->toBe([['2027-03-31', 6_000], ['2026-11-30', 2_000]]);
        ($this->expectBatchesIntact)('fefo from a real sale');
    });

    it('refuses a date that is not a date', function () {
        $supplierId = Shop::supplier($this->actor);
        $milk = Shop::product($this->actor, 'Evaporated Milk');

        expect(fn () => ($this->buy)($supplierId, [($this->dated)($milk, 6_000, 300, '31/03/2027')], ($this->pay)(1_800)))->toThrow(ValidationError::class);
        expect(purBatchesOf($milk))->toBe([])
            ->and(CatalogService::getProduct($milk)['qtyOnHand'])->toBe(0);
    });
});

describe('sending goods back to the supplier they came from', function () {
    beforeEach(function () {
        $this->dated = fn (int $productId, int $cost, string $expiry): array => ['productId' => $productId, 'qty' => 6_000, 'unitCost' => $cost, 'expiryDate' => $expiry];
    });

    it('empties the batch the voided delivery opened, not the oldest one', function () {
        $supplierId = Shop::supplier($this->actor);
        $milk = Shop::product($this->actor, 'Evaporated Milk');
        ($this->buy)($supplierId, [($this->dated)($milk, 300, '2026-10-31')], ($this->pay)(1_800));
        $later = ($this->buy)($supplierId, [($this->dated)($milk, 300, '2027-03-31')], ($this->pay)(1_800));

        PurchaseService::voidPurchase($later['purchaseId'], 'Delivered to the wrong shop', $this->actor);

        expect(array_map(fn (object $b): array => [$b->expiry_date, (int) $b->qty_milli, (bool) $b->is_closed], purBatchesOf($milk)))
            ->toBe([['2026-10-31', 6_000, false], ['2027-03-31', 0, true]]);
        Shop::assertBooksHealthy('void of a dated delivery');
    });

    it('draws a return from that supplier batch even when an older one exists', function () {
        $supplierId = Shop::supplier($this->actor);
        $milk = Shop::product($this->actor, 'Evaporated Milk');
        ($this->buy)($supplierId, [($this->dated)($milk, 300, '2026-10-31')], ($this->pay)(1_800));
        $second = ($this->buy)($supplierId, [($this->dated)($milk, 400, '2027-03-31')], ($this->pay)(2_400));

        $items = ReturnsService::getReturnablePurchaseItems($second['purchaseId']);
        ReturnsService::createSupplierReturn($second['purchaseId'], ['businessDate' => PUR_TODAY, 'items' => [['itemId' => $items[0]['id'], 'qty' => 4_000]]], $this->actor);

        expect(array_map(fn (object $b): array => [$b->expiry_date, (int) $b->qty_milli], purBatchesOf($milk)))->toBe([['2026-10-31', 6_000], ['2027-03-31', 2_000]]);
        Shop::assertBooksHealthy('supplier return from a dated batch');
    });

    it('falls back to picking when their crate has already been sold', function () {
        $supplierId = Shop::supplier($this->actor);
        $milk = Shop::product($this->actor, 'Evaporated Milk');
        $theirs = ($this->buy)($supplierId, [($this->dated)($milk, 300, '2026-10-31')], ($this->pay)(1_800));
        ($this->buy)($supplierId, [($this->dated)($milk, 300, '2027-03-31')], ($this->pay)(1_800));
        ($this->sell)($milk, 6_000, 4_800);

        $items = ReturnsService::getReturnablePurchaseItems($theirs['purchaseId']);
        ReturnsService::createSupplierReturn($theirs['purchaseId'], ['businessDate' => PUR_TODAY, 'items' => [['itemId' => $items[0]['id'], 'qty' => 3_000]]], $this->actor);

        expect(array_map(fn (object $b): array => [$b->expiry_date, (int) $b->qty_milli], purBatchesOf($milk)))->toBe([['2026-10-31', 0], ['2027-03-31', 3_000]]);
        Shop::assertBooksHealthy('return after their stock was sold');
    });

    it('still voids a delivery made before batches existed', function () {
        $supplierId = Shop::supplier($this->actor);
        $rice = Shop::product($this->actor, 'Rice 5kg');
        $purchase = ($this->buy)($supplierId, [[$rice, 10_000, 1_000]], ($this->pay)(10_000));

        // Erase the split, as a pre-batch delivery has none.
        DB::table('stock_ledger_batches')->delete();

        PurchaseService::voidPurchase($purchase['purchaseId'], 'Never arrived', $this->actor);

        expect(CatalogService::getProduct($rice)['qtyOnHand'])->toBe(0);
        Shop::assertBooksHealthy('void of a pre-batch delivery');
    });
});
