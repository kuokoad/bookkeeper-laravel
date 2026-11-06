<?php

declare(strict_types=1);

/*
| The figures screens used to add up themselves (Phase 9b), now service
| reads: the trial balance's gross totals, the ageing buckets, the sales and
| purchase report headlines, money held, the reconciliation headline, the
| supplier page totals, a purchase's "Paid", the product form's stored
| carton prices and the customer statement. Each keeps the old page's
| semantics: where it summed only the rows shown, so does the service.
*/

use App\Services\CatalogService;
use App\Services\CustomerPaymentService;
use App\Services\PaymentAccountService;
use App\Services\PurchaseService;
use App\Services\ReconciliationService;
use App\Services\Reporting\LedgerService;
use App\Services\Reporting\OperationsService;
use App\Services\SaleService;
use App\Services\SupplierPaymentService;
use App\Services\SupplierService;
use App\Services\UnitsService;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->rice = Shop::product($this->actor, 'Rice 5kg', 6_000, 10_000);
    Shop::openingStock($this->actor, $this->rice, 50_000, 300_000, '2026-01-01');
});

it('totals the trial balance gross, debits equal credits, headings left out', function () {
    Shop::sell($this->actor, [[$this->rice, 2_000]], [['paymentAccountId' => $this->cash, 'amount' => 20_000]], ['businessDate' => '2026-03-01']);

    $tb = LedgerService::getGrossTrialBalance(['to' => '2026-03-31']);
    expect($tb['totalDebit'])->toBe(array_sum(array_column($tb['rows'], 'totalDebit')))
        ->and($tb['totalDebit'])->toBe($tb['totalCredit'])
        ->and($tb['difference'])->toBe(0)
        ->and($tb['balanced'])->toBeTrue()
        ->and(array_filter($tb['rows'], fn (array $r): bool => $r['isHeader']))->toBe([]);
});

it('adds up the ageing buckets of the rows it is given, and narrows by party and status', function () {
    $rows = [
        ['partyId' => 1, 'current' => 1_000, 'days1to30' => 0, 'days31to60' => 0, 'days61to90' => 0, 'over90' => 500, 'total' => 1_500],
        ['partyId' => 2, 'current' => 0, 'days1to30' => 2_000, 'days31to60' => 0, 'days61to90' => 0, 'over90' => 0, 'total' => 2_000],
    ];

    expect(LedgerService::getAgeingTotals($rows))->toBe(['current' => 1_000, 'days1to30' => 2_000, 'days31to60' => 0, 'days61to90' => 0, 'over90' => 500, 'total' => 3_500])
        ->and(LedgerService::getAgeingTotals([])['total'])->toBe(0)
        ->and(array_column(LedgerService::narrowAgeing($rows, null, 'overdue'), 'partyId'))->toBe([1])
        ->and(array_column(LedgerService::narrowAgeing($rows, null, 'current'), 'partyId'))->toBe([2])
        ->and(array_column(LedgerService::narrowAgeing($rows, 2, null), 'partyId'))->toBe([2]);
});

it('gives the sales report headline from its day and payment rows', function () {
    $days = [
        ['revenue' => 10_000, 'cost' => 6_000, 'profit' => 4_000, 'saleCount' => 2],
        ['revenue' => 5_001, 'cost' => 3_000, 'profit' => 2_001, 'saleCount' => 1],
    ];
    $totals = OperationsService::getSalesReportTotals($days, [['received' => 9_000], ['received' => 6_001]]);

    expect($totals)->toBe(['totalSales' => 15_001, 'totalCost' => 9_000, 'totalProfit' => 6_001, 'saleCount' => 3, 'averageSale' => 5_000, 'totalTaken' => 15_001])
        // A day of returns only: no sales to average over, so the average is the total.
        ->and(OperationsService::getSalesReportTotals([['revenue' => -2_000, 'cost' => -1_200, 'profit' => -800, 'saleCount' => 0]])['averageSale'])->toBe(-2_000)
        ->and(OperationsService::getPurchasesReportTotals([['total' => 30_000, 'returned' => 0, 'net' => 30_000, 'purchaseCount' => 2], ['total' => 4_500, 'returned' => 450, 'net' => 4_050, 'purchaseCount' => 1]]))
        ->toBe(['total' => 34_500, 'returned' => 450, 'net' => 34_050, 'count' => 3]);
});

it('agrees with the report rows the screen shows', function () {
    Shop::sell($this->actor, [[$this->rice, 1_000]], [['paymentAccountId' => $this->cash, 'amount' => 10_000]], ['businessDate' => '2026-03-01']);
    Shop::sell($this->actor, [[$this->rice, 2_000]], [['paymentAccountId' => $this->cash, 'amount' => 20_000]], ['businessDate' => '2026-03-02']);
    $q = ['from' => '2026-03-01', 'to' => '2026-03-31'];
    $days = array_map(fn (array $r): array => ['saleCount' => $r['saleCount'], 'revenue' => $r['total'], 'cost' => $r['cogs'], 'profit' => $r['profit']], OperationsService::getSalesByDay($q));

    $totals = OperationsService::getSalesReportTotals($days, OperationsService::getSalesByPaymentMethod($q));
    expect($totals['totalSales'])->toBe(30_000)
        ->and($totals['saleCount'])->toBe(2)
        ->and($totals['averageSale'])->toBe(15_000)
        ->and($totals['totalCost'])->toBe(18_000)
        ->and($totals['totalTaken'])->toBe(30_000);
});

it('counts money held in active accounts only', function () {
    Shop::sell($this->actor, [[$this->rice, 1_000]], [['paymentAccountId' => $this->cash, 'amount' => 10_000]], ['businessDate' => '2026-03-01']);
    $accounts = PaymentAccountService::listPaymentAccounts(true);
    $archived = [...$accounts, ['isActive' => false, 'balance' => 99_999]];

    expect(PaymentAccountService::getTotalHeld($accounts))->toBe(array_sum(array_column(array_filter($accounts, fn (array $a): bool => $a['isActive']), 'balance')))
        ->and(PaymentAccountService::getTotalHeld($archived))->toBe(PaymentAccountService::getTotalHeld($accounts))
        ->and(PaymentAccountService::getTotalHeld($accounts))->toBe(10_000);
});

it('heads the reconciliation page with unresolved differences and accounts never counted', function () {
    $overview = [
        ['unresolvedDifference' => -500, 'lastCountedOn' => '2026-03-01'],
        ['unresolvedDifference' => 200, 'lastCountedOn' => null],
        ['unresolvedDifference' => 0, 'lastCountedOn' => null],
    ];

    expect(ReconciliationService::getOverviewTotals($overview))->toBe(['unresolved' => -300, 'neverCounted' => 2])
        // The live preview and the saved count use one rule: counted less the books.
        ->and(ReconciliationService::countDifference(9_500, 10_000))->toBe(-500)
        ->and(ReconciliationService::countDifference(10_000, 10_000))->toBe(0);
});

it('totals what was bought from a supplier and paid later, posted only, and what a purchase paid', function () {
    $supplier = Shop::supplier($this->actor);
    $paidAtDelivery = Shop::purchase($this->actor, $supplier, [[$this->rice, 10_000, 6_000]], [['paymentAccountId' => $this->cash, 'amount' => 25_000]])['purchaseId'];
    $voided = Shop::purchase($this->actor, $supplier, [[$this->rice, 1_000, 6_000]])['purchaseId'];
    PurchaseService::voidPurchase($voided, 'Wrong shop', $this->actor);
    Shop::capital($this->actor, $this->cash, 100_000);
    SupplierPaymentService::recordSupplierPayment(['businessDate' => '2026-08-18', 'supplierId' => $supplier, 'paymentAccountId' => $this->cash, 'amount' => 5_000], $this->actor);

    $totals = SupplierService::getActivityTotals($supplier);
    expect($totals)->toBe(['totalBought' => 60_000, 'totalPaid' => 5_000])
        ->and(PurchaseService::getPurchase($paidAtDelivery)['paidMinor'])->toBe(25_000);
});

it('gives the product form the carton prices as saved, blank when derived', function () {
    UnitsService::createUnit(['symbol' => 'carton', 'name' => 'Carton', 'decimalPlaces' => 0], $this->actor);
    $set = Shop::product($this->actor, 'Milk', 400, 500, ['altUnit' => 'carton', 'altFactor' => 24_000, 'altSellingPrice' => 11_000]);
    $derived = Shop::product($this->actor, 'Juice', 400, 500, ['altUnit' => 'carton', 'altFactor' => 24_000]);

    expect(CatalogService::getProduct($set))->toMatchArray(['storedAltSellingPrice' => 11_000, 'storedAltCostPrice' => null, 'altSellingPrice' => 11_000])
        ->and(CatalogService::getProduct($derived))->toMatchArray(['storedAltSellingPrice' => null, 'altSellingPrice' => 12_000]);
});

it('builds a customer statement in one read: open invoices dated by today, numbered, with due dates', function () {
    $customer = Shop::customer($this->actor, 'Mensah Provisions');
    $credit = fn (string $date, int $qty): int => SaleService::createSale(['businessDate' => $date, 'customerId' => $customer, 'termsDays' => 30, 'items' => [['productId' => $this->rice, 'qty' => $qty]], 'tenders' => []], $this->actor)['saleId'];
    $march = $credit('2026-03-01', 1_000);
    $paidOff = $credit('2026-03-05', 1_000);
    CustomerPaymentService::recordCustomerPayment(['businessDate' => '2026-03-06', 'customerId' => $customer, 'paymentAccountId' => $this->cash, 'amount' => 10_000, 'allocations' => [['saleId' => $paidOff, 'amount' => 10_000]]], $this->actor);
    $later = $credit('2026-05-01', 2_000);

    $statement = CustomerPaymentService::getCustomerStatement($customer, '2026-04-15');
    $sale = SaleService::getSale($march);
    expect(array_column($statement['rows'], 'id'))->toBe([$march])
        ->and($statement['rows'][0])->toMatchArray(['document' => $sale['invoiceNo'], 'dueDate' => '2026-03-31', 'overdueBy' => 15, 'outstanding' => 10_000]);

    // Dated today or before only; the total is the ledger's balance, not the rows.
    $all = CustomerPaymentService::getCustomerStatement($customer, '2026-05-31');
    expect(array_column($all['rows'], 'id'))->toBe([$march, $later])
        ->and($all['rowsTotal'])->toBe(30_000)
        ->and($all['balance'])->toBe(30_000)
        ->and($all['agrees'])->toBeTrue();
});
