<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/costing-vs-ledger.test.ts: the stock
| ledger and the general ledger must agree, always, most of all when something
| is undone after the average has moved on.
|
| The stock-adjustment voids are ported with the average moved by a FOUND
| adjustment in at GHS 20.00 a bag, where the old test used a delivery, and
| ten bags written off as DAMAGED where it used a sale: the costing a void
| meets is the same, and the assertions (stock value = GL 1200, trial balance,
| replay, the 5,000 residual to COGS) are unchanged. The purchase-void tests
| ("voiding a purchase after the average has moved", 2 tests, and "keeps both
| counts of inventory at zero") wait for purchases (Phase 5).
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Enums\AdjustmentReason;
use App\Services\InventoryService;
use App\Services\StockAdjustmentService;
use Tests\Support\Shop;

const COSTING_DAY = '2026-08-10';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->product = Shop::product($this->actor, 'Rice 5kg', 1_000, 3_000, ['unit' => 'bag']);
    // Opening stock at GHS 10.00 a bag.
    $this->opening = Shop::openingStock($this->actor, $this->product, 10_000, 10_000, COSTING_DAY);
    // Ten more at GHS 20.00 shift the average to GHS 15.00.
    StockAdjustmentService::createStockAdjustment([
        'businessDate' => COSTING_DAY,
        'reason' => AdjustmentReason::Found,
        'items' => [['productId' => $this->product, 'direction' => 'IN', 'qty' => 10_000, 'totalCost' => 20_000]],
    ], $this->actor);
});

describe('voiding a stock adjustment after the average has moved', function () {
    it('leaves the stock ledger and the accounts agreeing', function () {
        Shop::assertBooksHealthy('after the second lot');

        StockAdjustmentService::voidStockAdjustment($this->opening['adjustmentId'], 'Counted the wrong shelf', $this->actor);

        Shop::assertBooksHealthy('after voiding the opening stock');
    });
});

describe('a void that empties the shelf', function () {
    it('records the difference as a cost, not as thin air', function () {
        // Ten leave at the average: GHS 150.00 of cost, GHS 150.00 left against ten bags.
        Shop::stockOut($this->actor, $this->product, 10_000, COSTING_DAY);
        $cogsBefore = Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD);

        StockAdjustmentService::voidStockAdjustment($this->opening['adjustmentId'], 'Counted the wrong shelf', $this->actor);

        // The GHS 50.00 stranded by the averaging is a cost, and appears as one.
        expect(Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD) - $cogsBefore)->toBe(5_000)
            ->and(InventoryService::getInventoryValue())->toBe(0);
        Shop::assertBooksHealthy('after voiding opening stock down to empty');
    });
});
