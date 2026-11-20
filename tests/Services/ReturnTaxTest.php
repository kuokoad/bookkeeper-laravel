<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/return-tax.test.ts: the tax goes back
| when the goods come back, from a customer or to a supplier. After every
| test, every unit of stock still belongs to a batch, as the old afterEach
| checked. Nothing is deferred.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\PurchaseService;
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

describe('sending goods back to a supplier', function () {
    // The mirror of the customer side: tax paid to a supplier was reclaimed
    // when the goods arrived, and goes back with them.
    it('gives back the tax that was reclaimed on them', function () {
        Shop::setSingleTax(1_250);
        $supplier = Shop::supplier($this->actor, 'Kofi Wholesale');
        $product = ($this->stockedProduct)();

        // Ten bags at GHS 50.00 = GHS 500.00 plus GHS 62.50 VAT.
        $purchase = Shop::purchase($this->actor, $supplier, [[$product, 10_000, 5_000]], [['paymentAccountId' => Shop::paymentAccount('CASH'), 'amount' => 56_250]], ['businessDate' => RTAX_DAY]);
        $taxAfterPurchase = Shop::balance(ChartOfAccounts::TAX_PAYABLE);

        // All ten go back.
        ReturnsService::createSupplierReturn($purchase['purchaseId'], [
            'businessDate' => RTAX_DAY,
            'items' => [['itemId' => PurchaseService::getPurchase($purchase['purchaseId'])['items'][0]['id'], 'qty' => 10_000]],
            'refunds' => [],
        ], $this->actor);

        // The reclaim goes back with the goods, leaving the tax account where it
        // stood before the delivery.
        expect(Shop::balance(ChartOfAccounts::TAX_PAYABLE) - $taxAfterPurchase)->toBe(6_250)
            ->and(Shop::trialBalanced())->toBeTrue();
    });
});

it('gives the tax back on a sale from before tax rows were kept, out of the tax account it went into', function () {
    Shop::setSingleTax(1_500);
    $saleId = ($this->creditSale)(($this->stockedProduct)(), 2);
    // As imported from the old app's early days: the sale carries its tax, with no row per tax.
    DB::table('sale_taxes')->where('sale_id', $saleId)->delete();
    $taxBefore = Shop::balance(ChartOfAccounts::TAX_PAYABLE);

    $return = ($this->bringBack)($saleId, 1);

    // One bag at 100.00 plus 15.00 tax comes off what the customer owes, and the 15.00 off the tax owed.
    expect((int) DB::table('sales')->where('id', $return['returnSaleId'])->value('tax_minor'))->toBe(-1_500)
        ->and($return['creditApplied'])->toBe(11_500)
        ->and(Shop::balance(ChartOfAccounts::TAX_PAYABLE))->toBe($taxBefore - 1_500);
    Shop::assertBooksHealthy('return of a sale with no tax rows');
});
