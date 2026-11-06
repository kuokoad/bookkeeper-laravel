<?php

declare(strict_types=1);

/*
| Owner decision D3 (PLAN.md §10, approved 2026-11-06): a payment's explicit
| allocations cannot add up to more than the payment. The old supplier side
| did not check, so paying 10.00 with 50.00 allocated settled 50.00 of a
| purchase while the payable fell by 10.00. The customer side already refused,
| with the wording the supplier side now mirrors.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ValidationError;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\SupplierPaymentService;
use App\Services\SupplierService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const ACAP_DAY = '2026-08-17';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->product = Shop::product($this->actor, 'Rice', 500, 1_000, ['unit' => 'bag']);
});

describe('a supplier payment', function () {
    beforeEach(function () {
        $this->supplier = Shop::supplier($this->actor, 'Kofi Wholesale');
        // 10 bags at 5.00 on credit: 50.00 owed.
        $this->purchase = Shop::purchase($this->actor, $this->supplier, [[$this->product, 10_000, 500]], [], ['businessDate' => ACAP_DAY])['purchaseId'];
        $this->pay = fn (int $amount, ?array $allocations = null): array => SupplierPaymentService::recordSupplierPayment([
            'supplierId' => $this->supplier, 'businessDate' => ACAP_DAY, 'paymentAccountId' => $this->cash, 'amount' => $amount,
            ...($allocations === null ? [] : ['allocations' => $allocations]),
        ], $this->actor);
    });

    it('refuses allocations that add up to more than the payment, and writes nothing', function () {
        expect(fn () => ($this->pay)(1_000, [['purchaseId' => $this->purchase, 'amount' => 5_000]]))
            ->toThrow(ValidationError::class, 'The amounts allocated to purchases exceed the payment.');

        expect(DB::table('supplier_payments')->count())->toBe(0)
            ->and(DB::table('supplier_payment_allocations')->count())->toBe(0)
            ->and(PurchaseService::getPurchaseOutstanding($this->purchase))->toBe(5_000)
            ->and(SupplierService::getSupplierBalance($this->supplier))->toBe(5_000)
            ->and(Shop::balance(ChartOfAccounts::ACCOUNTS_PAYABLE))->toBe(5_000);
    });

    it('accepts allocations that add up to exactly the payment', function () {
        ($this->pay)(5_000, [['purchaseId' => $this->purchase, 'amount' => 5_000]]);

        expect(PurchaseService::getPurchaseOutstanding($this->purchase))->toBe(0)
            ->and(SupplierService::getSupplierBalance($this->supplier))->toBe(0);
        Shop::assertBooksHealthy('exact allocation');
    });

    it('leaves the rest unallocated when allocations add up to less than the payment', function () {
        $second = Shop::purchase($this->actor, $this->supplier, [[$this->product, 10_000, 500]], [], ['businessDate' => ACAP_DAY])['purchaseId'];

        ($this->pay)(4_000, [['purchaseId' => $second, 'amount' => 1_000]]);

        expect(PurchaseService::getPurchaseOutstanding($this->purchase))->toBe(5_000)
            ->and(PurchaseService::getPurchaseOutstanding($second))->toBe(4_000)
            ->and((int) DB::table('supplier_payment_allocations')->sum('amount_minor'))->toBe(1_000)
            ->and(SupplierService::getSupplierBalance($this->supplier))->toBe(6_000);
        Shop::assertBooksHealthy('under-allocation');
    });
});

describe('a customer payment', function () {
    beforeEach(function () {
        $this->customer = Shop::customer($this->actor, 'Ama');
        Shop::openingStock($this->actor, $this->product, 100_000, 50_000, ACAP_DAY);
        // 5 bags at 10.00 on credit: 50.00 owed.
        $this->sale = fn (): int => SaleService::createSale([
            'businessDate' => ACAP_DAY, 'customerId' => $this->customer,
            'items' => [['productId' => $this->product, 'qty' => 5_000]], 'tenders' => [],
        ], $this->actor)['saleId'];
        $this->pay = fn (int $amount, ?array $allocations = null): array => CustomerPaymentService::recordCustomerPayment([
            'customerId' => $this->customer, 'businessDate' => ACAP_DAY, 'paymentAccountId' => $this->cash, 'amount' => $amount,
            ...($allocations === null ? [] : ['allocations' => $allocations]),
        ], $this->actor);
    });

    it('refuses allocations that add up to more than the payment, and writes nothing', function () {
        $sale = ($this->sale)();

        expect(fn () => ($this->pay)(1_000, [['saleId' => $sale, 'amount' => 5_000]]))
            ->toThrow(ValidationError::class, 'The amounts allocated to invoices exceed the payment.');

        expect(DB::table('customer_payments')->count())->toBe(0)
            ->and(DB::table('customer_payment_allocations')->count())->toBe(0)
            ->and(SaleService::getSaleOutstanding($sale))->toBe(5_000)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(5_000)
            ->and(Shop::balance(ChartOfAccounts::ACCOUNTS_RECEIVABLE))->toBe(5_000);
    });

    it('accepts allocations that add up to exactly the payment', function () {
        $sale = ($this->sale)();

        ($this->pay)(5_000, [['saleId' => $sale, 'amount' => 5_000]]);

        expect(SaleService::getSaleOutstanding($sale))->toBe(0)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(0);
        Shop::assertBooksHealthy('exact allocation');
    });

    it('leaves the rest unallocated when allocations add up to less than the payment', function () {
        $first = ($this->sale)();
        $second = ($this->sale)();

        ($this->pay)(4_000, [['saleId' => $second, 'amount' => 1_000]]);

        expect(SaleService::getSaleOutstanding($first))->toBe(5_000)
            ->and(SaleService::getSaleOutstanding($second))->toBe(4_000)
            ->and((int) DB::table('customer_payment_allocations')->sum('amount_minor'))->toBe(1_000)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(6_000);
        Shop::assertBooksHealthy('under-allocation');
    });
});

describe('allocations of nothing or less', function () {
    it('refuses a negative supplier allocation that would slip a large one under the cap, and writes nothing', function () {
        $supplier = Shop::supplier($this->actor, 'Kofi Wholesale');
        $p1 = Shop::purchase($this->actor, $supplier, [[$this->product, 10_000, 500]], [], ['businessDate' => ACAP_DAY])['purchaseId'];
        $p2 = Shop::purchase($this->actor, $supplier, [[$this->product, 10_000, 500]], [], ['businessDate' => ACAP_DAY])['purchaseId'];
        $pay = fn (array $allocations): array => SupplierPaymentService::recordSupplierPayment([
            'supplierId' => $supplier, 'businessDate' => ACAP_DAY, 'paymentAccountId' => $this->cash, 'amount' => 1_000, 'allocations' => $allocations,
        ], $this->actor);

        expect(fn () => $pay([['purchaseId' => $p1, 'amount' => 5_000], ['purchaseId' => $p2, 'amount' => -4_000]]))
            ->toThrow(ValidationError::class, 'Each amount allocated to a purchase must be greater than zero.');
        expect(fn () => $pay([['purchaseId' => $p1, 'amount' => 1_000], ['purchaseId' => $p2, 'amount' => 0]]))
            ->toThrow(ValidationError::class, 'Each amount allocated to a purchase must be greater than zero.');

        expect(DB::table('supplier_payments')->count())->toBe(0)
            ->and(PurchaseService::getPurchaseOutstanding($p1))->toBe(5_000)
            ->and(SupplierService::getSupplierBalance($supplier))->toBe(10_000);
    });

    it('refuses a negative customer allocation that would slip a large one under the cap, and writes nothing', function () {
        $customer = Shop::customer($this->actor, 'Ama');
        Shop::openingStock($this->actor, $this->product, 100_000, 50_000, ACAP_DAY);
        $sale = fn (): int => SaleService::createSale([
            'businessDate' => ACAP_DAY, 'customerId' => $customer, 'items' => [['productId' => $this->product, 'qty' => 5_000]], 'tenders' => [],
        ], $this->actor)['saleId'];
        $s1 = $sale();
        $s2 = $sale();
        $pay = fn (array $allocations): array => CustomerPaymentService::recordCustomerPayment([
            'customerId' => $customer, 'businessDate' => ACAP_DAY, 'paymentAccountId' => $this->cash, 'amount' => 1_000, 'allocations' => $allocations,
        ], $this->actor);

        expect(fn () => $pay([['saleId' => $s1, 'amount' => 5_000], ['saleId' => $s2, 'amount' => -4_000]]))
            ->toThrow(ValidationError::class, 'Each amount allocated to an invoice must be greater than zero.');
        expect(fn () => $pay([['saleId' => $s1, 'amount' => 1_000], ['saleId' => $s2, 'amount' => 0]]))
            ->toThrow(ValidationError::class, 'Each amount allocated to an invoice must be greater than zero.');

        expect(DB::table('customer_payments')->count())->toBe(0)
            ->and(SaleService::getSaleOutstanding($s1))->toBe(5_000)
            ->and(CustomerService::getCustomerBalance($customer))->toBe(10_000);
    });
});
