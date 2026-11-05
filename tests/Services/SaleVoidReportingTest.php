<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/sale-void-reporting.test.ts: a void is a
| mirror dated the day of the correction, and the takings the owner reads
| agree with the Profit & Loss on each day.
|
| The P&L and the operations reports are Phase 7. Until then the same figures
| are read here the way the old reports built them: net sales is Sales Revenue
| less Sales Discounts and Sales Returns over journal lines dated in the
| window (financial.service); sales-by-day sums sales.total_minor per business
| date over every document dated in it; money-by-method sums sale_payments of
| every sale dated in it (operations.service salesDatedIn).
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\SaleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const SVR_SALE_DAY = '2026-08-10';
const SVR_VOID_DAY = '2026-08-17';

/** P&L net sales: revenue less discounts and returns, journal lines dated in the window. */
function svrNetSales(string $from, string $to): int
{
    $signed = fn (string $code): int => (int) DB::table('journal_lines')
        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
        ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.code', $code)
        ->whereBetween('journal_entries.entry_date', [$from, $to])
        ->selectRaw('COALESCE(SUM(journal_lines.credit_minor - journal_lines.debit_minor), 0) AS b')->value('b');

    return $signed(ChartOfAccounts::SALES_REVENUE) + $signed(ChartOfAccounts::SALES_DISCOUNTS) + $signed(ChartOfAccounts::SALES_RETURNS);
}

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    Carbon::setTestNow(SVR_SALE_DAY.' 10:00:00');
    $this->stocked = function (): int {
        $id = Shop::product($this->actor, 'Rice 5kg', 6_000, 10_000);
        Shop::openingStock($this->actor, $id, 20_000, 120_000, SVR_SALE_DAY);

        return $id;
    };
    // Sell two at GHS 100.00, then void it a week later.
    $this->sellThenVoid = function (): int {
        $created = SaleService::createSale([
            'businessDate' => SVR_SALE_DAY,
            'items' => [['productId' => ($this->stocked)(), 'qty' => 2_000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 20_000]],
        ], $this->actor);
        Carbon::setTestNow(SVR_VOID_DAY.' 10:00:00');
        SaleService::voidSale($created['saleId'], 'Rang up against the wrong customer', $this->actor);

        return $created['saleId'];
    };
});

afterEach(fn () => Carbon::setTestNow());

describe('the reversing document', function () {
    it('is marked as a VOID, not filed as an ordinary sale', function () {
        ($this->sellThenVoid)();
        expect(DB::table('sales')->whereNotNull('voids_sale_id')->value('kind'))->toBe('VOID');
    });
});

describe('the takings the owner reads', function () {
    it('agrees with the Profit & Loss on the day of the sale', function () {
        ($this->sellThenVoid)();
        expect(SaleService::getSalesSummary(SVR_SALE_DAY, SVR_SALE_DAY)['total'])->toBe(svrNetSales(SVR_SALE_DAY, SVR_SALE_DAY));
    });

    it('agrees with the Profit & Loss on the day of the void', function () {
        ($this->sellThenVoid)();
        expect(SaleService::getSalesSummary(SVR_VOID_DAY, SVR_VOID_DAY)['total'])->toBe(svrNetSales(SVR_VOID_DAY, SVR_VOID_DAY));
    });

    it('nets to nothing across both days, like the ledger does', function () {
        ($this->sellThenVoid)();
        $both = SaleService::getSalesSummary(SVR_SALE_DAY, SVR_VOID_DAY);
        expect($both['total'])->toBe(0)
            ->and($both['grossProfit'])->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::SALES_REVENUE))->toBe(0);
    });

    it('does not count the correction as another sale made', function () {
        ($this->sellThenVoid)();
        expect(SaleService::getSalesSummary(SVR_SALE_DAY, SVR_VOID_DAY)['count'])->toBe(1)
            ->and(SaleService::getSalesSummary(SVR_VOID_DAY, SVR_VOID_DAY)['count'])->toBe(0);
    });
});

describe('an ordinary sale, untouched', function () {
    it('still counts once, at its full value', function () {
        SaleService::createSale([
            'businessDate' => SVR_SALE_DAY,
            'items' => [['productId' => ($this->stocked)(), 'qty' => 2_000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 20_000]],
        ], $this->actor);

        $summary = SaleService::getSalesSummary(SVR_SALE_DAY, SVR_SALE_DAY);
        expect($summary['count'])->toBe(1)
            ->and($summary['total'])->toBe(20_000)
            ->and($summary['grossProfit'])->toBe(8_000);
    });

    it('is still listed', function () {
        SaleService::createSale([
            'businessDate' => SVR_SALE_DAY,
            'items' => [['productId' => ($this->stocked)(), 'qty' => 1_000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 10_000]],
        ], $this->actor);

        expect(count(SaleService::listSales()))->toBeGreaterThan(0);
    });
});

describe('the operations reports', function () {
    it('sales-by-day ties back to the Profit & Loss on EACH day', function () {
        ($this->sellThenVoid)();
        // Per day, not over the span: dropping both halves of a void nets out across a span.
        foreach ([SVR_SALE_DAY, SVR_VOID_DAY] as $day) {
            $reported = (int) DB::table('sales')->where('business_date', $day)->sum('total_minor');
            expect($reported)->toBe(svrNetSales($day, $day), "takings on {$day}");
        }
    });

    it('money-taken-by-method nets out too', function () {
        ($this->sellThenVoid)();
        $received = (int) DB::table('sale_payments')->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->whereBetween('sales.business_date', [SVR_SALE_DAY, SVR_VOID_DAY])->sum('sale_payments.amount_minor');

        // The cash went into the till and came back out of it.
        expect($received)->toBe(0);
    });
});
