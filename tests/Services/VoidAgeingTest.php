<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/void-ageing.test.ts: a void's mirror is
| not a debt going the other way.
|
| The receivables ageing is Phase 7; it is read through Tests\Support\Ageing,
| which follows the old report line for line.
|
| Deferred to Phase 5 (purchases and suppliers): the whole "payables ageing
| after a void" block (5 tests: really owes, agrees with the ledger before /
| on / after the void, and no negative owing on the purchases list).
*/

use App\Services\CustomerService;
use App\Services\SaleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Ageing;
use Tests\Support\Shop;

const VA_BEFORE_VOID = '2026-08-20';
const VA_VOID_DAY = '2026-09-05';
const VA_AFTER_VOID = '2026-09-30';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->voidAt = Carbon::parse(VA_VOID_DAY.' 10:00:00');
    $this->customer = Shop::customer($this->actor, 'Mensah Provisions');
    $this->product = Shop::product($this->actor, 'Rice 5kg', 3_000, 10_000, ['unit' => 'bag']);
    Shop::openingStock($this->actor, $this->product, 40_000, 120_000, '2026-08-01');
    $this->creditSale = fn (int $units, int $tendered = 0): array => SaleService::createSale([
        'businessDate' => '2026-08-10',
        'customerId' => $this->customer,
        'items' => [['productId' => $this->product, 'qty' => $units * 1000]],
        'tenders' => $tendered > 0 ? [['paymentAccountId' => $this->cash, 'amount' => $tendered]] : [],
    ], $this->actor);
    $this->aged = fn (string $asAt): int => array_sum(array_column(Ageing::receivables($asAt), 'total'));
});

describe('receivables ageing after a void', function () {
    it('shows what the customer really owes, not the void set against it', function () {
        ($this->creditSale)(5);
        $voided = ($this->creditSale)(10);
        SaleService::voidSale($voided['saleId'], 'wrong customer', $this->actor, $this->voidAt);

        expect(CustomerService::getCustomerBalance($this->customer))->toBe(50_000)
            ->and(($this->aged)(VA_AFTER_VOID))->toBe(50_000);
    });

    it('ignores a void written before mirrors were marked as voids', function () {
        ($this->creditSale)(5);
        $voided = ($this->creditSale)(10);
        $reversal = SaleService::voidSale($voided['saleId'], 'wrong customer', $this->actor, $this->voidAt)['reversalSaleId'];
        DB::table('sales')->where('id', $reversal)->update(['kind' => 'SALE']);

        expect(($this->aged)(VA_AFTER_VOID))->toBe(50_000)
            ->and(SaleService::getFilteredSalesSummary()['outstanding'])->toBe(50_000)
            ->and(SaleService::getSaleOutstanding($reversal))->toBe(0);
    });

    it('leaves nobody on the list when the only sale was voided', function () {
        $only = ($this->creditSale)(10);
        SaleService::voidSale($only['saleId'], 'wrong customer', $this->actor, $this->voidAt);

        expect(Ageing::receivables(VA_AFTER_VOID))->toBe([]);
    });

    it('counts only what was left unpaid on a part-paid sale, before the void', function () {
        $sale = ($this->creditSale)(10, 30_000);
        SaleService::voidSale($sale['saleId'], 'wrong customer', $this->actor, $this->voidAt);

        expect(($this->aged)(VA_BEFORE_VOID))->toBe(70_000)
            ->and(($this->aged)(VA_AFTER_VOID))->toBe(0);
    });

    it('agrees with the ledger as at a date', function (string $label, string $asAt) {
        ($this->creditSale)(5);
        $voided = ($this->creditSale)(10);
        SaleService::voidSale($voided['saleId'], 'wrong customer', $this->actor, $this->voidAt);

        expect(($this->aged)($asAt))->toBe(Ageing::receivablesAt($asAt));
    })->with([
        ['before the void', VA_BEFORE_VOID],
        ['on the day of the void', VA_VOID_DAY],
        ['after the void', VA_AFTER_VOID],
    ]);
});

describe('the sales list after a void', function () {
    it('does not report a negative amount still owing', function () {
        ($this->creditSale)(5);
        $voided = ($this->creditSale)(10);
        $reversal = SaleService::voidSale($voided['saleId'], 'wrong customer', $this->actor, $this->voidAt)['reversalSaleId'];

        expect(SaleService::getFilteredSalesSummary()['outstanding'])->toBe(50_000)
            ->and(SaleService::getSaleOutstanding($reversal))->toBe(0);
    });
});
