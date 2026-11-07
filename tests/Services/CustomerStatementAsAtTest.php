<?php

declare(strict_types=1);

/*
| PLAN D33: a customer statement as at a past date shows each invoice, and
| the total, as they stood that day, by the same rule as receivables ageing
| (D32, SaleService::getOutstandingBySale with asAt).
*/

use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\Reporting\LedgerService;
use App\Services\SaleService;
use Illuminate\Support\Carbon;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->product = Shop::product($this->actor, 'Rice 5kg', 6_000, 10_000, ['unit' => 'bag']);
    Shop::openingStock($this->actor, $this->product, 10_000, 60_000, '2026-01-01');
    $this->customer = Shop::customer($this->actor, 'Mensah Provisions');
    // An invoice of 100.00 on 5 Jan, 60.00 paid on 20 Jan.
    $this->sale = SaleService::createSale([
        'businessDate' => '2026-01-05', 'customerId' => $this->customer,
        'items' => [['productId' => $this->product, 'qty' => 1000]], 'tenders' => [],
    ], $this->actor)['saleId'];
    $this->payment = CustomerPaymentService::recordCustomerPayment([
        'customerId' => $this->customer, 'businessDate' => '2026-01-20', 'paymentAccountId' => $this->cash, 'amount' => 6_000,
        'allocations' => [['saleId' => $this->sale, 'amount' => 6_000]],
    ], $this->actor)['paymentId'];
    $this->owed = function (?string $asAt): array {
        $s = CustomerPaymentService::getCustomerStatement($this->customer, $asAt);

        return [array_column($s['rows'], 'outstanding', 'id'), $s['balance'], $s['agrees']];
    };
});

it('shows an invoice on a statement as at a past date as owed before a later payment', function () {
    expect(($this->owed)('2026-01-15'))->toBe([[$this->sale => 10_000], 10_000, true])
        ->and(($this->owed)('2026-01-20'))->toBe([[$this->sale => 4_000], 4_000, true]);
});

it('counts a payment voided after the statement date', function () {
    CustomerPaymentService::voidCustomerPayment($this->payment, 'cheque bounced', $this->actor, Carbon::parse('2026-02-03 10:00:00'));

    expect(($this->owed)('2026-01-31'))->toBe([[$this->sale => 4_000], 4_000, true])
        ->and(($this->owed)('2026-02-03'))->toBe([[$this->sale => 10_000], 10_000, true]);
});

it('keeps a sale voided after the statement date on the statement', function () {
    // A sale with a payment on it can only be voided once the payment is.
    CustomerPaymentService::voidCustomerPayment($this->payment, 'cheque bounced', $this->actor, Carbon::parse('2026-02-03 10:00:00'));
    SaleService::voidSale($this->sale, 'wrong customer', $this->actor, Carbon::parse('2026-02-03 11:00:00'));

    $aged = collect(LedgerService::getReceivablesAgeing('2026-01-31'))->firstWhere('partyId', $this->customer)['total'] ?? 0;
    expect(($this->owed)('2026-01-31'))->toBe([[$this->sale => 4_000], 4_000, true])
        ->and($aged)->toBe(4_000);
});

it('totals a statement as at a date to the customer\'s receivables ageing on that date', function () {
    $other = Shop::customer($this->actor, 'Ama Stores');
    SaleService::createSale(['businessDate' => '2026-01-08', 'customerId' => $other, 'items' => [['productId' => $this->product, 'qty' => 2000]], 'tenders' => []], $this->actor);
    $second = SaleService::createSale(['businessDate' => '2026-01-25', 'customerId' => $this->customer, 'items' => [['productId' => $this->product, 'qty' => 3000]], 'tenders' => []], $this->actor)['saleId'];
    CustomerPaymentService::recordCustomerPayment([
        'customerId' => $this->customer, 'businessDate' => '2026-02-10', 'paymentAccountId' => $this->cash, 'amount' => 30_000,
        'allocations' => [['saleId' => $second, 'amount' => 30_000]],
    ], $this->actor);
    CustomerPaymentService::voidCustomerPayment($this->payment, 'cheque bounced', $this->actor, Carbon::parse('2026-02-03 10:00:00'));

    foreach (['2026-01-04', '2026-01-15', '2026-01-20', '2026-01-31', '2026-02-03', '2026-02-10'] as $asAt) {
        $aged = collect(LedgerService::getReceivablesAgeing($asAt))->firstWhere('partyId', $this->customer)['total'] ?? 0;
        $statement = CustomerPaymentService::getCustomerStatement($this->customer, $asAt);
        expect([$asAt, $statement['balance'], $statement['rowsTotal']])->toBe([$asAt, $aged, $aged]);
    }
});

it('leaves a statement with no as-at date as it stands today', function () {
    CustomerPaymentService::voidCustomerPayment($this->payment, 'cheque bounced', $this->actor, Carbon::parse('2026-02-03 10:00:00'));

    expect(($this->owed)(null))->toBe([[$this->sale => 10_000], 10_000, true])
        ->and(CustomerService::getCustomerBalance($this->customer))->toBe(10_000);
});
