<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/refund-cap.test.ts: cash goes back only
| for money that came in. The rest of a return goes on account. Nothing is
| deferred: the supplier block arrived with the purchases in Phase 5.
*/

use App\Domain\Errors\ValidationError;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\PurchaseService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use App\Services\SupplierService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const RCAP_DAY = '2026-08-10';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->customer = Shop::customer($this->actor, 'Mensah Provisions');
    $this->supplier = Shop::supplier($this->actor, 'Kofi Traders');
    // Soap at 108.00 a carton.
    $this->soap = Shop::product($this->actor, 'Soap carton', 6_000, 10_800, ['unit' => 'carton']);
    Shop::openingStock($this->actor, $this->soap, 20_000, 120_000, '2026-08-01');

    $this->sell = fn (int $units, int $paidAtTill = 0, bool $toCustomer = true): int => SaleService::createSale([
        'businessDate' => RCAP_DAY,
        ...($toCustomer ? ['customerId' => $this->customer] : []),
        'items' => [['productId' => $this->soap, 'qty' => $units * 1000]],
        'tenders' => $paidAtTill === 0 ? [] : [['paymentAccountId' => $this->cash, 'amount' => $paidAtTill]],
    ], $this->actor)['saleId'];

    $this->bringBack = fn (int $saleId, int $units, int $refund): array => ReturnsService::createCustomerReturn($saleId, [
        'businessDate' => RCAP_DAY,
        'items' => [['itemId' => SaleService::getSale($saleId)['items'][0]['id'], 'qty' => $units * 1000]],
        'refunds' => $refund === 0 ? [] : [['paymentAccountId' => $this->cash, 'amount' => $refund]],
    ], $this->actor);

    $this->pay = fn (int $saleId, int $amount): array => CustomerPaymentService::recordCustomerPayment([
        'customerId' => $this->customer,
        'businessDate' => RCAP_DAY,
        'paymentAccountId' => $this->cash,
        'amount' => $amount,
        'allocations' => [['saleId' => $saleId, 'amount' => $amount]],
    ], $this->actor);

    // A delivery of soap at 60.00 a carton, on the supplier's account.
    $this->buy = fn (int $units, int $paid = 0): int => Shop::purchase(
        $this->actor,
        $this->supplier,
        [[$this->soap, $units * 1000, 6_000]],
        $paid === 0 ? [] : [['paymentAccountId' => $this->cash, 'amount' => $paid]],
        ['businessDate' => RCAP_DAY],
    )['purchaseId'];

    $this->sendBack = fn (int $purchaseId, int $units, int $refund): array => ReturnsService::createSupplierReturn($purchaseId, [
        'businessDate' => RCAP_DAY,
        'items' => [['itemId' => PurchaseService::getPurchase($purchaseId)['items'][0]['id'], 'qty' => $units * 1000]],
        'refunds' => $refund === 0 ? [] : [['paymentAccountId' => $this->cash, 'amount' => $refund]],
    ], $this->actor);
});

describe('a refund on a sale nobody has paid for', function () {
    it('gives no cash back', function () {
        $sale = ($this->sell)(1);

        expect(fn () => ($this->bringBack)($sale, 1, 10_800))->toThrow(ValidationError::class, 'Nothing can be refunded in cash');
        expect(CustomerService::getCustomerBalance($this->customer))->toBe(10_800);
    });

    it('clears the debt when the goods go back on account', function () {
        $sale = ($this->sell)(1);
        ($this->bringBack)($sale, 1, 0);

        expect(SaleService::getSaleOutstanding($sale))->toBe(0)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(0);
    });
});

describe('a refund on a part-paid sale', function () {
    it('gives back at most what was paid, and says how much that is', function () {
        $sale = ($this->sell)(1);
        ($this->pay)($sale, 5_000);

        expect(fn () => ($this->bringBack)($sale, 1, 5_001))->toThrow(ValidationError::class, '50.00');
        expect(($this->bringBack)($sale, 1, 5_000)['refunded'])->toBe(5_000)
            ->and(SaleService::getSaleOutstanding($sale))->toBe(0)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(0);
    });

    it('gives nothing back while what was paid only covers the goods kept', function () {
        $sale = ($this->sell)(2);
        ($this->pay)($sale, 10_800);

        expect(fn () => ($this->bringBack)($sale, 1, 100))->toThrow(ValidationError::class, 'Nothing can be refunded in cash');
        ($this->bringBack)($sale, 1, 0);
        expect(SaleService::getSaleOutstanding($sale))->toBe(0);
    });
});

describe('a refund when the money is on the account, not the sale', function () {
    it('gives back a payment that was never set against the sale', function () {
        $sale = ($this->sell)(1);
        CustomerPaymentService::recordCustomerPayment([
            'customerId' => $this->customer,
            'businessDate' => RCAP_DAY,
            'paymentAccountId' => $this->cash,
            'amount' => 10_800,
            'allocations' => [],
        ], $this->actor);

        expect(($this->bringBack)($sale, 1, 10_800)['refunded'])->toBe(10_800)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(0);
    });

    it('still refunds a paid sale in full when the customer owes on another', function () {
        ($this->sell)(1);
        $paid = ($this->sell)(1);
        ($this->pay)($paid, 10_800);

        expect(($this->bringBack)($paid, 1, 10_800)['refunded'])->toBe(10_800)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(10_800);
    });
});

describe('a refund on a sale paid in full', function () {
    it('gives back the whole value at the till', function () {
        $sale = ($this->sell)(1, 10_800, false);

        expect(($this->bringBack)($sale, 1, 10_800)['refunded'])->toBe(10_800);
    });

    it('allows the full value across separate returns, and no more', function () {
        $sale = ($this->sell)(2, 21_600);
        ($this->bringBack)($sale, 1, 10_800);

        expect(fn () => ($this->bringBack)($sale, 1, 10_801))->toThrow(ValidationError::class, '108.00');
        expect(($this->bringBack)($sale, 1, 10_800)['refunded'])->toBe(10_800)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(0);
    });
});

describe('a refund from a supplier', function () {
    it('cannot bring back cash the shop never paid', function () {
        $purchase = ($this->buy)(1);

        expect(fn () => ($this->sendBack)($purchase, 1, 6_000))->toThrow(ValidationError::class, 'Nothing can be refunded in cash');
        ($this->sendBack)($purchase, 1, 0);
        expect(PurchaseService::getPurchaseOutstanding($purchase))->toBe(0)
            ->and(SupplierService::getSupplierBalance($this->supplier))->toBe(0);
    });

    it('brings back what was paid on a paid delivery', function () {
        $purchase = ($this->buy)(2, 12_000);

        expect(($this->sendBack)($purchase, 1, 6_000)['refunded'])->toBe(6_000)
            ->and(SupplierService::getSupplierBalance($this->supplier))->toBe(0);
    });
});

it('refuses a return that names the same line twice, which could refund more than was paid', function () {
    // 6 cartons paid in cash; 5 + 5 would have brought back 10.
    $sale = ($this->sell)(6, 6 * 10_800, false);
    $line = SaleService::getSale($sale)['items'][0]['id'];

    expect(fn () => ReturnsService::createCustomerReturn($sale, [
        'businessDate' => RCAP_DAY,
        'items' => [['itemId' => $line, 'qty' => 5_000], ['itemId' => $line, 'qty' => 5_000]],
        'refunds' => [['paymentAccountId' => $this->cash, 'amount' => 10 * 10_800]],
    ], $this->actor))->toThrow(ValidationError::class, 'Each item can appear only once in a return.');
    expect((int) SaleService::getSale($sale)['items'][0]['returnedQtyMilli'])->toBe(0);
});

describe('a refund from an archived payment account', function () {
    beforeEach(function () {
        $this->archiveCash = fn () => DB::table('payment_accounts')->where('id', $this->cash)->update(['is_active' => false]);
    });

    it('is refused on a customer return, as a tender would be', function () {
        $sale = ($this->sell)(1, 10_800, false);
        ($this->archiveCash)();

        expect(fn () => ($this->bringBack)($sale, 1, 10_800))->toThrow(ValidationError::class, 'is not active');
        expect(DB::table('sales')->where('returns_sale_id', $sale)->count())->toBe(0);
    });

    it('is refused on a supplier return, as a payment would be', function () {
        $purchase = ($this->buy)(1, 6_000);
        ($this->archiveCash)();

        expect(fn () => ($this->sendBack)($purchase, 1, 6_000))->toThrow(ValidationError::class, 'is not active');
        expect(DB::table('purchases')->where('returns_purchase_id', $purchase)->count())->toBe(0);
    });
});
