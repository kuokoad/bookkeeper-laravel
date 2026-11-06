<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/overpayment.test.ts, through the real
| balance sheet and receivables split. Nothing is deferred.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ValidationError;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\Reporting\FinancialService;
use App\Services\Reporting\SubledgerSplit;
use App\Services\SaleService;
use Tests\Support\Shop;

const OVERPAY_TODAY = '2026-08-16';

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
            ->and(CustomerService::getTotalReceivables())->toBe(Shop::balance(ChartOfAccounts::ACCOUNTS_RECEIVABLE), "{$label}: A/R subledger vs control")
            ->and(FinancialService::getBalanceSheet(OVERPAY_TODAY)['balances'])->toBeTrue("{$label}: balance sheet");
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

        $sheet = FinancialService::getBalanceSheet(OVERPAY_TODAY);
        // Netting these would report 100.00 owed and no liability at all.
        expect($sheet['receivables'])->toBe(30_000)
            ->and($sheet['customerCredits'])->toBe(20_000)
            ->and($sheet['balances'])->toBeTrue();
    });

    it('still sums to the control account, so nothing is invented', function () {
        Shop::setting('allow_overpayment', true);
        $owing = Shop::customer($this->actor, 'Owes Money');
        $credited = Shop::customer($this->actor, 'In Credit');
        ($this->sellOnCredit)($owing);
        ($this->sellOnCredit)($credited);
        ($this->pay)($credited, 50_000);

        $split = SubledgerSplit::getReceivablesSplit();
        expect($split['net'])->toBe(Shop::balance(ChartOfAccounts::ACCOUNTS_RECEIVABLE))
            ->and($split['owed'] - $split['inCredit'])->toBe($split['net'])
            ->and($split['creditCount'])->toBe(1);
    });

    it('reports nothing extra when nobody is in credit', function () {
        $customerId = Shop::customer($this->actor, 'Ama Serwaa');
        ($this->sellOnCredit)($customerId);

        $sheet = FinancialService::getBalanceSheet(OVERPAY_TODAY);
        expect($sheet['customerCredits'])->toBe(0)->and($sheet['receivables'])->toBe(30_000);
    });
});
