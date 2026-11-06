<?php

declare(strict_types=1);

/*
| PLAN D32: ageing as at a past date shows a payment as it stood that day. A
| payment voided after the date still settled the debt on it; a void dated on
| or before it did not. The ageing total must agree with the control account
| and the subledger as at the same date.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\CustomerPaymentService;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\LedgerService;
use App\Services\Reporting\SubledgerSplit;
use App\Services\SaleService;
use App\Services\SupplierPaymentService;
use Illuminate\Support\Carbon;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->product = Shop::product($this->actor, 'Rice 5kg', 6_000, 10_000, ['unit' => 'bag']);
    Shop::openingStock($this->actor, $this->product, 10_000, 60_000, '2026-01-01');
});

it('shows a customer payment voided later as paid on the day before the void', function () {
    $customer = Shop::customer($this->actor, 'Mensah Provisions');
    $sale = SaleService::createSale([
        'businessDate' => '2026-01-05',
        'customerId' => $customer,
        'items' => [['productId' => $this->product, 'qty' => 1000]],
        'tenders' => [],
    ], $this->actor);
    $payment = CustomerPaymentService::recordCustomerPayment([
        'customerId' => $customer, 'businessDate' => '2026-01-10', 'paymentAccountId' => $this->cash, 'amount' => 10_000,
        'allocations' => [['saleId' => $sale['saleId'], 'amount' => 10_000]],
    ], $this->actor);
    CustomerPaymentService::voidCustomerPayment($payment['paymentId'], 'cheque bounced', $this->actor, Carbon::parse('2026-02-03 10:00:00'));

    $aged = fn (string $asAt): int => array_sum(array_column(LedgerService::getReceivablesAgeing($asAt), 'total'));

    expect($aged('2026-01-31'))->toBe(0)
        ->and(BalancesService::getAccountBalanceByCode(ChartOfAccounts::ACCOUNTS_RECEIVABLE, ['to' => '2026-01-31']))->toBe(0)
        ->and(SubledgerSplit::getReceivablesSplit('2026-01-31')['net'])->toBe(0)
        ->and($aged('2026-02-03'))->toBe(10_000)
        ->and(BalancesService::getAccountBalanceByCode(ChartOfAccounts::ACCOUNTS_RECEIVABLE, ['to' => '2026-02-03']))->toBe(10_000);
});

it('shows a supplier payment voided later as paid on the day before the void', function () {
    $supplier = Shop::supplier($this->actor, 'Kofi Traders');
    $purchase = Shop::purchase($this->actor, $supplier, [[$this->product, 1000, 10_000]], [], ['businessDate' => '2026-01-05']);
    $payment = SupplierPaymentService::recordSupplierPayment([
        'supplierId' => $supplier, 'businessDate' => '2026-01-10', 'paymentAccountId' => $this->cash, 'amount' => 10_000,
        'allocations' => [['purchaseId' => $purchase['purchaseId'], 'amount' => 10_000]],
    ], $this->actor);
    SupplierPaymentService::voidSupplierPayment($payment['paymentId'], 'cheque bounced', $this->actor, Carbon::parse('2026-02-03 10:00:00'));

    $aged = fn (string $asAt): int => array_sum(array_column(LedgerService::getPayablesAgeing($asAt), 'total'));

    expect($aged('2026-01-31'))->toBe(0)
        ->and(BalancesService::getAccountBalanceByCode(ChartOfAccounts::ACCOUNTS_PAYABLE, ['to' => '2026-01-31']))->toBe(0)
        ->and(SubledgerSplit::getPayablesSplit('2026-01-31')['net'])->toBe(0)
        ->and($aged('2026-02-03'))->toBe(10_000)
        ->and(BalancesService::getAccountBalanceByCode(ChartOfAccounts::ACCOUNTS_PAYABLE, ['to' => '2026-02-03']))->toBe(10_000);
});
