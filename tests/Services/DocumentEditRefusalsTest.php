<?php

declare(strict_types=1);

/*
| Audit finding 13: the edits a shop owner must NOT be able to make (D34).
| Each refusal leaves the figures a shop owner would check exactly as they
| were: the stock on the shelf, the cash, and what a customer or supplier owes.
| The normal edit paths are in DocumentEditTest.
*/

use App\Domain\Errors\ConflictError;
use App\Domain\Errors\PeriodLockedError;
use App\Domain\Errors\ValidationError;
use App\Services\CatalogService;
use App\Services\CustomerPaymentService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\SupplierPaymentService;
use App\Services\SupplierService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const REFUSE_DAY = '2026-08-16';

beforeEach(function () {
    Shop::setNoTax();
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    // 10 tins of Milo, bought at GHS 5.00 and sold at GHS 8.00.
    $this->product = Shop::product($this->actor, 'Milo 400g', 500, 800);
    Shop::openingStock($this->actor, $this->product, 10_000, 5_000, REFUSE_DAY);
    $this->onShelf = fn (): int => CatalogService::getProduct($this->product)['qtyOnHand'];

    $this->saleInput = fn (int $qty, array $extra = []): array => [
        'businessDate' => REFUSE_DAY,
        'items' => [['productId' => $this->product, 'qty' => $qty]],
        'tenders' => [['paymentAccountId' => $this->cash, 'amount' => intdiv($qty, 1000) * 800]],
        ...$extra,
    ];
    $this->supplier = Shop::supplier($this->actor);
    $this->purchaseInput = fn (int $qty, int $unitCost, array $extra = []): array => [
        'supplierId' => $this->supplier,
        'businessDate' => REFUSE_DAY,
        'items' => [['productId' => $this->product, 'qty' => $qty, 'unitCost' => $unitCost]],
        'tenders' => [],
        ...$extra,
    ];
});

describe('a sale edit', function () {
    it('is refused in a locked period, changing nothing', function () {
        $sale = SaleService::createSale(($this->saleInput)(3_000), $this->actor);
        Shop::setting('books_locked_before', '2026-08-20');

        expect(fn () => SaleService::editSale($sale['saleId'], ($this->saleInput)(2_000, ['businessDate' => '2026-08-21']), 'Sold 2', $this->actor))
            ->toThrow(PeriodLockedError::class)
            ->and(($this->onShelf)())->toBe(7_000)
            ->and(Shop::balance('1001'))->toBe(2_400);
    });

    it('is refused when it would move the sale into a locked period', function () {
        Shop::setting('books_locked_before', '2026-08-10');
        $sale = SaleService::createSale(($this->saleInput)(3_000), $this->actor);

        expect(fn () => SaleService::editSale($sale['saleId'], ($this->saleInput)(3_000, ['businessDate' => '2026-08-05']), 'Wrong day', $this->actor))
            ->toThrow(PeriodLockedError::class)
            ->and((string) DB::table('sales')->where('id', $sale['saleId'])->value('business_date'))->toBe(REFUSE_DAY);
    });

    it('is refused once a customer payment is set against it, changing nothing', function () {
        $customer = Shop::customer($this->actor);
        $sale = SaleService::createSale(($this->saleInput)(3_000, ['customerId' => $customer, 'tenders' => []]), $this->actor);
        CustomerPaymentService::recordCustomerPayment(['customerId' => $customer, 'businessDate' => REFUSE_DAY, 'paymentAccountId' => $this->cash, 'amount' => 1_000,
            'allocations' => [['saleId' => $sale['saleId'], 'amount' => 1_000]]], $this->actor);

        expect(fn () => SaleService::editSale($sale['saleId'], ($this->saleInput)(2_000, ['customerId' => $customer, 'tenders' => []]), 'Sold 2', $this->actor))
            ->toThrow(ConflictError::class, 'customer payments')
            ->and(SaleService::getSaleOutstanding($sale['saleId']))->toBe(1_400)
            ->and(($this->onShelf)())->toBe(7_000);
    });

    it('holds to the customer’s credit limit, without counting the sale being edited twice', function () {
        $customer = Shop::customer($this->actor, 'Ama Serwaa', 3_000);
        $credit = fn (int $qty, string $note): array => ($this->saleInput)($qty, ['customerId' => $customer, 'tenders' => [], 'note' => $note]);
        $sale = SaleService::createSale($credit(3_000, 'Three tins'), $this->actor);

        // GHS 24.00 owed of a GHS 30.00 limit: fixing the note must not count it as GHS 48.00.
        SaleService::editSale($sale['saleId'], $credit(3_000, 'Three tins, on account'), 'Add the note', $this->actor);
        expect(fn () => SaleService::editSale($sale['saleId'], $credit(4_000, 'Four tins'), 'Sold 4', $this->actor))
            ->toThrow(ValidationError::class, 'credit limit')
            ->and(SaleService::getSaleOutstanding($sale['saleId']))->toBe(2_400)
            ->and(($this->onShelf)())->toBe(7_000);
    });
});

describe('a purchase edit', function () {
    it('is refused in a locked period, changing nothing', function () {
        $purchase = PurchaseService::createPurchase(($this->purchaseInput)(20_000, 600), $this->actor);
        Shop::setting('books_locked_before', '2026-08-20');

        expect(fn () => PurchaseService::editPurchase($purchase['purchaseId'], ($this->purchaseInput)(20_000, 550, ['businessDate' => '2026-08-21']), 'Invoice said 5.50', $this->actor))
            ->toThrow(PeriodLockedError::class)
            ->and(($this->onShelf)())->toBe(30_000)
            ->and(SupplierService::getSupplierBalance($this->supplier))->toBe(12_000);
    });

    it('is refused once a supplier payment is set against it, changing nothing', function () {
        $purchase = PurchaseService::createPurchase(($this->purchaseInput)(20_000, 600), $this->actor);
        SupplierPaymentService::recordSupplierPayment(['supplierId' => $this->supplier, 'businessDate' => REFUSE_DAY, 'paymentAccountId' => $this->cash, 'amount' => 5_000,
            'allocations' => [['purchaseId' => $purchase['purchaseId'], 'amount' => 5_000]]], $this->actor);

        expect(fn () => PurchaseService::editPurchase($purchase['purchaseId'], ($this->purchaseInput)(20_000, 550), 'Invoice said 5.50', $this->actor))
            ->toThrow(ConflictError::class, 'supplier payments')
            ->and(PurchaseService::getPurchaseOutstanding($purchase['purchaseId']))->toBe(7_000)
            ->and(($this->onShelf)())->toBe(30_000);
    });
});
