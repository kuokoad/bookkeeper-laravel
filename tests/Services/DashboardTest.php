<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/dashboard.test.tsx: the figures behind
| the dashboard, read through the real services (P&L, receivables ageing
| with Dashboard::summariseAgeing, the expense trend), plus the money-by-month
| chart and top product the dashboard draws.
|
| Deferred to Phase 9 (the dashboard screen): every case that rendered the
| page — "renders authoritative P&L ..." keeps only its figure half here; its
| card and link half, "Outside invoice ageing", "shows opposing reconciliation
| discrepancies ...", and the whole "dashboard server permissions" block
| (layout, permission gating, links, redirects, "reads do not change journal
| lines or inventory", failure propagation).
*/

use App\Domain\BusinessDate;
use App\Domain\Dashboard;
use App\Domain\Money;
use App\Services\CashbookService;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\PaymentAccountService;
use App\Services\ReconciliationService;
use App\Services\Reporting\FinancialService;
use App\Services\Reporting\LedgerService;
use App\Services\Reporting\MoneyTrend;
use App\Services\Reporting\OperationsService;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner('owner');
    $this->cash = Shop::paymentAccount('CASH');
    $this->momo = Shop::paymentAccount('MOBILE_MONEY');
    $this->today = Clock::today();
    $this->period = ['from' => substr($this->today, 0, 7).'-01', 'to' => $this->today];
    $this->product = Shop::product($this->actor, 'Dashboard rice', 500, 1000);
    Shop::openingStock($this->actor, $this->product, 100_000, 50_000, $this->period['from']);
    $this->expenseCategory = PaymentAccountService::listExpenseCategories()[0]['id'];
});

describe('dashboard financial regression', function () {
    it('renders authoritative P&L including shrinkage and adjusted cash differences', function () {
        Shop::sell($this->actor, [[$this->product, 10_000]], [['paymentAccountId' => $this->cash, 'amount' => 10_000]], ['businessDate' => $this->today]);
        Shop::expense($this->actor, $this->today, $this->expenseCategory, 200, 'Dashboard expense');
        Shop::stockOut($this->actor, $this->product, 2_000, $this->today);
        ReconciliationService::createReconciliation(['businessDate' => $this->today, 'paymentAccountId' => $this->cash, 'actual' => 9_700, 'adjust' => true, 'explanation' => 'Test cash short'], $this->actor);

        // 5000 gross - 200 cashbook - 1000 shrinkage - 100 cash short
        expect(FinancialService::getProfitAndLoss($this->period)['netProfit'])->toBe(3_700);
    });

    it('assigns invoices to exactly one bucket, including all overdue boundaries', function () {
        $customer = Shop::customer($this->actor, 'Ageing customer');
        foreach ([-1, 0, 1, 30, 31, 60, 61, 90, 91] as $days) {
            $sale = Shop::sell($this->actor, [[$this->product, 1_000]], [], ['businessDate' => $this->today, 'customerId' => $customer]);
            DB::table('sales')->where('id', $sale['saleId'])->update(['due_date' => BusinessDate::addDays($this->today, -$days)]);
        }
        $rows = LedgerService::getReceivablesAgeing($this->today);
        expect($rows[0])->toMatchArray(['current' => 2_000, 'days1to30' => 2_000, 'days31to60' => 2_000, 'days61to90' => 2_000, 'over90' => 1_000, 'total' => 9_000]);

        $result = Dashboard::summariseAgeing($rows, CustomerService::getTotalReceivables());
        expect($result)->toMatchArray(['overdue' => 7_000, 'notYetDue' => 2_000, 'unaged' => 0, 'reconciles' => true])
            ->and($result['overdue'] + $result['notYetDue'])->toBe(CustomerService::getTotalReceivables());
    });

    it('exposes unallocated payments rather than silently assigning due dates', function () {
        $customer = Shop::customer($this->actor, 'Credit customer');
        Shop::setting('allow_overpayment', true);
        CustomerPaymentService::recordCustomerPayment(['businessDate' => $this->today, 'customerId' => $customer, 'paymentAccountId' => $this->cash, 'amount' => 500], $this->actor);

        $result = Dashboard::summariseAgeing(LedgerService::getReceivablesAgeing($this->today), CustomerService::getTotalReceivables());
        expect($result['overdue'] + $result['notYetDue'] + $result['unaged'])->toBe(-500)
            ->and($result['unaged'])->toBe(-500);
    });

    it('expense trend matches total, includes boundaries and excludes voids', function () {
        [$from, $to] = ['2026-03-01', '2026-03-31'];
        foreach (['2026-02-28', $from, $to, '2026-04-01'] as $date) {
            Shop::expense($this->actor, $date, $this->expenseCategory, 100, 'Boundary expense');
        }
        $voided = Shop::expense($this->actor, $from, $this->expenseCategory, 999, 'Voided expense');
        // Voided inside the period: one voided after it would still stand in March, as the ledger has it.
        CashbookService::voidExpense($voided['id'], 'Test correction', $this->actor, Carbon::parse($from.' 15:00', 'Africa/Accra'));

        $rows = CashbookService::getExpensesByDay($from, $to);
        expect(array_column($rows, 'businessDate'))->toBe([$from, $to])
            ->and(Money::sum(array_column($rows, 'total')))->toBe(CashbookService::getExpensesTotal($from, $to))
            ->and(CashbookService::getExpensesTotal($from, $to))->toBe(200);
    });
});

describe('the dashboard charts', function () {
    it('gives money in and out per month in one read, oldest first, empty months as zero', function () {
        Shop::capital($this->actor, $this->cash, 20_000, '2026-03-05');
        Shop::expense($this->actor, '2026-05-31', $this->expenseCategory, 700);
        Shop::expense($this->actor, '2026-06-01', $this->expenseCategory, 300);

        $months = MoneyTrend::getMoneyByMonth('2026-05-15', 3);
        expect($months)->toBe([
            ['month' => '2026-03', 'label' => 'Mar', 'inMinor' => 20_000, 'outMinor' => 0],
            ['month' => '2026-04', 'label' => 'Apr', 'inMinor' => 0, 'outMinor' => 0],
            ['month' => '2026-05', 'label' => 'May', 'inMinor' => 0, 'outMinor' => 700],
        ]);
    });

    it('counts across a year boundary by month index', function () {
        expect(array_column(MoneyTrend::getMoneyByMonth('2026-02-10', 4), 'month'))->toBe(['2025-11', '2025-12', '2026-01', '2026-02']);
    });

    it('names the top product the same way as the sales report', function () {
        Shop::sell($this->actor, [[$this->product, 3_000]], [['paymentAccountId' => $this->cash, 'amount' => 3_000]], ['businessDate' => $this->today]);
        $top = OperationsService::getTopProductByRevenue($this->period);
        expect($top)->toMatchArray(['productId' => $this->product, 'qtySold' => 3_000, 'revenue' => 3_000])
            ->and($top['revenue'])->toBe(OperationsService::getSalesByProduct($this->period)[0]['revenue']);
    });

    it('takes the sales chart from the same rows as the sales-by-day report', function () {
        Shop::sell($this->actor, [[$this->product, 2_000]], [['paymentAccountId' => $this->cash, 'amount' => 2_000]], ['businessDate' => $this->today]);
        $byDay = OperationsService::getSalesByDay($this->period);
        $series = Dashboard::dashboardDailySeries($byDay, $this->period);
        expect(Money::sum(array_column($series, 'value')))->toBe(2_000)
            ->and(count($series))->toBe(count(range(0, BusinessDate::daysBetween($this->period['from'], $this->period['to']))));
    });
});
