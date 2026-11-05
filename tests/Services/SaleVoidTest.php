<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/sale-void.test.ts: voiding a sale that was
| given a discount or carried tax. The mirror's negative figures must satisfy
| the same table, and every unit of stock must still belong to a batch.
*/

use App\Services\InventoryService;
use App\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const SVOID_TODAY = '2026-08-16';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->stocked = function (int $price): int {
        $id = Shop::product($this->actor, 'Rice 5kg', 6_000, $price);
        Shop::openingStock($this->actor, $id, 20_000, 120_000, SVOID_TODAY);

        return $id;
    };
});

afterEach(function () {
    // Voids are where the question of WHICH crate stock goes back to has a wrong answer.
    foreach (InventoryService::verifyBatchCoverage() as $row) {
        expect($row['ok'])->toBeTrue("coverage for product {$row['productId']}");
    }
});

describe('voiding', function () {
    it('works on a sale that was given a discount', function () {
        $product = ($this->stocked)(10_000);
        $created = SaleService::createSale([
            'businessDate' => SVOID_TODAY,
            'items' => [['productId' => $product, 'qty' => 2_000]],
            'invoiceDiscount' => 2_000,
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 18_000]],
            'allowPriceOverride' => true,
        ], $this->actor);

        SaleService::voidSale($created['saleId'], 'Rang it up twice', $this->actor);
        expect(Shop::trialBalanced())->toBeTrue();
    });

    it('works on a sale that carried tax', function () {
        Shop::setSingleTax(1_250);
        $product = ($this->stocked)(10_000);
        $created = SaleService::createSale([
            'businessDate' => SVOID_TODAY,
            'items' => [['productId' => $product, 'qty' => 1_000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 11_250]],
        ], $this->actor);

        SaleService::voidSale($created['saleId'], 'Wrong customer', $this->actor);
        expect(Shop::trialBalanced())->toBeTrue();
    });

    it('works on a tax-inclusive sale with a discount', function () {
        Shop::setSingleTax(1_250, inclusive: true);
        $product = ($this->stocked)(11_250);
        $created = SaleService::createSale([
            'businessDate' => SVOID_TODAY,
            'items' => [['productId' => $product, 'qty' => 2_000]],
            'invoiceDiscount' => 2_250,
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 20_250]],
            'allowPriceOverride' => true,
        ], $this->actor);

        SaleService::voidSale($created['saleId'], 'Customer changed their mind', $this->actor);

        // The pair nets to nothing: that is what "void" has to mean.
        $mirror = DB::table('sales')->where('voids_sale_id', $created['saleId'])->first();
        $original = DB::table('sales')->where('id', $created['saleId'])->first();
        expect((int) $mirror->total_minor + (int) $original->total_minor)->toBe(0)
            ->and((int) $mirror->tax_minor + (int) $original->tax_minor)->toBe(0)
            ->and(Shop::trialBalanced())->toBeTrue();
    });
});
