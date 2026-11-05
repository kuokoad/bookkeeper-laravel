<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/return-tax.test.ts (customer half): the
| tax goes back when the goods come back. After every test, every unit of
| stock still belongs to a batch, as the old afterEach checked.
|
| Deferred to Phase 5 (purchases): "sending goods back to a supplier" — "gives
| back the tax that was reclaimed on them".
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const RTAX_DAY = '2026-08-10';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->customer = Shop::customer($this->actor, 'Mensah Provisions');

    $this->stockedProduct = function (): int {
        $id = Shop::product($this->actor, 'Rice 5kg', 6_000, 10_000, ['unit' => 'bag']);
        Shop::openingStock($this->actor, $id, 20_000, 120_000, RTAX_DAY);

        return $id;
    };

    $this->creditSale = fn (int $productId, int $units): int => SaleService::createSale([
        'businessDate' => RTAX_DAY,
        'customerId' => $this->customer,
        'items' => [['productId' => $productId, 'qty' => $units * 1000]],
        'tenders' => [],
    ], $this->actor)['saleId'];

    $this->bringBack = fn (int $saleId, int $units): array => ReturnsService::createCustomerReturn($saleId, [
        'businessDate' => RTAX_DAY,
        'items' => [['itemId' => (int) DB::table('sale_items')->where('sale_id', $saleId)->orderBy('id')->value('id'), 'qty' => $units * 1000]],
        'refunds' => [],
    ], $this->actor);
});

afterEach(function () {
    foreach (InventoryService::verifyBatchCoverage() as $row) {
        expect($row['ok'])->toBeTrue("coverage for product {$row['productId']}");
    }
});

describe('returning goods that carried tax', function () {
    it('gives the tax back to the taxman as well as the goods to the shelf', function () {
        Shop::setSingleTax(1_250);
        // Two bags at GHS 100.00 plus GHS 25.00 VAT = GHS 225.00 on credit.
        $sale = ($this->creditSale)(($this->stockedProduct)(), 2);
        expect(Shop::balance(ChartOfAccounts::TAX_PAYABLE))->toBe(2_500);

        ($this->bringBack)($sale, 2);

        expect(Shop::balance(ChartOfAccounts::TAX_PAYABLE))->toBe(0);
    });

    it('stops the customer owing tax on goods they gave back', function () {
        Shop::setSingleTax(1_250);
        $sale = ($this->creditSale)(($this->stockedProduct)(), 2);
        expect(CustomerService::getCustomerBalance($this->customer))->toBe(22_500);

        ($this->bringBack)($sale, 2);

        expect(CustomerService::getCustomerBalance($this->customer))->toBe(0);
    });

    it('gives back a proportional share on a partial return', function () {
        Shop::setSingleTax(1_250);
        $sale = ($this->creditSale)(($this->stockedProduct)(), 4);

        // One of four back: a quarter of GHS 50.00 of VAT is GHS 12.50.
        ($this->bringBack)($sale, 1);

        expect(Shop::balance(ChartOfAccounts::TAX_PAYABLE))->toBe(3_750)
            // GHS 400 + GHS 50 VAT = GHS 450, less GHS 100 + GHS 12.50 returned.
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(33_750);
    });

    it('leaves the books balanced', function () {
        Shop::setSingleTax(1_250);
        $sale = ($this->creditSale)(($this->stockedProduct)(), 3);

        ($this->bringBack)($sale, 2);

        expect(Shop::trialBalanced())->toBeTrue();
    });
});

describe('returning goods from a shop with no tax', function () {
    it('behaves exactly as before', function () {
        $sale = ($this->creditSale)(($this->stockedProduct)(), 2);

        ($this->bringBack)($sale, 2);

        expect(CustomerService::getCustomerBalance($this->customer))->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::TAX_PAYABLE))->toBe(0)
            ->and(Shop::trialBalanced())->toBeTrue();
    });
});
