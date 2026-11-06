<?php

declare(strict_types=1);

/*
| The report screens: the old (app)/reports pages. Each figure checked here is
| the service's own answer for the same filter, as the page renders it.
*/

use App\Services\CatalogService;
use App\Services\CustomerService;
use App\Services\Reporting\FinancialService;
use App\Services\Reporting\OperationsService;
use App\Services\Reporting\TaxReturnService;
use App\Services\Reporting\YearEndService;
use App\Support\Clock;
use App\Support\Filters\ListFilters;
use App\View\Format;
use Database\Seeders\DemoSeeder;

const REPORT_ROUTES = ['/reports', '/reports/profit-and-loss', '/reports/balance-sheet', '/reports/cash-flow', '/reports/sales', '/reports/purchases', '/reports/inventory', '/reports/tax', '/reports/year-end'];

beforeEach(function () {
    $this->ownerId = owner();
});

it('refuses every report without the reports right', function () {
    $staff = staff(['sales' => viewOnly()]);
    $viewer = staff(['reports' => viewOnly()], 'kofi');
    foreach (REPORT_ROUTES as $route) {
        signedIn($staff)->get($route)->assertRedirect('/no-access?area=reports');
        signedIn($viewer)->get($route)->assertOk();
    }
});

it('renders every report over an empty shop', function () {
    foreach (REPORT_ROUTES as $route) {
        signedIn($this->ownerId)->get($route)->assertOk();
    }
});

describe('over the demo shop', function () {
    beforeEach(function () {
        (new DemoSeeder)->seedDemo();
        $this->all = ListFilters::parseReportPeriod(['period' => 'all'])['range'];
    });

    it('shows the headline figures on the reports page', function () {
        $sheet = FinancialService::getBalanceSheet(Clock::today());
        signedIn($this->ownerId)->get('/reports')->assertOk()
            ->assertSee('Every figure here is calculated from your actual records')
            ->assertSee(Format::money($sheet['totalEquity']))
            ->assertSee('Year-end pack');
    });

    it('shows the profit and loss the service works out', function () {
        $pl = FinancialService::getProfitAndLoss($this->all);
        expect($pl['netSales'])->toBeGreaterThan(0);
        signedIn($this->ownerId)->get('/reports/profit-and-loss?period=all')->assertOk()
            ->assertSee(Format::money($pl['netProfit']))
            ->assertSee(Format::money($pl['netSales']))
            ->assertSee('Everything up to');
    });

    it('shows the balance sheet as at a date, and today for junk', function () {
        $sheet = FinancialService::getBalanceSheet(Clock::today());
        $html = signedIn($this->ownerId)->get('/reports/balance-sheet?asAt=junk')->assertOk()
            ->assertSee(Format::money($sheet['totalAssets']))->getContent();
        expect($html)->toContain('name="asAt"')->toContain('value="'.Clock::today().'"');
    });

    it('shows the cash flow for one account', function () {
        $flow = FinancialService::getCashFlow($this->all, 1);
        $html = signedIn($this->ownerId)->get('/reports/cash-flow?period=all&account=1')->assertOk()
            ->assertSee(Format::money($flow['closingBalance']))->getContent();
        expect($html)->toMatch('/<option value="1"\s+selected/');
    });

    it('shows sales by product, and the line view under a product filter', function () {
        $filters = ListFilters::parseSalesReportFilters(['period' => 'all'])['filters'];
        $product = OperationsService::getSalesByProduct($filters)[0];
        signedIn($this->ownerId)->get('/reports/sales?period=all')->assertOk()
            ->assertSee($product['productName'])
            ->assertSee(Format::money($product['revenue'], bare: true))
            ->assertSee('By payment method');

        $html = signedIn($this->ownerId)->get('/reports/sales?period=all&product='.$product['productId'])->assertOk()
            ->assertSee('Product revenue (net)')
            ->assertSee('Not shown while a product or category filter is on.')->getContent();
        expect($html)->toMatch('/<option value="'.$product['productId'].'"\s+selected/');
    });

    it('shows purchases by supplier', function () {
        $filters = ListFilters::parsePurchaseReportFilters(['period' => 'all'])['filters'];
        $supplier = OperationsService::getPurchasesBySupplier($filters)[0];
        signedIn($this->ownerId)->get('/reports/purchases?period=all')->assertOk()
            ->assertSee($supplier['supplierName'])
            ->assertSee(Format::money($supplier['total'], bare: true));
    });

    it('shows the stock valuation, filtered and resolved', function () {
        $valuation = OperationsService::getStockValuation();
        signedIn($this->ownerId)->get('/reports/inventory')->assertOk()
            ->assertSee(Format::money($valuation['totalCostValue']))
            ->assertDontSee('Stock value does not match the accounts');

        $low = OperationsService::getStockValuation(['stockStatus' => 'low']);
        $html = signedIn($this->ownerId)->get('/reports/inventory?stock=low&category=junk')->assertOk()
            ->assertSee(Format::money($low['totalCostValue']))
            ->assertSee('Both tables below cover only the products matching these filters')->getContent();
        expect($html)->toMatch('/<option value="low"\s+selected/');
    });

    it('shows the tax return', function () {
        $tax = TaxReturnService::getTaxReturn($this->all);
        signedIn($this->ownerId)->get('/reports/tax?period=all')->assertOk()
            ->assertSee('Tax you charged')
            ->assertSee(Format::money($tax['totalOutput']));
    });

    it('shows the year-end pack for the chosen year', function () {
        $year = YearEndService::availableFinancialYears()[0];
        $pack = YearEndService::getYearEndPack($year->startYear);
        signedIn($this->ownerId)->get('/reports/year-end?year='.$year->startYear)->assertOk()
            ->assertSee("Financial statements for the year {$year->label}")
            ->assertSee(Format::moneyAccounting($pack['profitAndLoss']['netProfit'], currencyCode: $pack['shop']['currencyCode']))
            ->assertSee('Checks performed');
    });

    it('carries the resolved dates to the CSV', function () {
        $html = signedIn($this->ownerId)->get('/reports/profit-and-loss?period=custom&from=junk')->assertOk()->getContent();
        $monthStart = substr(Clock::today(), 0, 7).'-01';
        expect($html)->toContain('value="'.$monthStart.'"')
            ->toContain('/export/report/profit-and-loss?period=custom&amp;from='.$monthStart);
    });

    it('lists the option names the services hold', function () {
        $customer = CustomerService::listCustomerOptions(true)[0];
        $category = CatalogService::listCategories()[0];
        signedIn($this->ownerId)->get('/reports/sales')->assertOk()->assertSee($customer['name'])->assertSee($category->name);
    });
});
