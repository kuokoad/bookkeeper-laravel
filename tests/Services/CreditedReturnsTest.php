<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/credited-returns.test.ts: goods put back
| on account come off the sale they were bought on.
|
| The receivables ageing (Phase 7) is not built yet. Where the old test summed
| getReceivablesAgeing, this sums getOutstandingBySale as at the same date
| (the read the ageing is built from), and the ledger figure is the A/R
| journal lines dated up to that day.
|
| Deferred to Phase 7 (reports): "ages the credit with the sale it came off,
| not on the day it was given" (needs the ageing buckets).
| Deferred to Phase 5 (purchases): the whole "a purchase after goods go back on
| account" block (4 tests, 5 with the each).
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ValidationError;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const CRET_DAY = '2026-08-10';
const CRET_BEFORE_RETURN = '2026-08-15';
const CRET_RETURN_DAY = '2026-08-20';
const CRET_AFTER_RETURN = '2026-08-31';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->customer = Shop::customer($this->actor, 'Mensah Provisions');
    $this->rice = Shop::product($this->actor, 'Rice 5kg', 8_000, 12_000, ['unit' => 'bag']);
    Shop::openingStock($this->actor, $this->rice, 40_000, 320_000, '2026-08-01');

    // Rice at 120.00 a bag, on the customer's account.
    $this->creditSale = fn (int $units, string $businessDate = CRET_DAY): int => SaleService::createSale([
        'businessDate' => $businessDate,
        'customerId' => $this->customer,
        'items' => [['productId' => $this->rice, 'qty' => $units * 1000]],
        'tenders' => [],
    ], $this->actor)['saleId'];

    $this->bringBack = fn (int $saleId, int $units, int $refund = 0): array => ReturnsService::createCustomerReturn($saleId, [
        'businessDate' => CRET_RETURN_DAY,
        'items' => [['itemId' => SaleService::getSale($saleId)['items'][0]['id'], 'qty' => $units * 1000]],
        'refunds' => $refund === 0 ? [] : [['paymentAccountId' => $this->cash, 'amount' => $refund]],
    ], $this->actor);

    $this->pay = fn (int $amount): array => CustomerPaymentService::recordCustomerPayment(
        ['customerId' => $this->customer, 'businessDate' => CRET_DAY, 'paymentAccountId' => $this->cash, 'amount' => $amount],
        $this->actor,
    );

    $this->receivablesAsAt = fn (string $asAt): int => (int) DB::table('journal_lines')
        ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
        ->where('accounts.code', ChartOfAccounts::ACCOUNTS_RECEIVABLE)
        ->where('journal_entries.entry_date', '<=', $asAt)
        ->selectRaw('COALESCE(SUM(`journal_lines`.`debit_minor` - `journal_lines`.`credit_minor`), 0) AS b')
        ->value('b');
});

describe('a sale after goods come back on account', function () {
    it('owes only what is left', function () {
        $sale = ($this->creditSale)(2);
        ($this->bringBack)($sale, 1);

        expect(SaleService::getSaleOutstanding($sale))->toBe(12_000)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(12_000);
    });

    it('owes nothing once everything is back, and leaves the open invoices', function () {
        $sale = ($this->creditSale)(2);
        ($this->bringBack)($sale, 2);

        expect(SaleService::getSaleOutstanding($sale))->toBe(0)
            ->and(CustomerPaymentService::getOpenSales($this->customer))->toBe([]);
    });

    it('says the same thing on the list, in the batch read and on the sale', function () {
        $sale = ($this->creditSale)(2);
        $returnSaleId = ($this->bringBack)($sale, 1)['returnSaleId'];

        $row = collect(SaleService::listSales())->firstWhere('id', $sale);
        expect($row['outstandingMinor'])->toBe(12_000)
            ->and(SaleService::getOutstandingBySale()[$sale])->toBe(12_000)
            ->and(SaleService::getOutstandingBySale()[$returnSaleId])->toBe(0)
            ->and(SaleService::getFilteredSalesSummary([])['outstanding'])->toBe(12_000);
    });

    it('owes less after a part payment and a return', function () {
        $sale = ($this->creditSale)(2);
        ($this->pay)(10_000);
        ($this->bringBack)($sale, 1);

        expect(SaleService::getSaleOutstanding($sale))->toBe(2_000)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(2_000);
    });

    it('counts only what was credited, not what was refunded in cash', function () {
        $sale = ($this->creditSale)(2);
        ($this->pay)(24_000);
        ($this->bringBack)($sale, 1, 2_000);

        expect(SaleService::getSaleOutstanding($sale))->toBe(-10_000)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(-10_000);
    });

    it('adds up across several returns', function () {
        $sale = ($this->creditSale)(3);
        ($this->bringBack)($sale, 1);
        ($this->bringBack)($sale, 1);

        expect(SaleService::getSaleOutstanding($sale))->toBe(12_000);
    });

    it('shows a paid sale as in credit, which the customer balance agrees with', function () {
        $sale = ($this->creditSale)(1);
        ($this->pay)(12_000);
        ($this->bringBack)($sale, 1);

        expect(SaleService::getSaleOutstanding($sale))->toBe(-12_000)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(-12_000)
            ->and(CustomerPaymentService::getOpenSales($this->customer))->toBe([]);
    });
});

describe('what the invoice is built from', function () {
    it('keeps goods credited back apart from money received', function () {
        $sale = ($this->creditSale)(2);
        ($this->pay)(12_000);
        ($this->bringBack)($sale, 2);

        $read = SaleService::getSale($sale);
        expect($read['creditedMinor'])->toBe(24_000)
            ->and($read['outstandingMinor'])->toBe(-12_000)
            // What the invoice shows as already paid: the 120.00 that was handed over.
            ->and($read['totalMinor'] - $read['creditedMinor'] - $read['outstandingMinor'])->toBe(12_000);
    });
});

describe('a payment after goods came back', function () {
    it('lands on the invoice still owing', function () {
        $returned = ($this->creditSale)(1);
        $open = ($this->creditSale)(1);
        ($this->bringBack)($returned, 1);

        ($this->pay)(12_000);

        expect(SaleService::getSaleOutstanding($returned))->toBe(0)
            ->and(SaleService::getSaleOutstanding($open))->toBe(0)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(0);
    });

    it('cannot be set against more than the sale still owes', function () {
        $sale = ($this->creditSale)(2);
        ($this->bringBack)($sale, 1);

        expect(fn () => CustomerPaymentService::recordCustomerPayment([
            'customerId' => $this->customer,
            'businessDate' => CRET_DAY,
            'paymentAccountId' => $this->cash,
            'amount' => 20_000,
            'allocations' => [['saleId' => $sale, 'amount' => 20_000]],
        ], $this->actor))->toThrow(ValidationError::class);
    });
});

describe('the receivables ageing with goods back on account', function () {
    it('agrees with the sale and the ledger as at a date', function (string $asAt, int $owing) {
        $sale = ($this->creditSale)(2);
        ($this->bringBack)($sale, 1);

        $outstanding = SaleService::getOutstandingBySale(['asAt' => $asAt]);
        expect($outstanding[$sale])->toBe($owing)
            ->and(array_sum($outstanding))->toBe($owing)
            ->and(($this->receivablesAsAt)($asAt))->toBe($owing);
    })->with([
        'before the return' => [CRET_BEFORE_RETURN, 24_000],
        'on the day of the return' => [CRET_RETURN_DAY, 12_000],
        'after the return' => [CRET_AFTER_RETURN, 12_000],
    ]);

    it('keeps a customer in credit in step with the ledger', function () {
        $sale = ($this->creditSale)(1);
        ($this->pay)(12_000);
        ($this->bringBack)($sale, 1);

        expect(array_sum(SaleService::getOutstandingBySale(['asAt' => CRET_AFTER_RETURN])))->toBe(($this->receivablesAsAt)(CRET_AFTER_RETURN));
    });

    it('keeps the credit on the return when its sale was voided', function () {
        $sale = ($this->creditSale)(1);
        $returnSaleId = ($this->bringBack)($sale, 1)['returnSaleId'];
        DB::table('sales')->where('id', $sale)->update(['status' => 'VOIDED']);

        expect(SaleService::getSaleOutstanding($returnSaleId))->toBe(-12_000)
            ->and(SaleService::getOutstandingBySale()[$returnSaleId])->toBe(-12_000)
            ->and(collect(SaleService::listSales())->firstWhere('id', $returnSaleId)['outstandingMinor'])->toBe(-12_000);
    });
});
