<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/sales-tax.test.ts: selling at prices that
| already include tax, and at prices that do not. The trial balance and
| account balances come from Shop, which calls the real BalancesService.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const SALES_TAX_TODAY = '2026-08-16';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    // 12.5%, Ghana's VAT standard rate at the time of writing.
    $this->setTax = fn (bool $inclusive) => Shop::setSingleTax(1_250, $inclusive);
    // A product priced at GHS 112.50 on the shelf, with stock to sell.
    $this->stocked = function (): int {
        $id = Shop::product($this->actor, 'Rice 5kg', 6_000, 11_250);
        Shop::openingStock($this->actor, $id, 20_000, 120_000, SALES_TAX_TODAY);

        return $id;
    };
    $this->sale = fn (array $input): object => DB::table('sales')->where('id', SaleService::createSale(
        ['businessDate' => SALES_TAX_TODAY, 'allowPriceOverride' => true, ...$input],
        $this->actor,
    )['saleId'])->first();
});

describe('a sale at tax-inclusive prices', function () {
    it('can be recorded at all', function () {
        ($this->setTax)(true);
        $product = ($this->stocked)();

        $row = ($this->sale)(['items' => [['productId' => $product, 'qty' => 1_000, 'unitPrice' => 11_250]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 11_250]]]);

        expect((int) $row->total_minor)->toBe(11_250)->and((int) $row->tax_minor)->toBe(1_250);
    });

    it('keeps the stored figures adding up: subtotal − discount + tax = total', function () {
        ($this->setTax)(true);
        $product = ($this->stocked)();

        $row = ($this->sale)([
            'items' => [['productId' => $product, 'qty' => 2_000, 'unitPrice' => 11_250]],
            'invoiceDiscount' => 2_250,
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 20_250]],
        ]);

        expect((int) $row->subtotal_minor - (int) $row->discount_minor + (int) $row->tax_minor)->toBe((int) $row->total_minor)
            ->and((int) $row->total_minor)->toBe(20_250);
    });

    it('splits what was taken into revenue and tax owed, and nothing else', function () {
        ($this->setTax)(true);
        $product = ($this->stocked)();

        ($this->sale)(['items' => [['productId' => $product, 'qty' => 1_000, 'unitPrice' => 11_250]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 11_250]]]);

        expect(Shop::balance(ChartOfAccounts::TAX_PAYABLE))->toBe(1_250)
            ->and(Shop::balance(ChartOfAccounts::SALES_REVENUE))->toBe(10_000);
    });

    it('leaves the books in balance, discounts and awkward numbers included', function () {
        ($this->setTax)(true);
        $product = ($this->stocked)();

        ($this->sale)([
            'items' => [['productId' => $product, 'qty' => 3_000, 'unitPrice' => 11_250, 'discount' => 1_000]],
            'invoiceDiscount' => 1_337,
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 31_413]],
        ]);

        expect(Shop::trialBalanced())->toBeTrue();
    });

    it('charges tax on the discounted price, not the shelf price', function () {
        ($this->setTax)(true);
        $product = ($this->stocked)();

        $row = ($this->sale)([
            'items' => [['productId' => $product, 'qty' => 1_000, 'unitPrice' => 11_250]],
            'invoiceDiscount' => 2_250,
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 9_000]],
        ]);

        expect((int) $row->total_minor)->toBe(9_000)->and((int) $row->tax_minor)->toBe(1_000);
    });
});

describe('a sale at tax-exclusive prices, unchanged', function () {
    it('still adds tax on top and still balances', function () {
        ($this->setTax)(false);
        $product = Shop::product($this->actor, 'Rice 5kg', 6_000, 10_000);
        Shop::openingStock($this->actor, $product, 10_000, 60_000, SALES_TAX_TODAY);

        $row = ($this->sale)(['items' => [['productId' => $product, 'qty' => 1_000, 'unitPrice' => 10_000]], 'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 11_250]]]);

        expect((int) $row->subtotal_minor)->toBe(10_000)
            ->and((int) $row->tax_minor)->toBe(1_250)
            ->and((int) $row->total_minor)->toBe(11_250)
            ->and(Shop::trialBalanced())->toBeTrue()
            ->and(Shop::balance(ChartOfAccounts::SALES_REVENUE))->toBe(10_000);
    });
});
