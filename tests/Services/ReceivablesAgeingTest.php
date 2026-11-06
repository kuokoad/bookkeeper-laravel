<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/receivables-ageing.test.ts: who owes the
| shop, and for how long, as at a date.
|
| Read through the real report, LedgerService::getReceivablesAgeing, and the
| control account through BalancesService::getAccountBalanceByCode with a to
| date. The whole file is ported.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\CustomerPaymentService;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\LedgerService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->customer = Shop::customer($this->actor, 'Mensah Provisions');
    // A sale entirely on credit: nothing tendered at the counter.
    $this->creditSale = function (string $date, int $units): int {
        $id = Shop::product($this->actor, 'Rice 5kg', 6_000, 10_000);
        Shop::openingStock($this->actor, $id, 50_000, 300_000, '2026-01-01');

        return SaleService::createSale(['businessDate' => $date, 'customerId' => $this->customer, 'items' => [['productId' => $id, 'qty' => $units * 1000]], 'tenders' => []], $this->actor)['saleId'];
    };
    $this->owedAt = fn (string $asAt): int => collect(LedgerService::getReceivablesAgeing($asAt))->firstWhere('partyId', $this->customer)['total'] ?? 0;
    $this->pay = fn () => CustomerPaymentService::recordCustomerPayment(['businessDate' => '2026-05-10', 'customerId' => $this->customer, 'paymentAccountId' => $this->cash, 'amount' => 20_000], $this->actor);
    // One case came back; nothing refunded in cash, so it goes to their account.
    $this->returnOne = fn (int $saleId) => ReturnsService::createCustomerReturn($saleId, [
        'businessDate' => '2026-03-10',
        'items' => [['itemId' => (int) DB::table('sale_items')->where('sale_id', $saleId)->orderBy('line_no')->value('id'), 'qty' => 1_000]],
        'refunds' => [],
    ], $this->actor);
});

describe('"as at" a date in the past', function () {
    it('does not treat a payment made LATER as though it had already arrived', function () {
        ($this->creditSale)('2026-03-01', 2);
        ($this->pay)();

        expect(($this->owedAt)('2026-03-31'))->toBe(20_000)
            ->and(($this->owedAt)('2026-05-31'))->toBe(0);
    });

    it('ties to the receivables control account on the date asked about', function () {
        ($this->creditSale)('2026-03-01', 2);
        ($this->pay)();

        expect(($this->owedAt)('2026-03-31'))->toBe(BalancesService::getAccountBalanceByCode(ChartOfAccounts::ACCOUNTS_RECEIVABLE, ['to' => '2026-03-31']));
    });
});

describe('a credit note', function () {
    it('reduces what the customer owes', function () {
        ($this->returnOne)(($this->creditSale)('2026-03-01', 3));
        expect(($this->owedAt)('2026-03-31'))->toBe(20_000);
    });

    it('keeps the ageing tied to the control account', function () {
        ($this->returnOne)(($this->creditSale)('2026-03-01', 3));
        expect(($this->owedAt)('2026-03-31'))->toBe(BalancesService::getAccountBalanceByCode(ChartOfAccounts::ACCOUNTS_RECEIVABLE, ['to' => '2026-03-31']));
    });

    it('leaves the buckets adding up to the total', function () {
        ($this->returnOne)(($this->creditSale)('2026-03-01', 3));
        $row = collect(LedgerService::getReceivablesAgeing('2026-06-30'))->firstWhere('partyId', $this->customer);

        expect($row['current'] + $row['days1to30'] + $row['days31to60'] + $row['days61to90'] + $row['over90'])->toBe($row['total']);
    });
});

describe('walk-in sales', function () {
    it('are not loaded into the ageing, since a sale with no customer can never owe', function () {
        Shop::setNoTax();
        $credit = ($this->creditSale)('2026-03-01', 2);
        $rice = (int) DB::table('sale_items')->where('sale_id', $credit)->value('product_id');
        $walkIn = Shop::sell($this->actor, [[$rice, 1_000]], [['paymentAccountId' => $this->cash, 'amount' => 10_000]], ['businessDate' => '2026-03-02'])['saleId'];

        $outstanding = SaleService::getOutstandingBySale(['asAt' => '2026-03-31']);

        expect($outstanding)->toBe([$credit => 20_000])
            ->and(array_key_exists($walkIn, $outstanding))->toBeFalse()
            ->and(($this->owedAt)('2026-03-31'))->toBe(20_000);
    });
});
