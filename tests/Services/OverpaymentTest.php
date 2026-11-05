<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/overpayment.test.ts.
|
| The balance sheet and the receivables split are Phase 7 reports. Until they
| arrive, "receivables" and "customer credits" are asserted as the sum of the
| positive and of the negative customer balances (what getReceivablesSplit
| summed), against the 1100 control account, and "the balance sheet balances"
| as the trial balance balancing.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ValidationError;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\SaleService;
use Tests\Support\Shop;

const OVERPAY_TODAY = '2026-08-16';

/** @return array{owed: int, inCredit: int, net: int, creditCount: int} */
function overpaymentSplit(): array
{
    $balances = CustomerService::getAllCustomerBalances();
    $owed = array_sum(array_filter($balances, fn (int $b): bool => $b > 0));
    $credits = array_filter($balances, fn (int $b): bool => $b < 0);

    return ['owed' => $owed, 'inCredit' => -array_sum($credits), 'net' => array_sum($balances), 'creditCount' => count($credits)];
}

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->counter = 0;
    // A credit sale of 500 that leaves 300 owed.
    $this->sellOnCredit = function (int $customerId): void {
        $this->counter++;
        $productId = Shop::product($this->actor, "Rice {$this->counter}", 1_400, 1_900, ['unit' => 'kg']);
        Shop::openingStock($this->actor, $productId, 100_000, 140_000, OVERPAY_TODAY);
        SaleService::createSale([
            'businessDate' => OVERPAY_TODAY,
            'customerId' => $customerId,
            'items' => [['productId' => $productId, 'unitPrice' => 50_000, 'qty' => 1_000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 20_000]],
            'allowPriceOverride' => true,
        ], $this->actor);
    };
    $this->pay = fn (int $customerId, int $amount): array => CustomerPaymentService::recordCustomerPayment(
        ['customerId' => $customerId, 'businessDate' => OVERPAY_TODAY, 'paymentAccountId' => $this->cash, 'amount' => $amount],
        $this->actor,
    );
    $this->healthy = function (string $label): void {
        expect(Shop::trialBalanced())->toBeTrue("{$label}: trial balance")
            ->and(CustomerService::getTotalReceivables())->toBe(Shop::balance(ChartOfAccounts::ACCOUNTS_RECEIVABLE), "{$label}: A/R subledger vs control");
    };
});

describe('when overpayment is off (the default)', function () {
    it('refuses more than is owed', function () {
        $customerId = Shop::customer($this->actor, 'Ama Serwaa');
        ($this->sellOnCredit)($customerId);

        expect(fn () => ($this->pay)($customerId, 40_000))->toThrow(ValidationError::class);
        expect(CustomerService::getCustomerBalance($customerId))->toBe(30_000);
    });

    it('says how to allow it, rather than only refusing', function () {
        $customerId = Shop::customer($this->actor, 'Ama Serwaa');
        ($this->sellOnCredit)($customerId);

        expect(fn () => ($this->pay)($customerId, 40_000))->toThrow(ValidationError::class, 'Settings');
    });

    it('still accepts payment of exactly what is owed', function () {
        $customerId = Shop::customer($this->actor, 'Ama Serwaa');
        ($this->sellOnCredit)($customerId);

        ($this->pay)($customerId, 30_000);
        expect(CustomerService::getCustomerBalance($customerId))->toBe(0);
        ($this->healthy)('exact payment');
    });
});

describe('when overpayment is on', function () {
    beforeEach(fn () => Shop::setting('allow_overpayment', true));

    it('accepts more than is owed and leaves the customer in credit', function () {
        $customerId = Shop::customer($this->actor, 'Ama Serwaa');
        ($this->sellOnCredit)($customerId);

        ($this->pay)($customerId, 50_000);

        expect(CustomerService::getCustomerBalance($customerId))->toBe(-20_000);
        ($this->healthy)('overpayment');
    });

    it('applies the credit against the next sale', function () {
        $customerId = Shop::customer($this->actor, 'Ama Serwaa');
        ($this->sellOnCredit)($customerId);
        ($this->pay)($customerId, 50_000);

        ($this->sellOnCredit)($customerId);
        expect(CustomerService::getCustomerBalance($customerId))->toBe(10_000);
        ($this->healthy)('credit applied');
    });

    it('takes an advance from a customer who owes nothing at all', function () {
        $customerId = Shop::customer($this->actor, 'Kofi Mensah');

        ($this->pay)($customerId, 15_000);
        expect(CustomerService::getCustomerBalance($customerId))->toBe(-15_000);
        ($this->healthy)('advance');
    });
});

describe('a customer in credit on the balance sheet', function () {
    it('is reported as a liability, not netted inside receivables', function () {
        Shop::setting('allow_overpayment', true);
        $owing = Shop::customer($this->actor, 'Owes Money');
        $credited = Shop::customer($this->actor, 'In Credit');
        ($this->sellOnCredit)($owing);
        ($this->sellOnCredit)($credited);
        ($this->pay)($credited, 50_000);

        $split = overpaymentSplit();
        expect($split['owed'])->toBe(30_000)
            ->and($split['inCredit'])->toBe(20_000)
            ->and(Shop::trialBalanced())->toBeTrue();
    });

    it('still sums to the control account, so nothing is invented', function () {
        Shop::setting('allow_overpayment', true);
        $owing = Shop::customer($this->actor, 'Owes Money');
        $credited = Shop::customer($this->actor, 'In Credit');
        ($this->sellOnCredit)($owing);
        ($this->sellOnCredit)($credited);
        ($this->pay)($credited, 50_000);

        $split = overpaymentSplit();
        expect($split['net'])->toBe(Shop::balance(ChartOfAccounts::ACCOUNTS_RECEIVABLE))
            ->and($split['owed'] - $split['inCredit'])->toBe($split['net'])
            ->and($split['creditCount'])->toBe(1);
    });

    it('reports nothing extra when nobody is in credit', function () {
        $customerId = Shop::customer($this->actor, 'Ama Serwaa');
        ($this->sellOnCredit)($customerId);

        $split = overpaymentSplit();
        expect($split['inCredit'])->toBe(0)->and($split['owed'])->toBe(30_000);
    });
});
