<?php

declare(strict_types=1);

/*
| The owner's decisions D4 and D6 to D11 (PLAN.md §10, approved 2026-11-06):
| report figures the old app got wrong, each with the example the plan gave.
| D5, the dashboard windows, is in tests/Domain/DashboardTest.php.
*/

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Domain\Errors\ValidationError;
use App\Enums\JournalSourceType;
use App\Livewire\Purchases\Create;
use App\Models\User;
use App\Reports\Csv\ReportExport;
use App\Services\JournalService;
use App\Services\PaymentAccountService;
use App\Services\PurchaseService;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\FinancialService;
use App\Services\Reporting\LedgerService;
use App\Services\Reporting\MoneyTrend;
use App\Services\Reporting\OperationsService;
use App\Services\Reporting\TaxReturnService;
use App\Services\SaleService;
use App\Services\TransferService;
use App\Services\YearEndCloseService;
use App\Support\Filters\Filters;
use App\Support\Filters\ListFilters;
use App\View\Format;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Shop;

function rdPost(string $date, string $debitCode, string $creditCode, int $amount): void
{
    Tx::run(fn () => JournalService::post($date, JournalSourceType::OpeningBalance, [
        Journal::debit(Shop::accountId($debitCode), $amount),
        Journal::credit(Shop::accountId($creditCode), $amount),
    ], memo: 'test entry', isOpening: true));
}

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('D4: balances leaving out the closing entries', function () {
    it('leaves the closing entries out of all-time balances when no dates are given', function () {
        Carbon::setTestNow('2027-06-15 10:00:00');
        // 2025 sold 10,000.00, then closed: the close moves it all to retained earnings.
        rdPost('2025-03-01', '1001', ChartOfAccounts::SALES_REVENUE, 1_000_000);
        YearEndCloseService::closeFinancialYear(2025, $this->actor);

        expect(BalancesService::getAccountBalanceByCode(ChartOfAccounts::SALES_REVENUE))->toBe(0)
            ->and(BalancesService::getAccountBalanceByCode(ChartOfAccounts::SALES_REVENUE, ['excludeClosing' => true]))->toBe(1_000_000)
            ->and(BalancesService::getAccountBalanceByCode(ChartOfAccounts::SALES_REVENUE, ['from' => '2025-01-01', 'to' => '2025-12-31', 'excludeClosing' => true]))->toBe(1_000_000);
    });
});

describe('D6: the money trend and transfers', function () {
    it('leaves a transfer between the shop\'s own accounts out of the money trend, as the cash flow does', function () {
        Shop::capital($this->actor, $this->cash, 500_000, '2026-03-02');
        Shop::expense($this->actor, '2026-03-10', PaymentAccountService::listExpenseCategories()[0]['id'], 1_500);
        $moved = TransferService::recordTransfer(['businessDate' => '2026-03-15', 'fromAccountId' => $this->cash, 'toAccountId' => Shop::paymentAccount('BANK'), 'amount' => 200_000], $this->actor);

        $check = function (): void {
            $march = MoneyTrend::getMoneyByMonth('2026-03-31', 1)[0];
            $flow = FinancialService::getCashFlow(['from' => '2026-03-01', 'to' => '2026-03-31']);
            expect($march['inMinor'])->toBe(500_000)->and($march['outMinor'])->toBe(1_500)
                ->and($march['inMinor'])->toBe($flow['totalIn'])
                ->and($march['outMinor'])->toBe($flow['totalOut']);
        };
        $check();
        // Voiding the transfer in the same month moves nothing either.
        TransferService::voidTransfer($moved['id'], 'Wrong account', $this->actor, Carbon::parse('2026-03-20 09:00:00'));
        $check();
    });
});

describe('D7: purchases by product across a unit change', function () {
    it('gives one row per unit bought in, not a sum across units', function () {
        $supplier = Shop::supplier($this->actor);
        $milo = Shop::product($this->actor, 'Milo 400g', 12_000, 15_000, ['unit' => 'carton']);
        Shop::purchase($this->actor, $supplier, [[$milo, 5_000, 12_000]], extra: ['businessDate' => '2026-08-05']);
        // The old app let a product's unit change after deliveries; imported books can hold both.
        DB::table('products')->where('id', $milo)->update(['unit' => 'pcs']);
        Shop::purchase($this->actor, $supplier, [[$milo, 10_000, 500]], extra: ['businessDate' => '2026-08-20']);

        $rows = OperationsService::getPurchasesByProduct(['from' => '2026-08-01', 'to' => '2026-08-31']);
        expect(array_map(fn (array $r): array => [$r['unit'], $r['qtyBought'], $r['total']], $rows))->toBe([
            ['carton', 5_000, 60_000],
            ['pcs', 10_000, 5_000],
        ])->and(array_unique(array_column($rows, 'productName')))->toBe(['Milo 400g']);

        $csv = ReportExport::download('purchases', ['from' => '2026-08-01', 'to' => '2026-08-31'], '2026-08-31')['rows'];
        $byProduct = array_values(array_filter($csv, fn (array $r): bool => $r[0] === 'By product'));
        expect(array_map(fn (array $r): array => [$r[1], $r[2]], $byProduct))->toBe([['Milo 400g (carton)', '5'], ['Milo 400g (pcs)', '10']]);
    });
});

describe('D8: purchases carry a due date and payables age from it', function () {
    beforeEach(function () {
        $this->supplier = Shop::supplier($this->actor);
        $this->rice = Shop::product($this->actor, 'Rice 5kg');
    });

    it('shows a 60-day-terms delivery received 45 days ago as current, not overdue', function () {
        $p = Shop::purchase($this->actor, $this->supplier, [[$this->rice, 10_000, 1_000]], extra: ['businessDate' => '2026-08-01', 'dueDate' => '2026-09-30']);
        expect(DB::table('purchases')->where('id', $p['purchaseId'])->value('due_date'))->toBe('2026-09-30')
            ->and(PurchaseService::getPurchase($p['purchaseId'])['dueDate'])->toBe('2026-09-30');

        $row = LedgerService::getPayablesAgeing('2026-09-15')[0];
        expect($row['current'])->toBe(10_000)->and($row['days31to60'])->toBe(0)->and($row['oldestDate'])->toBe('2026-09-30');
        // A fortnight after it fell due, it is overdue by the due date, not the delivery date.
        expect(LedgerService::getPayablesAgeing('2026-10-14')[0]['days1to30'])->toBe(10_000);
    });

    it('heads the payables screen "Oldest due" and says ages run from the due date, else the delivery date', function () {
        Shop::purchase($this->actor, $this->supplier, [[$this->rice, 10_000, 1_000]], extra: ['businessDate' => '2026-08-01', 'dueDate' => '2026-09-30']);
        signedIn($this->actor->id)->get('/accounting/payables?asAt=2026-09-15')->assertOk()
            ->assertSee('Oldest due')->assertDontSee('Oldest delivery')
            ->assertSee('from the day each purchase falls due')->assertSee('A purchase with no due date ages from the day it was delivered.');
    });

    it('ages a purchase with no due date from its purchase date, as before', function () {
        $p = Shop::purchase($this->actor, $this->supplier, [[$this->rice, 10_000, 1_000]], extra: ['businessDate' => '2026-08-01']);
        expect(DB::table('purchases')->where('id', $p['purchaseId'])->value('due_date'))->toBeNull();

        $row = LedgerService::getPayablesAgeing('2026-09-15')[0];
        expect($row['days31to60'])->toBe(10_000)->and($row['current'])->toBe(0)->and($row['oldestDate'])->toBe('2026-08-01');
    });

    it('holds a due date to the purchase date plus 0 to 365 days, and says so in those words', function (string $due, ?string $message) {
        $buy = fn () => Shop::purchase($this->actor, $this->supplier, [[$this->rice, 1_000, 1_000]], extra: ['businessDate' => '2026-08-01', 'dueDate' => $due]);
        $message === null ? expect($buy()['purchaseId'])->toBeInt() : expect($buy)->toThrow(ValidationError::class, $message);
    })->with([
        'the same day' => ['2026-08-01', null],
        'a year on' => ['2027-08-01', null],
        'before the delivery' => ['2026-07-31', 'The due date must be from the purchase date up to a year after it.'],
        'over a year on' => ['2027-08-02', 'The due date must be from the purchase date up to a year after it.'],
        'not a date' => ['2026-02-30', 'The due date "2026-02-30" is not a real date (YYYY-MM-DD).'],
    ]);

    it('takes the due date from the purchase form, optional, and shows it on the purchase', function () {
        $owner = User::query()->findOrFail($this->actor->id);
        $page = Livewire::actingAs($owner)->test(Create::class)
            ->assertSee('Due date')
            ->set('supplierId', (string) $this->supplier)->set('businessDate', '2026-08-01')->set('dueDate', '2026-09-30')
            ->set('rows.0.productId', (string) $this->rice)->set('rows.0.qty', '2')
            ->call('save')->assertHasNoErrors()->assertSet('dueDate', '');
        $id = $page->get('savedId');
        expect(DB::table('purchases')->where('id', $id)->value('due_date'))->toBe('2026-09-30');
        signedIn($this->actor->id)->get("/purchases/{$id}")->assertOk()->assertSee('Due '.Format::date('2026-09-30'));

        $none = Livewire::actingAs($owner)->test(Create::class)
            ->set('supplierId', (string) $this->supplier)->set('businessDate', '2026-08-01')
            ->set('rows.0.productId', (string) $this->rice)->set('rows.0.qty', '1')
            ->call('save')->assertHasNoErrors();
        expect(DB::table('purchases')->where('id', $none->get('savedId'))->value('due_date'))->toBeNull();

        Livewire::actingAs($owner)->test(Create::class)
            ->set('supplierId', (string) $this->supplier)->set('businessDate', '2026-08-01')->set('dueDate', '2026-07-01')
            ->set('rows.0.productId', (string) $this->rice)->set('rows.0.qty', '1')
            ->call('save')->assertHasErrors(['form' => 'The due date must be from the purchase date up to a year after it.']);
    });
});

describe('D9: the taxable-sales document count', function () {
    it('counts only standing sales: one sale of 100.00 and a void of another of 50.00 is 100.00 over 1 sale', function () {
        Carbon::setTestNow('2026-08-20 10:00:00');
        $rice = Shop::product($this->actor, 'Rice 5kg', 1_000, 10_000);
        $oil = Shop::product($this->actor, 'Palm oil', 1_000, 5_000);
        Shop::openingStock($this->actor, $rice, 10_000, 10_000, '2026-08-01');
        Shop::openingStock($this->actor, $oil, 10_000, 10_000, '2026-08-01');
        $pay = fn (int $amount): array => [['paymentAccountId' => $this->cash, 'amount' => $amount]];
        Shop::sell($this->actor, [[$rice, 1_000]], $pay(10_000), ['businessDate' => '2026-08-10']);
        $other = Shop::sell($this->actor, [[$oil, 1_000]], $pay(5_000), ['businessDate' => '2026-08-12']);
        SaleService::voidSale($other['saleId'], 'Rung up twice', $this->actor, Carbon::parse('2026-08-15 09:00:00'));

        $tax = TaxReturnService::getTaxReturn(['from' => '2026-08-01', 'to' => '2026-08-31']);
        expect($tax['taxableSalesMinor'])->toBe(10_000)->and($tax['saleCount'])->toBe(1);
    });

    it('counts a sale in its own period when its void is dated in a later one, and the void counts nowhere', function () {
        $rice = Shop::product($this->actor, 'Rice 5kg', 1_000, 10_000);
        Shop::openingStock($this->actor, $rice, 10_000, 10_000, '2026-03-01');
        $sale = Shop::sell($this->actor, [[$rice, 1_000]], [['paymentAccountId' => $this->cash, 'amount' => 10_000]], ['businessDate' => '2026-03-10']);
        $march = fn (): array => TaxReturnService::getTaxReturn(['from' => '2026-03-01', 'to' => '2026-03-31']);
        $before = $march();
        SaleService::voidSale($sale['saleId'], 'Rung up twice', $this->actor, Carbon::parse('2026-04-05 09:00:00'));

        $april = TaxReturnService::getTaxReturn(['from' => '2026-04-01', 'to' => '2026-04-30']);
        expect($march()['taxableSalesMinor'])->toBe(10_000)->and($march()['saleCount'])->toBe(1)->and($march())->toBe($before)
            ->and($april['taxableSalesMinor'])->toBe(-10_000)->and($april['saleCount'])->toBe(0)
            // Over both months the void falls inside the period: nothing stands.
            ->and(TaxReturnService::getTaxReturn(['from' => '2026-03-01', 'to' => '2026-04-30'])['saleCount'])->toBe(0);
    });

    it('counts purchases the same way: a void in the same period removes one, a later void does not', function () {
        $supplier = Shop::supplier($this->actor);
        $rice = Shop::product($this->actor, 'Rice 5kg');
        $kept = Shop::purchase($this->actor, $supplier, [[$rice, 1_000, 1_000]], extra: ['businessDate' => '2026-03-10']);
        $sameMonth = Shop::purchase($this->actor, $supplier, [[$rice, 1_000, 2_000]], extra: ['businessDate' => '2026-03-11']);
        PurchaseService::voidPurchase($sameMonth['purchaseId'], 'Entered twice', $this->actor, Carbon::parse('2026-03-20 09:00:00'));
        PurchaseService::voidPurchase($kept['purchaseId'], 'Sent back unopened', $this->actor, Carbon::parse('2026-04-05 09:00:00'));

        $march = TaxReturnService::getTaxReturn(['from' => '2026-03-01', 'to' => '2026-03-31']);
        $april = TaxReturnService::getTaxReturn(['from' => '2026-04-01', 'to' => '2026-04-30']);
        expect($march['taxablePurchasesMinor'])->toBe(1_000)->and($march['purchaseCount'])->toBe(1)
            ->and($april['taxablePurchasesMinor'])->toBe(-1_000)->and($april['purchaseCount'])->toBe(0);
    });
});

describe('D10: report dates', function () {
    it('swaps a from after the to, after an invalid from has fallen back', function () {
        expect(ListFilters::parseReportDates(['from' => '2026-03-31', 'to' => '2026-01-01'], '2026-11-06'))->toBe(['from' => '2026-01-01', 'to' => '2026-03-31'])
            ->and(ListFilters::parseReportDates(['from' => 'junk'], '2026-11-06'))->toBe(['from' => '2026-11-01', 'to' => '2026-11-06'])
            ->and(ListFilters::parseReportDates([], '2026-11-06'))->toBe(['from' => '2026-11-01', 'to' => '2026-11-06'])
            ->and(ReportExport::download('tax', ['from' => '2026-03-31', 'to' => '2026-01-01'], '2026-11-06')['filename'])->toBe('bookkeeper_tax_2026-01-01_2026-03-31.csv');
    });

    it('resolves a typed to with no from exactly as the page does', function () {
        $params = ['period' => 'custom', 'to' => '2026-01-31'];
        $page = ListFilters::parseReportPeriod($params, '2026-11-06')['range'];
        expect(ListFilters::parseReportDates($params, '2026-11-06'))->toBe($page)
            ->and(ListFilters::parseReportDates(['to' => '2026-01-31'], '2026-11-06'))->toBe($page)
            ->and($page)->toBe(['from' => Filters::EARLIEST_DATE, 'to' => '2026-01-31'])
            ->and(ListFilters::parseReportDates(['from' => '2026-02-30', 'to' => '2026-01-31'], '2026-11-06'))->toBe(['from' => Filters::EARLIEST_DATE, 'to' => '2026-01-31']);
    });

    it('shows the resolved, swapped dates in the report\'s date controls', function () {
        $html = signedIn($this->actor->id)->get('/reports/tax?period=custom&from=2026-03-31&to=2026-01-01')->assertOk()->getContent();
        expect($html)->toMatch('/name="from"[^>]*value="2026-01-01"|value="2026-01-01"[^>]*name="from"/')
            ->and($html)->toMatch('/name="to"[^>]*value="2026-03-31"|value="2026-03-31"[^>]*name="to"/');
    });

    it('gives the sales file the page\'s dates, not a period re-read against its own end date', function () {
        $rice = Shop::product($this->actor, 'Rice 5kg', 1_000, 10_000);
        Shop::openingStock($this->actor, $rice, 10_000, 10_000, '2026-09-01');
        foreach (['2026-09-15', '2026-10-15'] as $date) {
            Shop::sell($this->actor, [[$rice, 1_000]], [['paymentAccountId' => $this->cash, 'amount' => 10_000]], ['businessDate' => $date]);
        }
        // The page's CSV button sends the carried period AND the resolved dates: on 6 November, "last month" is October.
        $rows = ReportExport::download('sales', ['period' => 'last-month', 'from' => '2026-10-01', 'to' => '2026-10-31'], '2026-11-06')['rows'];
        $days = array_column(array_values(array_filter($rows, fn (array $r): bool => $r[0] === 'By day')), 1);
        expect($days)->toBe(['2026-10-15']);
    });
});

describe('D11: margins in exact arithmetic', function () {
    it('gives a loss of 1.00 on sales of 20,000.00 a margin of -1 bp, half away from zero', function () {
        rdPost('2026-08-05', '1001', ChartOfAccounts::SALES_REVENUE, 2_000_000);
        rdPost('2026-08-06', '6010', '1001', 2_000_100);

        $pl = FinancialService::getProfitAndLoss(['from' => '2026-08-01', 'to' => '2026-08-31']);
        expect($pl['netProfit'])->toBe(-100)->and($pl['netMarginBp'])->toBe(-1)->and($pl['grossMarginBp'])->toBe(10_000);
    });

    it('gives a product sold at a 1.00 loss on 20,000.00 a margin of -1 bp', function () {
        $tv = Shop::product($this->actor, 'Television', 2_000_100, 2_000_000);
        Shop::openingStock($this->actor, $tv, 1_000, 2_000_100, '2026-08-01');
        Shop::sell($this->actor, [[$tv, 1_000]], [['paymentAccountId' => $this->cash, 'amount' => 2_000_000]], ['businessDate' => '2026-08-10']);

        $row = OperationsService::getSalesByProduct(['from' => '2026-08-01', 'to' => '2026-08-31'])[0];
        expect($row['profit'])->toBe(-100)->and($row['marginBp'])->toBe(-1);
    });
});
