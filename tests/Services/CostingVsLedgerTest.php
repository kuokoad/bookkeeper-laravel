<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/costing-vs-ledger.test.ts: the stock
| ledger and the general ledger must agree, always, most of all when something
| is undone after the average has moved on. The whole old file is ported.
|
| Each stock-adjustment void runs twice: as the old test wrote it (a delivery
| at GHS 20.00 a bag moves the average, a sale takes ten), and in an earlier
| stand-in kept from before purchases existed (a FOUND adjustment in, ten bags
| written off as DAMAGED). The costing a void meets is the same either way.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Enums\AdjustmentReason;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\StockAdjustmentService;
use Tests\Support\Shop;

const COSTING_DAY = '2026-08-10';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->product = Shop::product($this->actor, 'Rice 5kg', 1_000, 3_000, ['unit' => 'bag']);
    $this->cash = Shop::paymentAccount('CASH');
    $this->supplier = Shop::supplier($this->actor, 'Kofi Wholesale');
    // Ten bags delivered at a unit cost, paid in cash.
    $this->deliver = fn (int $unitCost): array => Shop::purchase($this->actor, $this->supplier, [[$this->product, 10_000, $unitCost]], [['paymentAccountId' => $this->cash, 'amount' => 10 * $unitCost]], ['businessDate' => COSTING_DAY]);
    $this->sellAt = fn (int $units, int $paid): array => Shop::sell($this->actor, [[$this->product, $units * 1000]], [['paymentAccountId' => $this->cash, 'amount' => $paid]], ['businessDate' => COSTING_DAY]);
    // Opening stock at GHS 10.00 a bag.
    $this->opening = fn (): array => Shop::openingStock($this->actor, $this->product, 10_000, 10_000, COSTING_DAY);
    // The stand-in: ten more at GHS 20.00 shift the average to GHS 15.00, by adjustment.
    $this->found = fn (): array => StockAdjustmentService::createStockAdjustment([
        'businessDate' => COSTING_DAY,
        'reason' => AdjustmentReason::Found,
        'items' => [['productId' => $this->product, 'direction' => 'IN', 'qty' => 10_000, 'totalCost' => 20_000]],
    ], $this->actor);
});

describe('voiding a purchase after the average has moved', function () {
    it('leaves the stock ledger and the accounts agreeing', function () {
        // Ten bags at GHS 10.00, then ten more at GHS 20.00: the average is now
        // GHS 15.00, and neither delivery was bought at that price.
        $first = ($this->deliver)(1_000);
        ($this->deliver)(2_000);
        Shop::assertBooksHealthy('after both deliveries');

        PurchaseService::voidPurchase($first['purchaseId'], 'Supplier invoiced us twice', $this->actor);

        Shop::assertBooksHealthy('after voiding the first delivery');
    });

    it('still agrees when some of the stock has already been sold', function () {
        $first = ($this->deliver)(1_000);
        ($this->deliver)(2_000);
        ($this->sellAt)(5, 15_000);
        Shop::assertBooksHealthy('after selling five');

        PurchaseService::voidPurchase($first['purchaseId'], 'Supplier invoiced us twice', $this->actor);

        Shop::assertBooksHealthy('after voiding a delivery that is partly sold');
    });
});

describe('voiding a stock adjustment after the average has moved', function () {
    it('leaves the stock ledger and the accounts agreeing', function () {
        $opening = ($this->opening)();
        // A delivery at GHS 20.00 shifts the average to GHS 15.00.
        ($this->deliver)(2_000);
        Shop::assertBooksHealthy('after the delivery');

        StockAdjustmentService::voidStockAdjustment($opening['adjustmentId'], 'Counted the wrong shelf', $this->actor);

        Shop::assertBooksHealthy('after voiding the opening stock');
    });

    it('leaves the stock ledger and the accounts agreeing (adjustment stand-in)', function () {
        $opening = ($this->opening)();
        ($this->found)();
        Shop::assertBooksHealthy('after the second lot');

        StockAdjustmentService::voidStockAdjustment($opening['adjustmentId'], 'Counted the wrong shelf', $this->actor);

        Shop::assertBooksHealthy('after voiding the opening stock');
    });
});

describe('a void that empties the shelf', function () {
    it('keeps both counts of inventory at zero, and says where the difference went', function () {
        // Ten at GHS 10.00, ten at GHS 20.00: average GHS 15.00, value GHS 300.00.
        $first = ($this->deliver)(1_000);
        ($this->deliver)(2_000);
        // Sell ten at the average: GHS 150.00 of cost leaves, GHS 150.00 remains against ten bags.
        ($this->sellAt)(10, 30_000);

        // Ten bags out at the GHS 100.00 they went in at, leaving GHS 50.00 of value behind.
        PurchaseService::voidPurchase($first['purchaseId'], 'Supplier invoiced us twice', $this->actor);

        expect(InventoryService::getInventoryValue())->toBe(0);
        Shop::assertBooksHealthy('after voiding down to an empty shelf');
    });

    it('records the difference as a cost, not as thin air', function () {
        $opening = ($this->opening)();
        ($this->deliver)(2_000);
        ($this->sellAt)(10, 30_000);
        $cogsBefore = Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD);

        StockAdjustmentService::voidStockAdjustment($opening['adjustmentId'], 'Counted the wrong shelf', $this->actor);

        // The GHS 50.00 stranded by the averaging is a cost, and appears as one.
        expect(Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD) - $cogsBefore)->toBe(5_000)
            ->and(InventoryService::getInventoryValue())->toBe(0);
        Shop::assertBooksHealthy('after voiding opening stock down to empty');
    });

    it('records the difference as a cost, not as thin air (adjustment stand-in)', function () {
        $opening = ($this->opening)();
        ($this->found)();
        // Ten leave at the average: GHS 150.00 of cost, GHS 150.00 left against ten bags.
        Shop::stockOut($this->actor, $this->product, 10_000, COSTING_DAY);
        $cogsBefore = Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD);

        StockAdjustmentService::voidStockAdjustment($opening['adjustmentId'], 'Counted the wrong shelf', $this->actor);

        expect(Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD) - $cogsBefore)->toBe(5_000)
            ->and(InventoryService::getInventoryValue())->toBe(0);
        Shop::assertBooksHealthy('after voiding opening stock down to empty');
    });
});
