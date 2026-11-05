<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/payment-void.test.ts: voiding a payment
| frees the sales or purchases it settled and keeps the record of what it
| settled. Nothing is deferred.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\SupplierPaymentService;
use App\Services\SupplierService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const PVOID_DAY = '2026-08-10';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->product = Shop::product($this->actor, 'Rice 5kg', 1_000, 2_000, ['unit' => 'bag']);
    Shop::openingStock($this->actor, $this->product, 100_000, 100_000, PVOID_DAY);
    $this->customer = Shop::customer($this->actor, 'Ama');
    $this->creditSale = fn (): array => SaleService::createSale([
        'businessDate' => PVOID_DAY, 'customerId' => $this->customer,
        'items' => [['productId' => $this->product, 'qty' => 4_000]], 'tenders' => [],
    ], $this->actor);
    $this->pay = fn (int $amount): array => CustomerPaymentService::recordCustomerPayment(
        ['customerId' => $this->customer, 'businessDate' => PVOID_DAY, 'paymentAccountId' => $this->cash, 'amount' => $amount],
        $this->actor,
    );
});

describe('voiding a customer payment', function () {
    it('puts the sale back to outstanding', function () {
        $sale = ($this->creditSale)();
        $payment = ($this->pay)(3_000);
        expect(SaleService::getSaleOutstanding($sale['saleId']))->toBe(5_000)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(5_000);

        CustomerPaymentService::voidCustomerPayment($payment['paymentId'], 'Paid by mistake', $this->actor);

        expect(SaleService::getSaleOutstanding($sale['saleId']))->toBe(8_000)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(8_000);
    });

    it('KEEPS the record of what that payment settled', function () {
        $sale = ($this->creditSale)();
        $payment = ($this->pay)(3_000);

        CustomerPaymentService::voidCustomerPayment($payment['paymentId'], 'Paid by mistake', $this->actor);

        $allocations = DB::table('customer_payment_allocations')->where('payment_id', $payment['paymentId'])->get();
        expect($allocations)->toHaveCount(1)
            ->and((int) $allocations[0]->sale_id)->toBe($sale['saleId'])
            ->and((int) $allocations[0]->amount_minor)->toBe(3_000);
    });

    it('leaves the receivables control account and the subledger agreeing', function () {
        ($this->creditSale)();
        $payment = ($this->pay)(3_000);
        CustomerPaymentService::voidCustomerPayment($payment['paymentId'], 'Paid by mistake', $this->actor);

        expect(CustomerService::getTotalReceivables())->toBe(Shop::balance(ChartOfAccounts::ACCOUNTS_RECEIVABLE))
            ->and(Shop::trialBalanced())->toBeTrue();
    });

    it('does not let a voided payment be counted twice if the customer pays again', function () {
        $sale = ($this->creditSale)();
        $wrong = ($this->pay)(3_000);
        CustomerPaymentService::voidCustomerPayment($wrong['paymentId'], 'Wrong amount', $this->actor);

        ($this->pay)(8_000);

        expect(SaleService::getSaleOutstanding($sale['saleId']))->toBe(0)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(0)
            ->and(Shop::trialBalanced())->toBeTrue();
    });
});

describe('voiding a supplier payment', function () {
    it('puts the purchase back to outstanding and keeps the record', function () {
        $supplier = Shop::supplier($this->actor, 'Depot');
        $purchase = Shop::purchase($this->actor, $supplier, [[$this->product, 10_000, 1_000]], [], ['businessDate' => PVOID_DAY]);

        $payment = SupplierPaymentService::recordSupplierPayment(
            ['supplierId' => $supplier, 'businessDate' => PVOID_DAY, 'paymentAccountId' => $this->cash, 'amount' => 4_000],
            $this->actor,
        );
        expect(PurchaseService::getPurchaseOutstanding($purchase['purchaseId']))->toBe(6_000);

        SupplierPaymentService::voidSupplierPayment($payment['paymentId'], 'Paid the wrong supplier', $this->actor);

        expect(PurchaseService::getPurchaseOutstanding($purchase['purchaseId']))->toBe(10_000)
            ->and(SupplierService::getSupplierBalance($supplier))->toBe(10_000);

        // The allocation history must survive the void.
        $allocations = DB::table('supplier_payment_allocations')->where('payment_id', $payment['paymentId'])->get();
        expect($allocations)->toHaveCount(1)
            ->and((int) $allocations[0]->purchase_id)->toBe($purchase['purchaseId'])
            ->and((int) $allocations[0]->amount_minor)->toBe(4_000);

        expect(SupplierService::getTotalPayables())->toBe(Shop::balance(ChartOfAccounts::ACCOUNTS_PAYABLE))
            ->and(Shop::trialBalanced())->toBeTrue();
    });
});
