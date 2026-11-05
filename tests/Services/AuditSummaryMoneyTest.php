<?php

declare(strict_types=1);

/*
| Ported from the sale and customer-payment parts of the old
| tests/services/audit-summary-money.test.ts: the audit log writes money in the
| shop's format, never as raw pesewas. The exact string is the point.
|
| Deferred: "records a supplier payment as money" and "records a purchase total
| as money" (purchases, Phase 5), and "records a till shortage as money"
| (reconciliation, Phase 6).
*/

use App\Services\CustomerPaymentService;
use App\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const AUDIT_MONEY_TODAY = '2026-08-16';

/** The summary of the newest audit row of this kind. */
function auditMoneyLatest(string $entityType): string
{
    return DB::table('audit_logs')->where('entity_type', $entityType)->orderByDesc('id')->value('summary')
        ?? throw new RuntimeException("No audit row written for {$entityType}");
}

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->stocked = function (): int {
        $id = Shop::product($this->actor, 'Cement 50kg', 8_000, 9_600, ['unit' => 'bag']);
        Shop::openingStock($this->actor, $id, 500_000, 4_000_000, AUDIT_MONEY_TODAY);

        return $id;
    };
});

describe('money in the audit log', function () {
    it('records a sale total as money, not as pesewas', function () {
        $product = ($this->stocked)();
        SaleService::createSale([
            'businessDate' => AUDIT_MONEY_TODAY,
            'items' => [['productId' => $product, 'qty' => 100_000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 960_000]],
        ], $this->actor);

        // GHS 9,600.00, not "960000".
        expect(auditMoneyLatest('sale'))->toContain('total GHS 9,600.00')
            ->and(auditMoneyLatest('sale'))->not->toMatch('/total \d+$/');
    });

    it('records a customer payment as money, not as pesewas', function () {
        $customer = Shop::customer($this->actor, 'Adom Construction Ltd');
        $product = ($this->stocked)();
        SaleService::createSale([
            'businessDate' => AUDIT_MONEY_TODAY,
            'customerId' => $customer,
            'items' => [['productId' => $product, 'qty' => 100_000]],
            'tenders' => [],
        ], $this->actor);

        CustomerPaymentService::recordCustomerPayment([
            'businessDate' => AUDIT_MONEY_TODAY,
            'customerId' => $customer,
            'paymentAccountId' => $this->cash,
            'amount' => 200_000,
        ], $this->actor);

        expect(auditMoneyLatest('customer_payment'))->toContain('received GHS 2,000.00 from Adom Construction Ltd');
    });
});
