<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/purchase-tax.test.ts: tax paid to a
| supplier. VAT paid on a purchase is set against VAT collected, so it is an
| asset; tax that cannot be reclaimed is part of what the goods cost. Account
| balances come from Shop::balance (journal sums) until Phase 7.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const PTAX_DAY = '2026-08-10';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->supplier = Shop::supplier($this->actor, 'Kofi Wholesale');
    $this->riceDelivery = function (int $unitCost, int $qty, int $paid): int {
        $rice = Shop::product($this->actor, 'Rice 5kg', $unitCost, 10_000, ['unit' => 'bag']);
        PurchaseService::createPurchase([
            'businessDate' => PTAX_DAY,
            'supplierId' => $this->supplier,
            'items' => [['productId' => $rice, 'qty' => $qty * 1_000, 'unitCost' => $unitCost]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => $paid]],
        ], $this->actor);

        return $rice;
    };
    // The levies as they were before Act 1151: a cost of the goods, not reclaimable.
    $this->levyIsACost = fn () => DB::table('tax_components')->where('code', '<>', 'VAT')->update(['is_recoverable' => false]);
});

describe('tax on a delivery that cannot be reclaimed', function () {
    it('puts it into what the goods cost, and reclaims only what it may', function () {
        Shop::setGhanaTaxes();
        ($this->levyIsACost)();

        // 10 bags at 65.00 = 650.00 net; NHIL 16.25, GETFund 16.25, VAT 97.50.
        ($this->riceDelivery)(6_500, 10, 78_000);

        $recorded = DB::table('purchase_taxes')->orderBy('id')->get()
            ->map(fn (object $r): array => [$r->code, (int) $r->amount_minor, (bool) $r->is_recoverable])->all();
        expect($recorded)->toBe([['NHIL', 1_625, false], ['GETFUND', 1_625, false], ['VAT', 9_750, true]]);

        // The stock is worth the goods plus the two levies: 650.00 + 32.50.
        expect(InventoryService::getInventoryValue())->toBe(68_250, 'stock value')
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(68_250, 'Inventory account agrees with the shelf')
            ->and(Shop::balance(ChartOfAccounts::TAX_PAYABLE))->toBe(-9_750)
            ->and(Shop::balance(ChartOfAccounts::NHIL_PAYABLE))->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::GETFUND_PAYABLE))->toBe(0);
    });

    it('carries it into the unit cost, so margin is not overstated', function () {
        Shop::setGhanaTaxes();
        ($this->levyIsACost)();
        $rice = ($this->riceDelivery)(6_500, 10, 78_000);

        $product = Shop::productRow($rice);
        expect((int) $product->stock_value_minor)->toBe(68_250)
            ->and((int) $product->qty_on_hand_milli)->toBe(10_000);
    });

    it('reclaims the whole tax when every component is reclaimable', function () {
        Shop::setSingleTax(1_250);
        ($this->riceDelivery)(6_500, 10, 73_125);

        expect(InventoryService::getInventoryValue())->toBe(65_000, 'stock is the goods value alone')
            ->and(Shop::balance(ChartOfAccounts::TAX_PAYABLE))->toBe(-8_125);
    });

    it('adds nothing at all when the shop is not registered for tax', function () {
        ($this->riceDelivery)(6_500, 10, 65_000);

        expect(DB::table('purchase_taxes')->count())->toBe(0)
            ->and(InventoryService::getInventoryValue())->toBe(65_000)
            ->and(Shop::balance(ChartOfAccounts::TAX_PAYABLE))->toBe(0);
    });
});

describe('Ghana from 1 January 2026, as the shop is seeded', function () {
    it('reclaims all three, so nothing lands in the cost of the goods', function () {
        Shop::setGhanaTaxes();
        ($this->riceDelivery)(6_500, 10, 78_000);

        expect(DB::table('purchase_taxes')->orderBy('id')->get()->map(fn (object $r): array => [$r->code, (bool) $r->is_recoverable])->all())
            ->toBe([['NHIL', true], ['GETFUND', true], ['VAT', true]]);
        expect(InventoryService::getInventoryValue())->toBe(65_000, 'stock is the goods alone')
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(65_000)
            ->and(Shop::balance(ChartOfAccounts::NHIL_PAYABLE))->toBe(-1_625)
            ->and(Shop::balance(ChartOfAccounts::GETFUND_PAYABLE))->toBe(-1_625)
            ->and(Shop::balance(ChartOfAccounts::TAX_PAYABLE))->toBe(-9_750);
    });

    it('charges 20 per cent on the net, not 20.75 on a cascade', function () {
        Shop::setGhanaTaxes();
        ($this->riceDelivery)(6_500, 10, 78_000);

        expect((int) DB::table('purchase_taxes')->sum('amount_minor'))->toBe(13_000, 'GHS 130.00 on GHS 650.00');
    });
});

describe('voiding a delivery that carried tax', function () {
    it('stops reclaiming the VAT, and takes the levies back out of stock', function () {
        ($this->levyIsACost)();
        Shop::setGhanaTaxes();
        ($this->riceDelivery)(6_500, 10, 78_000);

        PurchaseService::voidPurchase((int) DB::table('purchases')->where('kind', 'PURCHASE')->value('id'), 'Wrong supplier invoice', $this->actor);

        expect(InventoryService::getInventoryValue())->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::TAX_PAYABLE))->toBe(0)
            ->and((int) DB::table('purchase_taxes')->sum('amount_minor'))->toBe(0, 'what was reclaimed, less what was given back');
    });
});

describe('the account a purchase reclaims into', function () {
    it('follows the component, not a hard-coded tax account', function () {
        Shop::setSingleTax(1_250);
        DB::table('tax_components')->where('code', 'VAT')->update(['gl_account_id' => Shop::accountId(ChartOfAccounts::NHIL_PAYABLE)]);

        ($this->riceDelivery)(6_500, 10, 73_125);

        expect(Shop::balance(ChartOfAccounts::NHIL_PAYABLE))->toBe(-8_125)
            ->and(Shop::balance(ChartOfAccounts::TAX_PAYABLE))->toBe(0);
    });
});
