<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/list-order-by-trading-date.test.ts: lists
| run in the order the shop traded, not the order rows were written. Each test
| writes rows deliberately out of sequence. The account statement is the one
| with money at stake: its running balance accumulates in trading order.
*/

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Enums\JournalSourceType;
use App\Services\JournalService;
use App\Services\PaymentAccountService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Support\Carbon;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->stocked = function (string $name): int {
        $id = Shop::product($this->actor, $name, 5_000, 10_000);
        Shop::openingStock($this->actor, $id, 500_000, 2_500_000, '2026-06-01');

        return $id;
    };
    // The old tests' local times: the test machine ran on Accra time.
    $this->at = fn (string $local): Carbon => Carbon::parse($local, 'Africa/Accra');
});

describe('the sales list', function () {
    // RCP-00008 was a quote converted last and dated the 1st, and sat above a sale made on the 30th.
    it('puts a sale where its trading date belongs, not where it was keyed in', function () {
        $product = ($this->stocked)('Cement 50kg');
        $sale = fn (string $date, string $writtenAt) => SaleService::createSale([
            'businessDate' => $date,
            'occurredAt' => ($this->at)($writtenAt),
            'items' => [['productId' => $product, 'qty' => 1_000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 10_000]],
        ], $this->actor);

        $sale('2026-08-30', '2026-08-31 13:19:01');
        $sale('2026-07-21', '2026-08-31 13:19:02');
        $sale('2026-08-01', '2026-08-31 13:19:03');

        expect(array_column(SaleService::listSales(), 'businessDate'))->toBe(['2026-08-30', '2026-08-01', '2026-07-21'])
            ->and(array_column(SaleService::listSales(['sort' => 'date', 'direction' => 'asc']), 'businessDate'))->toBe(['2026-07-21', '2026-08-01', '2026-08-30']);
    });
});

describe('the purchases list', function () {
    it('puts a delivery where its date belongs, not where it was booked in', function () {
        $product = ($this->stocked)('Iron rod 12mm');
        $supplier = Shop::supplier($this->actor, 'Tema Steel Works');
        $line = fn (string $date, string $writtenAt) => PurchaseService::createPurchase([
            'businessDate' => $date,
            'occurredAt' => ($this->at)($writtenAt),
            'supplierId' => $supplier,
            'items' => [['productId' => $product, 'qty' => 10_000, 'unitCost' => 5_000]],
            'tenders' => [],
        ], $this->actor);

        $line('2026-08-22', '2026-08-31 13:19:01');
        $line('2026-06-18', '2026-08-31 13:19:02');
        $line('2026-07-16', '2026-08-31 13:19:03');

        expect(array_column(PurchaseService::listPurchases(), 'businessDate'))->toBe(['2026-08-22', '2026-07-16', '2026-06-18']);
    });
});

describe('an account statement', function () {
    beforeEach(function () {
        // A balanced cash entry on a trading day, written at a chosen moment.
        $this->cashIn = fn (string $entryDate, string $writtenAt, int $amount) => Tx::run(fn () => JournalService::post(
            $entryDate,
            JournalSourceType::OpeningBalance,
            [Journal::debit(Shop::accountId('1001'), $amount), Journal::credit(Shop::accountId(ChartOfAccounts::OWNERS_CAPITAL), $amount)],
            memo: 'test entry',
            isOpening: true,
            occurredAt: ($this->at)($writtenAt),
        ));
        ($this->cashIn)('2026-08-20', '2026-08-31 13:19:01', 300_00);
        ($this->cashIn)('2026-08-08', '2026-08-31 13:19:02', 200_00);
        ($this->cashIn)('2026-08-02', '2026-08-31 13:19:03', 100_00);
    });

    // Accumulated by write time, the 8 August row carried the 20 August money.
    it('accumulates the running balance in trading order', function () {
        $statement = PaymentAccountService::getAccountStatement($this->cash);

        // Newest first, so the balances count back down the account.
        expect(array_column($statement['movements'], 'entryDate'))->toBe(['2026-08-20', '2026-08-08', '2026-08-02'])
            ->and(array_column($statement['movements'], 'runningBalance'))->toBe([600_00, 300_00, 100_00]);
    });

    it('reconciles opening plus movement to closing', function () {
        $statement = PaymentAccountService::getAccountStatement($this->cash);

        expect($statement['opening'] + $statement['moneyIn'] - $statement['moneyOut'])->toBe($statement['closing'])
            ->and($statement['movements'][0]['runningBalance'])->toBe($statement['closing']);
    });
});
