<?php

declare(strict_types=1);

/*
| The owner's decisions D22 to D24 (PLAN.md §10, approved 2026-11-06): figures
| the Phase 9 click-through found the old app getting wrong, each with the
| example the plan gave. D20 and D21 are in the till, sales and accounts
| screen tests.
*/

use App\Livewire\Customers\Show as CustomerShow;
use App\Livewire\Suppliers\Show as SupplierShow;
use App\Models\User;
use App\Reports\Csv\ReportExport;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\PurchaseService;
use App\Services\Reporting\OperationsService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use App\Services\SupplierPaymentService;
use App\Services\SupplierService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    Shop::setNoTax();
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('D22: supplier returns on the purchase report', function () {
    beforeEach(function () {
        $this->supplier = Shop::supplier($this->actor, 'Depot');
        $this->rice = Shop::product($this->actor, 'Rice 5kg', 45_000, 50_000);
        // 4,500.00 bought on credit on the 17th; one bag (450.00) sent back on the 20th.
        $this->purchaseId = Shop::purchase($this->actor, $this->supplier, [[$this->rice, 10_000, 45_000]])['purchaseId'];
        ReturnsService::createSupplierReturn($this->purchaseId, [
            'businessDate' => '2026-08-20',
            'items' => [['itemId' => PurchaseService::getPurchase($this->purchaseId)['items'][0]['id'], 'qty' => 1_000]],
            'refunds' => [],
        ], $this->actor);
        $this->august = ['from' => '2026-08-01', 'to' => '2026-08-31'];
    });

    it('shows 4,500.00 bought and 450.00 returned as Returned 450.00 and Net 4,050.00, agreeing with what the supplier is owed', function () {
        $byDay = OperationsService::getPurchasesByDay($this->august);
        $figures = fn (array $rows): array => array_map(fn (array $r): array => [$r['purchaseCount'], $r['total'], $r['returned'], $r['net']], $rows);

        // The return is dated by its own day, not the purchase's.
        expect(array_column($byDay, 'businessDate'))->toBe(['2026-08-17', '2026-08-20'])
            ->and($figures($byDay))->toBe([[1, 450_000, 0, 450_000], [0, 0, 45_000, -45_000]])
            ->and(OperationsService::getPurchasesReportTotals($byDay))->toBe(['total' => 450_000, 'returned' => 45_000, 'net' => 405_000, 'count' => 1])
            ->and($figures(OperationsService::getPurchasesBySupplier($this->august)))->toBe([[1, 450_000, 45_000, 405_000]]);

        $product = OperationsService::getPurchasesByProduct($this->august);
        expect(array_map(fn (array $r): array => [$r['unit'], $r['qtyBought'], $r['total'], $r['returned'], $r['net']], $product))->toBe([['pcs', 10_000, 450_000, 45_000, 405_000]])
            // Nothing paid yet: the net is the supplier balance's movement.
            ->and(SupplierService::getSupplierBalance($this->supplier))->toBe(405_000);
    });

    it('totals the Returned column under the same filter as the rows', function () {
        $other = Shop::supplier($this->actor, 'Other depot');
        $sugar = Shop::product($this->actor, 'Sugar 1kg', 1_000, 1_500);
        Shop::purchase($this->actor, $other, [[$sugar, 5_000, 1_000]]);

        $onlyDepot = OperationsService::getPurchasesReportTotals(OperationsService::getPurchasesByDay([...$this->august, 'supplierId' => $this->supplier]));
        $onlySugar = OperationsService::getPurchasesReportTotals(OperationsService::getPurchasesByDay([...$this->august, 'productId' => $sugar]));
        $returnDayOnly = OperationsService::getPurchasesReportTotals(OperationsService::getPurchasesByDay(['from' => '2026-08-20', 'to' => '2026-08-20']));

        expect($onlyDepot)->toBe(['total' => 450_000, 'returned' => 45_000, 'net' => 405_000, 'count' => 1])
            ->and($onlySugar)->toBe(['total' => 5_000, 'returned' => 0, 'net' => 5_000, 'count' => 1])
            ->and($returnDayOnly)->toBe(['total' => 0, 'returned' => 45_000, 'net' => -45_000, 'count' => 0]);
    });

    it('gives the CSV the Returned and Net columns in each view', function () {
        $csv = ReportExport::download('purchases', $this->august, '2026-08-31');
        $rows = array_map(fn (array $r): array => array_slice($r, 0, 6), $csv['rows']);

        expect($csv['headers'])->toBe(['Section', 'Name', 'Count/Qty', 'Total', 'Returned', 'Net'])
            ->and($rows)->toBe([
                ['By day', '2026-08-17', '1', '4500.00', '0.00', '4500.00'],
                ['By day', '2026-08-20', '0', '0.00', '450.00', '-450.00'],
                ['By supplier', 'Depot', '1', '4500.00', '450.00', '4050.00'],
                ['By product', 'Rice 5kg (pcs)', '10', '4500.00', '450.00', '4050.00'],
            ]);
    });

    it('shows Returned and Net on the purchase report page', function () {
        signedIn($this->actor->id)->get('/reports/purchases?period=all')->assertOk()
            ->assertSeeInOrder(['Total bought', 'GHS 4,500.00', 'Returned', 'GHS 450.00', 'Net bought', 'GHS 4,050.00']);
    });
});

describe('D23: the sales report daily sale count', function () {
    beforeEach(function () {
        $this->rice = Shop::product($this->actor, 'Rice 5kg', 1_000, 10_000);
        Shop::openingStock($this->actor, $this->rice, 100_000, 100_000, '2026-01-01');
        $this->sellOn = fn (string $date): int => Shop::sell($this->actor, [[$this->rice, 1_000]], [['paymentAccountId' => $this->cash, 'amount' => 10_000]], ['businessDate' => $date])['saleId'];
    });

    it('counts three sales on 03 Nov with one voided as 2, beside the takings of two', function () {
        ($this->sellOn)('2026-11-03');
        ($this->sellOn)('2026-11-03');
        $voided = ($this->sellOn)('2026-11-03');
        SaleService::voidSale($voided, 'Rung up twice', $this->actor, Carbon::parse('2026-11-03 15:00:00'));
        $november = ['from' => '2026-11-01', 'to' => '2026-11-30'];

        $day = OperationsService::getSalesByDay($november)[0];
        expect([$day['businessDate'], $day['saleCount'], $day['total']])->toBe(['2026-11-03', 2, 20_000])
            ->and(OperationsService::getSalesLinesByDay($november)[0]['saleCount'])->toBe(2)
            ->and(OperationsService::getSalesByCustomer($november)[0]['saleCount'])->toBe(2);
    });

    it('counts a March sale in March when its void is dated in April, and nowhere once the period takes in the void', function () {
        $sale = ($this->sellOn)('2026-03-10');
        SaleService::voidSale($sale, 'Customer changed mind', $this->actor, Carbon::parse('2026-04-05 09:00:00'));
        $count = fn (string $from, string $to): int => array_sum(array_column(OperationsService::getSalesByDay(['from' => $from, 'to' => $to]), 'saleCount'));
        $lineCount = fn (string $from, string $to): int => array_sum(array_column(OperationsService::getSalesLinesByDay(['from' => $from, 'to' => $to]), 'saleCount'));

        expect($count('2026-03-01', '2026-03-31'))->toBe(1)->and($lineCount('2026-03-01', '2026-03-31'))->toBe(1)
            ->and($count('2026-04-01', '2026-04-30'))->toBe(0)
            ->and($count('2026-03-01', '2026-04-30'))->toBe(0)->and($lineCount('2026-03-01', '2026-04-30'))->toBe(0);
    });
});

describe('D24: all-time totals on the customer and supplier pages', function () {
    it('shows Total bought 1,500.00 for 150 sales of 10.00, not the latest 100, and paid later over every payment', function () {
        $rice = Shop::product($this->actor, 'Rice 5kg', 500, 1_000);
        Shop::openingStock($this->actor, $rice, 200_000, 100_000, '2026-01-01');
        $regular = Shop::customer($this->actor, 'Adwoa Mensah');
        for ($i = 0; $i < 150; $i++) {
            Shop::sell($this->actor, [[$rice, 1_000]], [], ['customerId' => $regular]);
        }
        // A voided sale and its void mirror both drop out, as the balance has it.
        $voided = Shop::sell($this->actor, [[$rice, 1_000]], [], ['customerId' => $regular]);
        SaleService::voidSale($voided['saleId'], 'Rung up twice', $this->actor);
        for ($i = 0; $i < 101; $i++) {
            CustomerPaymentService::recordCustomerPayment(['businessDate' => '2026-08-16', 'customerId' => $regular, 'paymentAccountId' => $this->cash, 'amount' => 100], $this->actor);
        }

        expect(CustomerService::getActivityTotals($regular))->toBe(['totalBought' => 150_000, 'totalPaid' => 10_100])
            ->and(CustomerService::getCustomerBalance($regular))->toBe(150_000 - 10_100);
        Livewire::actingAs(User::query()->findOrFail($this->actor->id))->test(CustomerShow::class, ['id' => $regular])
            ->assertSeeInOrder(['Total bought', '1,500.00', 'Total paid later', '101.00']);
    });

    it('nets a return out of the customer\'s Total bought, as the balance does', function () {
        $rice = Shop::product($this->actor, 'Rice 5kg', 500, 1_000);
        Shop::openingStock($this->actor, $rice, 10_000, 5_000, '2026-01-01');
        $customer = Shop::customer($this->actor, 'Ama Serwaa');
        $sale = Shop::sell($this->actor, [[$rice, 3_000]], [], ['customerId' => $customer]);
        ReturnsService::createCustomerReturn($sale['saleId'], [
            'businessDate' => '2026-08-16',
            'items' => [['itemId' => SaleService::getSale($sale['saleId'])['items'][0]['id'], 'qty' => 1_000]],
            'refunds' => [],
        ], $this->actor);

        expect(CustomerService::getActivityTotals($customer)['totalBought'])->toBe(2_000)
            ->and(CustomerService::getCustomerBalance($customer))->toBe(2_000);
    });

    it('totals a supplier\'s purchases and payments over all of them, not the 100 the page lists', function () {
        $rice = Shop::product($this->actor, 'Rice 5kg', 1_000, 1_500);
        $supplier = Shop::supplier($this->actor);
        for ($i = 0; $i < 101; $i++) {
            Shop::purchase($this->actor, $supplier, [[$rice, 1_000, 1_000]]);
        }
        Shop::capital($this->actor, $this->cash, 100_000);
        for ($i = 0; $i < 101; $i++) {
            SupplierPaymentService::recordSupplierPayment(['businessDate' => '2026-08-18', 'supplierId' => $supplier, 'paymentAccountId' => $this->cash, 'amount' => 100], $this->actor);
        }

        expect(SupplierService::getActivityTotals($supplier))->toBe(['totalBought' => 101_000, 'totalPaid' => 10_100]);
        Livewire::actingAs(User::query()->findOrFail($this->actor->id))->test(SupplierShow::class, ['id' => (string) $supplier])
            ->assertSeeInOrder(['Total bought', '1,010.00', 'Total paid later', '101.00']);
    });

    it('nets a supplier return out of Total bought, as the purchase report\'s Net and the balance do', function () {
        $rice = Shop::product($this->actor, 'Rice 5kg', 45_000, 50_000);
        $supplier = Shop::supplier($this->actor);
        $purchaseId = Shop::purchase($this->actor, $supplier, [[$rice, 10_000, 45_000]])['purchaseId'];
        ReturnsService::createSupplierReturn($purchaseId, [
            'businessDate' => '2026-08-20',
            'items' => [['itemId' => PurchaseService::getPurchase($purchaseId)['items'][0]['id'], 'qty' => 1_000]],
            'refunds' => [],
        ], $this->actor);

        expect(SupplierService::getActivityTotals($supplier))->toBe(['totalBought' => 405_000, 'totalPaid' => 0])
            ->and(SupplierService::getSupplierBalance($supplier))->toBe(405_000);
    });
});
