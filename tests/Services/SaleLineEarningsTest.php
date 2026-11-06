<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/sale-line-earnings.test.ts: what a line
| of a sale earned, net of its receipt's tax and discount.
|
| Read through the real reports: OperationsService (by product, category,
| day and customer, and the top product) and the P&L. The whole old file is
| ported; nothing is deferred.
*/

use App\Domain\Money;
use App\Enums\AdjustmentReason;
use App\Services\CatalogService;
use App\Services\Reporting\FinancialService;
use App\Services\Reporting\OperationsService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use Illuminate\Support\Carbon;
use Tests\Support\Shop;

const SLE_DAY = '2026-08-10';
const SLE_AUGUST = ['from' => '2026-08-01', 'to' => '2026-08-31'];

/** The line table a key names, from the real report. */
function sleTally(array $query, string $key): array
{
    return match ($key) {
        'productId' => OperationsService::getSalesByProduct($query),
        'categoryId' => OperationsService::getSalesByCategory($query),
        'day' => OperationsService::getSalesLinesByDay($query),
        'customerId' => OperationsService::getSalesLinesByCustomer($query),
    };
}

function sleRevenueOf(int $productId): ?int
{
    foreach (sleTally(SLE_AUGUST, 'productId') as $row) {
        if ($row['productId'] === $productId) {
            return $row['revenue'];
        }
    }

    return null;
}

function sleTopProduct(): ?array
{
    return OperationsService::getTopProductByRevenue(SLE_AUGUST);
}

function sleNetSales(): int
{
    return FinancialService::getProfitAndLoss(SLE_AUGUST)['netSales'];
}

function sleSum(array $rows, string $field): int
{
    return Money::sum(array_column($rows, $field));
}

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->customer = Shop::customer($this->actor, 'Mensah Provisions');
    $this->drinks = CatalogService::createCategory(['name' => 'Drinks'], $this->actor);
    $this->rice = Shop::product($this->actor, 'Rice 5kg', 8_000, 12_000, ['unit' => 'bag']);
    $this->coke = Shop::product($this->actor, 'Coca-Cola', 2_000, 3_000, ['categoryId' => $this->drinks]);
    $this->stock = fn (array $items) => StockAdjustmentService::createStockAdjustment(['businessDate' => '2026-08-01', 'reason' => AdjustmentReason::OpeningStock,
        'items' => array_map(fn (array $i): array => ['productId' => $i[0], 'direction' => 'IN', 'qty' => $i[1] * 1000, 'totalCost' => $i[2]], $items)], $this->actor);
    ($this->stock)([[$this->rice, 20, 160_000], [$this->coke, 20, 40_000]]);
    $this->sell = fn (array $lines, int $paid, ?int $invoiceDiscount = null): array => SaleService::createSale([
        'businessDate' => SLE_DAY,
        'customerId' => $this->customer,
        'items' => array_map(fn (array $l): array => ['productId' => $l[0], 'qty' => $l[1] * 1000], $lines),
        ...($invoiceDiscount === null ? [] : ['invoiceDiscount' => $invoiceDiscount, 'allowPriceOverride' => true]),
        'tenders' => [['paymentAccountId' => $this->cash, 'amount' => $paid]],
    ], $this->actor);
});

describe('line figures under tax-inclusive prices', function () {
    beforeEach(fn () => Shop::setSingleTax(2_000, true));

    it("takes the tax out of a product's revenue and profit", function () {
        ($this->sell)([[$this->rice, 1]], 12_000);

        $rice = sleTally(SLE_AUGUST, 'productId')[0];
        expect($rice['revenue'])->toBe(10_000)->and($rice['profit'])->toBe(2_000)->and($rice['marginBp'])->toBe(2_000);
    });

    it("shares a receipt's tax between its lines by value", function () {
        ($this->sell)([[$this->rice, 1], [$this->coke, 1]], 15_000);

        expect(sleRevenueOf($this->rice))->toBe(10_000)->and(sleRevenueOf($this->coke))->toBe(2_500);
    });

    it('agrees across every line table and with the Profit & Loss', function () {
        ($this->sell)([[$this->rice, 1], [$this->coke, 1]], 15_000);
        ($this->sell)([[$this->coke, 3]], 9_000);

        $net = sleNetSales();
        expect(sleSum(sleTally(SLE_AUGUST, 'productId'), 'revenue'))->toBe($net)
            ->and(sleSum(sleTally(SLE_AUGUST, 'categoryId'), 'revenue'))->toBe($net)
            ->and(sleSum(sleTally(SLE_AUGUST, 'day'), 'revenue'))->toBe($net)
            ->and(sleSum(sleTally(SLE_AUGUST, 'customerId'), 'revenue'))->toBe($net)
            ->and(sleSum(sleTally(SLE_AUGUST, 'productId'), 'profit'))->toBe(FinancialService::getProfitAndLoss(SLE_AUGUST)['grossProfit']);
    });

    it('keeps every pesewa when the shares do not divide evenly', function () {
        Shop::setSingleTax(2_190, true);
        $penny = Shop::product($this->actor, 'Sweets', 100, 333);
        $toffee = Shop::product($this->actor, 'Toffee', 100, 337);
        ($this->stock)([[$penny, 20, 2_000], [$toffee, 20, 2_000]]);
        for ($receipt = 0; $receipt < 7; $receipt++) {
            ($this->sell)([[$penny, 1], [$toffee, 1], [$this->coke, 1]], 3_670);
        }

        expect(sleSum(sleTally(SLE_AUGUST, 'productId'), 'revenue'))->toBe(sleNetSales());
    });

    it('applies to the by-day and by-customer tables under a product filter', function () {
        ($this->sell)([[$this->rice, 1], [$this->coke, 1]], 15_000);

        $filter = [...SLE_AUGUST, 'productId' => $this->coke];
        $day = sleTally($filter, 'day')[0];
        $customer = sleTally($filter, 'customerId')[0];
        expect($day['revenue'])->toBe(2_500)->and($day['profit'])->toBe(500)
            ->and($customer['revenue'])->toBe(2_500)->and($customer['profit'])->toBe(500);
    });

    it('applies to a category filter, and shares by the whole receipt', function () {
        ($this->sell)([[$this->rice, 1], [$this->coke, 1]], 15_000);

        $drinks = sleTally([...SLE_AUGUST, 'categoryId' => $this->drinks], 'categoryId')[0];
        expect($drinks['revenue'])->toBe(2_500)->and($drinks['profit'])->toBe(500);
    });

    it('names the same revenue on the dashboard card as on the report', function () {
        ($this->sell)([[$this->rice, 1], [$this->coke, 1]], 15_000);

        expect(sleTopProduct()['revenue'])->toBe(10_000)->and(sleRevenueOf($this->rice))->toBe(10_000);
    });

    it('nets a voided sale to nothing', function () {
        $sale = ($this->sell)([[$this->rice, 1], [$this->coke, 1]], 15_000);
        SaleService::voidSale($sale['saleId'], 'rung up by mistake', $this->actor, Carbon::parse('2026-08-11 10:00:00', 'UTC'));

        expect(sleRevenueOf($this->rice))->toBe(0)->and(sleRevenueOf($this->coke))->toBe(0);
    });

    it('nets a returned line to nothing, and leaves the other line alone', function () {
        $sale = ($this->sell)([[$this->rice, 1], [$this->coke, 1]], 15_000);
        $rice = collect(SaleService::getSale($sale['saleId'])['items'])->firstWhere('productId', $this->rice);
        ReturnsService::createCustomerReturn($sale['saleId'], [
            'businessDate' => SLE_DAY,
            'items' => [['itemId' => $rice['id'], 'qty' => 1_000]],
            'refunds' => [['paymentAccountId' => $this->cash, 'amount' => 12_000]],
        ], $this->actor);

        expect(sleRevenueOf($this->rice))->toBe(0)->and(sleRevenueOf($this->coke))->toBe(2_500);
    });
});

describe('the dashboard card after everything came back', function () {
    it('names no top seller, whatever pesewa the tax rounding leaves behind', function () {
        Shop::setGhanaTaxes(true);
        $tin = Shop::product($this->actor, 'Tin', 1_000, 2_555);
        $soap = Shop::product($this->actor, 'Soap', 300, 777);
        ($this->stock)([[$tin, 20, 20_000], [$soap, 20, 6_000]]);
        $sale = ($this->sell)([[$tin, 3], [$soap, 1]], 8_442);
        foreach (SaleService::getSale($sale['saleId'])['items'] as $item) {
            ReturnsService::createCustomerReturn($sale['saleId'], [
                'businessDate' => SLE_DAY,
                'items' => [['itemId' => $item['id'], 'qty' => $item['qtyMilli']]],
                'refunds' => [],
            ], $this->actor);
        }

        $leftover = array_column(sleTally(SLE_AUGUST, 'productId'), 'revenue');
        expect(collect($leftover)->contains(fn (int $r): bool => $r > 0))->toBeTrue()
            ->and(sleTopProduct())->toBeNull();
    });
});

describe('line figures under an invoice discount', function () {
    it('takes the discount out of each line by value, with tax on top', function () {
        Shop::setSingleTax(2_000, false);
        ($this->sell)([[$this->rice, 1], [$this->coke, 1]], 14_400, 3_000);

        expect(sleRevenueOf($this->rice))->toBe(9_600)
            ->and(sleRevenueOf($this->coke))->toBe(2_400)
            ->and(sleSum(sleTally(SLE_AUGUST, 'productId'), 'revenue'))->toBe(sleNetSales());
    });
});

describe('line figures with no tax and no discount', function () {
    it('are the line totals, unchanged', function () {
        Shop::setNoTax();
        ($this->sell)([[$this->rice, 1], [$this->coke, 2]], 18_000);

        expect(sleRevenueOf($this->rice))->toBe(12_000)->and(sleRevenueOf($this->coke))->toBe(6_000);
    });

    it('counts one receipt once, whatever matched on it', function () {
        Shop::setNoTax();
        ($this->sell)([[$this->rice, 1], [$this->coke, 1]], 15_000);

        expect(sleTally(SLE_AUGUST, 'day')[0]['saleCount'])->toBe(1);
    });
});
