<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/sale-profit-tax.test.ts: tax is not
| profit, on every screen that shows a sale's profit.
| The P&L and the by-customer and by-day reports are the real reports.
| Nothing is deferred.
*/

use App\Services\Reporting\FinancialService;
use App\Services\Reporting\OperationsService;
use App\Services\SaleService;
use Illuminate\Support\Carbon;
use Tests\Support\Shop;

const SPT_DAY = '2026-08-10';
const SPT_PERIOD = ['from' => '2026-08-01', 'to' => '2026-08-31'];

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->customer = Shop::customer($this->actor, 'Mensah Provisions');
    $this->stocked = function (string $name, int $cost, int $price, string $unit, int $units, int $value): int {
        $id = Shop::product($this->actor, $name, $cost, $price, ['unit' => $unit]);
        Shop::openingStock($this->actor, $id, $units * 1000, $value, '2026-08-01');

        return $id;
    };
    $this->sellOne = fn (int $product, int $paid, ?int $customerId = null): array => SaleService::createSale([
        'businessDate' => SPT_DAY,
        ...($customerId === null ? [] : ['customerId' => $customerId]),
        'items' => [['productId' => $product, 'qty' => 1_000]],
        'tenders' => [['paymentAccountId' => $this->cash, 'amount' => $paid]],
    ], $this->actor);
    // Sells at GHS 120.00 tax included, of which 20.00 is tax, for goods costing 80.00.
    $this->taxedSale = function (?int $customerId = null): array {
        Shop::setSingleTax(2_000, true);

        return ($this->sellOne)(($this->stocked)('Rice 5kg', 8_000, 12_000, 'bag', 10, 80_000), 12_000, $customerId);
    };
});

describe('profit on a taxed sale', function () {
    it('matches the Profit & Loss on the sales list', function () {
        ($this->taxedSale)();

        expect(SaleService::listSales()[0]['profitMinor'])->toBe(2_000)->and(FinancialService::getProfitAndLoss(SPT_PERIOD)['grossProfit'])->toBe(2_000);
    });

    it('matches the Profit & Loss in the totals above the list', function () {
        ($this->taxedSale)();

        expect(SaleService::getFilteredSalesSummary()['grossProfit'])->toBe(2_000)
            ->and(SaleService::getSalesSummary(SPT_DAY, SPT_DAY)['grossProfit'])->toBe(2_000);
    });

    it('is what the sale page shows', function () {
        $sale = ($this->taxedSale)();

        expect(SaleService::getSale($sale['saleId'])['profitMinor'])->toBe(2_000);
    });

    it('agrees between the by-customer and by-day reports', function () {
        ($this->taxedSale)($this->customer);

        $byCustomer = OperationsService::getSalesByCustomer(SPT_PERIOD)[0];
        $byDay = OperationsService::getSalesByDay(SPT_PERIOD)[0];
        expect($byCustomer['profit'])->toBe(2_000)->and($byCustomer['profit'])->toBe($byDay['profit']);
    });

    it('still shows what the customer paid as the takings', function () {
        ($this->taxedSale)($this->customer);

        expect(OperationsService::getSalesByCustomer(SPT_PERIOD)[0]['total'])->toBe(12_000)
            ->and(SaleService::getFilteredSalesSummary()['revenue'])->toBe(12_000);
    });

    it('nets to nothing once the sale is voided', function () {
        $sale = ($this->taxedSale)();
        SaleService::voidSale($sale['saleId'], 'rung up by mistake', $this->actor, Carbon::parse('2026-08-11 10:00:00', 'UTC'));

        expect(SaleService::getFilteredSalesSummary()['grossProfit'])->toBe(0)
            ->and(SaleService::getSalesSummary(SPT_PERIOD['from'], SPT_PERIOD['to'])['grossProfit'])->toBe(0)
            ->and(OperationsService::getSalesByCustomer(SPT_PERIOD)[0]['profit'])->toBe(0);
    });

    it('sorts by the profit shown, not by the tax on top of it', function () {
        ($this->taxedSale)();
        Shop::setNoTax();
        ($this->sellOne)(($this->stocked)('Salt', 100, 3_100, 'pack', 5, 500), 3_100);

        expect(array_column(SaleService::listSales(['sort' => 'profit', 'direction' => 'desc']), 'profitMinor'))->toBe([3_000, 2_000]);
    });
});

describe('profit on an untaxed sale', function () {
    it('is unchanged', function () {
        Shop::setNoTax();
        ($this->sellOne)(($this->stocked)('Rice 5kg', 8_000, 12_000, 'bag', 10, 80_000), 12_000);

        expect(SaleService::listSales()[0]['profitMinor'])->toBe(4_000);
    });
});
