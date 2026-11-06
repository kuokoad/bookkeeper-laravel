<?php

declare(strict_types=1);

/*
| Owner decision D26 (PLAN.md §10, approved 2026-11-06): stock that comes back
| up from below zero at a different price leaves a costing residual (zero
| quantity must mean zero value), and every document that brings stock in
| posts it, as a purchase void and a supplier return already did. Before the
| fix a purchase, a customer return, a sale void and an adjustment in each
| dropped it, so Inventory (1200) kept value an empty shelf did not hold.
|
| Negative stock allowed; rice costs 10.00 on the card and sells at 15.00.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Enums\AdjustmentReason;
use App\Services\InventoryService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    Shop::setting('allow_negative_stock', true);
    $this->rice = Shop::product($this->actor, 'Rice 5kg', 1_000, 1_500, ['unit' => 'bag']);
    $this->customer = Shop::customer($this->actor);
    // One bag on credit; with none on the shelf it is costed at the card's 10.00.
    $this->sellOne = fn (): int => Shop::sell($this->actor, [[$this->rice, 1_000]], [], ['customerId' => $this->customer])['saleId'];
    // One bag at 12.00 on the shelf, then sold: the shelf is empty again at a cost of 12.00.
    $this->soldAtTwelve = function (): int {
        Shop::openingStock($this->actor, $this->rice, 1_000, 1_200);

        return ($this->sellOne)();
    };
    $this->shelfIsEmptyAndWorthNothing = function (string $label): void {
        $shelf = Shop::productRow($this->rice);
        expect((int) $shelf->qty_on_hand_milli)->toBe(0, "{$label}: quantity")
            ->and((int) $shelf->stock_value_minor)->toBe(0, "{$label}: stock value")
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(0, "{$label}: Inventory (1200)");
        Shop::assertBooksHealthy($label);
    };
});

describe('D26: stock that comes back from below zero posts its costing residual', function () {
    it('leaves Inventory at 0.00 when a bag sold from an empty shelf is then bought at 12.00', function () {
        ($this->sellOne)();
        expect(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(-1_000);

        Shop::purchase($this->actor, Shop::supplier($this->actor), [[$this->rice, 1_000, 1_200]]);

        ($this->shelfIsEmptyAndWorthNothing)('after the delivery');
        // The bag really cost 12.00: the 2.00 difference is a cost of goods sold.
        expect(Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD))->toBe(1_200)
            ->and(DB::table('journal_entries')->where('source_type', 'PURCHASE')->count())->toBe(2);
    });

    it('leaves Inventory at 0.00 when a customer returns a 12.00 bag onto a shelf below zero', function () {
        $first = ($this->soldAtTwelve)();
        ($this->sellOne)();

        ReturnsService::createCustomerReturn($first, [
            'businessDate' => '2026-08-16',
            'items' => [['itemId' => SaleService::getSale($first)['items'][0]['id'], 'qty' => 1_000]],
            'refunds' => [],
        ], $this->actor);

        ($this->shelfIsEmptyAndWorthNothing)('after the return');
        expect(Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD))->toBe(1_200);
    });

    it('leaves Inventory at 0.00 when a 12.00 sale is voided onto a shelf below zero', function () {
        $first = ($this->soldAtTwelve)();
        ($this->sellOne)();

        SaleService::voidSale($first, 'Rung up twice', $this->actor);

        ($this->shelfIsEmptyAndWorthNothing)('after the void');
        expect(Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD))->toBe(1_200);
    });

    it('leaves Inventory at 0.00 when a bag found at 12.00 is counted in onto a shelf below zero', function () {
        ($this->sellOne)();

        StockAdjustmentService::createStockAdjustment([
            'businessDate' => '2026-08-16',
            'reason' => AdjustmentReason::Found,
            'items' => [['productId' => $this->rice, 'direction' => 'IN', 'qty' => 1_000, 'totalCost' => 1_200]],
        ], $this->actor);

        ($this->shelfIsEmptyAndWorthNothing)('after the adjustment');
    });

    it('posts a stock counted in at no value onto a shelf below zero as a cost given back', function () {
        ($this->sellOne)();

        StockAdjustmentService::createStockAdjustment([
            'businessDate' => '2026-08-16',
            'reason' => AdjustmentReason::Found,
            'items' => [['productId' => $this->rice, 'direction' => 'IN', 'qty' => 1_000, 'totalCost' => 0]],
        ], $this->actor);

        ($this->shelfIsEmptyAndWorthNothing)('after a zero-value count');
        expect(Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD))->toBe(0);
    });

    it('shows no ledger mismatch on the inventory report after the delivery', function () {
        ($this->sellOne)();
        Shop::purchase($this->actor, Shop::supplier($this->actor), [[$this->rice, 1_000, 1_200]]);

        expect(InventoryService::getInventoryValue())->toBe(Shop::balance(ChartOfAccounts::INVENTORY));
        signedIn($this->actor->id)->get('/reports/inventory')->assertOk()
            ->assertDontSee('Stock value does not match the accounts');
    });
});
