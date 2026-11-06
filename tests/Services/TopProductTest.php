<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/top-product.test.ts: the dashboard's "Top
| selling product".
|
| Read through the real OperationsService::getTopProductByRevenue. The
| whole old file is ported; nothing is deferred.
*/

use App\Services\CatalogService;
use App\Services\Reporting\OperationsService;
use App\Services\SaleService;
use Illuminate\Support\Carbon;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $drinks = CatalogService::createCategory(['name' => 'Drinks'], $this->actor);
    $food = CatalogService::createCategory(['name' => 'Food'], $this->actor);
    $this->coke = Shop::product($this->actor, 'Coca-Cola 500ml', 300, 500, ['categoryId' => $drinks]);
    $this->rice = Shop::product($this->actor, 'Rice 5kg', 4_000, 6_000, ['categoryId' => $food, 'unit' => 'bag']);
    Shop::openingStock($this->actor, $this->coke, 500_000, 150_000, '2026-07-31');
    Shop::openingStock($this->actor, $this->rice, 100_000, 400_000, '2026-07-31');
    $this->sale = fn (string $date, int $productId, int $qty, int $unitPrice): int => SaleService::createSale([
        'businessDate' => $date,
        'items' => [['productId' => $productId, 'qty' => $qty * 1000]],
        'tenders' => [['paymentAccountId' => $this->cash, 'amount' => $qty * $unitPrice]],
    ], $this->actor)['saleId'];
    $this->voidAt = Carbon::parse('2026-08-07 10:00:00', 'UTC');
});

describe('the top selling product', function () {
    it('names the product that took the most money, not the one that moved most', function () {
        ($this->sale)('2026-08-05', $this->coke, 50, 500);
        ($this->sale)('2026-08-06', $this->rice, 10, 6_000);

        $top = OperationsService::getTopProductByRevenue(['from' => '2026-08-01', 'to' => '2026-08-31']);
        expect($top['productName'])->toBe('Rice 5kg')
            ->and($top['revenue'])->toBe(60_000)
            ->and($top['qtySold'])->toBe(10_000)
            ->and($top['unit'])->toBe('bag');
    });

    it('adds up every sale of a product, not just the largest one', function () {
        ($this->sale)('2026-08-05', $this->rice, 10, 6_000);
        ($this->sale)('2026-08-06', $this->coke, 100, 500);
        ($this->sale)('2026-08-07', $this->coke, 60, 500);

        $top = OperationsService::getTopProductByRevenue(['from' => '2026-08-01', 'to' => '2026-08-31']);
        expect($top['productName'])->toBe('Coca-Cola 500ml')
            ->and($top['revenue'])->toBe(80_000)
            ->and($top['qtySold'])->toBe(160_000);
    });

    it('says nothing when nothing was sold', function () {
        expect(OperationsService::getTopProductByRevenue(['from' => '2026-08-01', 'to' => '2026-08-31']))->toBeNull();
    });

    it('ignores trading outside the window at either end', function () {
        ($this->sale)('2026-07-31', $this->rice, 40, 6_000);
        ($this->sale)('2026-09-01', $this->rice, 40, 6_000);
        ($this->sale)('2026-08-15', $this->coke, 10, 500);

        $top = OperationsService::getTopProductByRevenue(['from' => '2026-08-01', 'to' => '2026-08-31']);
        expect($top['productName'])->toBe('Coca-Cola 500ml')->and($top['revenue'])->toBe(5_000);
    });

    it('does not crown a product whose only sale was voided', function () {
        $rice = ($this->sale)('2026-08-05', $this->rice, 10, 6_000);
        ($this->sale)('2026-08-06', $this->coke, 10, 500);
        SaleService::voidSale($rice, 'Rung up twice', $this->actor, $this->voidAt);

        expect(OperationsService::getTopProductByRevenue(['from' => '2026-08-01', 'to' => '2026-08-31'])['productName'])->toBe('Coca-Cola 500ml');
    });

    it('says nothing when every sale in the window was voided', function () {
        $rice = ($this->sale)('2026-08-05', $this->rice, 10, 6_000);
        SaleService::voidSale($rice, 'Rung up twice', $this->actor, $this->voidAt);

        expect(OperationsService::getTopProductByRevenue(['from' => '2026-08-01', 'to' => '2026-08-31']))->toBeNull();
    });

    it('counts a void of an earlier sale against the window it lands in', function () {
        $july = ($this->sale)('2026-07-20', $this->rice, 10, 6_000);
        ($this->sale)('2026-08-06', $this->coke, 10, 500);
        SaleService::voidSale($july, 'Customer returned everything', $this->actor, $this->voidAt);

        expect(OperationsService::getTopProductByRevenue(['from' => '2026-08-01', 'to' => '2026-08-31'])['productName'])->toBe('Coca-Cola 500ml');
    });

    it('names the same product every time when two are level', function () {
        ($this->sale)('2026-08-05', $this->coke, 60, 500);
        ($this->sale)('2026-08-05', $this->rice, 5, 6_000);

        $first = OperationsService::getTopProductByRevenue(['from' => '2026-08-01', 'to' => '2026-08-31']);
        expect($first['revenue'])->toBe(30_000);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            expect(OperationsService::getTopProductByRevenue(['from' => '2026-08-01', 'to' => '2026-08-31'])['productId'])->toBe($first['productId']);
        }
    });
});
