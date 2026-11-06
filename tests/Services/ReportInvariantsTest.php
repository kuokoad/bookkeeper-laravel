<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/report-invariants.test.ts: the reports
| must agree with each other and with the ledger they all read, through a
| messy week (an overpayment, a return, a part-settled supplier, drawings, a
| void), with Ghana's taxes, and with stock allowed to go negative. The whole
| old file is ported; nothing is deferred.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Money;
use App\Enums\AdjustmentReason;
use App\Services\CashbookService;
use App\Services\CustomerPaymentService;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\FinancialService;
use App\Services\Reporting\LedgerService;
use App\Services\Reporting\OperationsService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use App\Services\SupplierPaymentService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const RI_DAY = '2026-08-10';
const RI_LATER = '2026-08-17';

/** Everything that must be true, whatever the shop did. */
function riExpectReportsAgree(string $label): void
{
    expect(BalancesService::getTrialBalance()['balanced'])->toBeTrue("{$label}: trial balance");

    $sheet = FinancialService::getBalanceSheet(RI_LATER);
    expect($sheet['balances'])->toBeTrue("{$label}: balance sheet balances")
        ->and($sheet['difference'])->toBe(0, "{$label}: balance sheet difference")
        ->and(InventoryService::getInventoryValue())->toBe(BalancesService::getAccountBalanceByCode(ChartOfAccounts::INVENTORY), "{$label}: stock vs Inventory account");

    $integrity = LedgerService::checkBooksIntegrity();
    expect($integrity['receivablesMatch'])->toBeTrue("{$label}: A/R subledger vs control")
        ->and($integrity['payablesMatch'])->toBeTrue("{$label}: A/P subledger vs control")
        ->and($integrity['untracedEntries'])->toBe(0, "{$label}: untraced entries");

    foreach (DB::table('products')->pluck('id') as $id) {
        expect(InventoryService::verifyProductStock((int) $id)['ok'])->toBeTrue("{$label}: stock drift p{$id}");
    }

    // Over ALL time: a void dates its reversal on the day it is made.
    $period = ['from' => '0000-01-01', 'to' => '9999-12-31'];
    $held = Money::sum(array_column(BalancesService::getPaymentAccountBalances(), 'balance'));
    expect(FinancialService::getCashFlow($period)['closingBalance'])->toBe($held, "{$label}: cash flow vs payment accounts")
        ->and(array_sum(array_column(OperationsService::getSalesByDay($period), 'net')))->toBe(FinancialService::getProfitAndLoss($period)['netSales'], "{$label}: sales by day vs P&L net sales");
}

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->momo = Shop::paymentAccount('MOBILE_MONEY');
    $this->customer = Shop::customer($this->actor, 'Mensah Provisions');
    $this->supplier = Shop::supplier($this->actor, 'Kofi Wholesale');
    $this->firstItemOf = fn (int $saleId): int => (int) DB::table('sale_items')->where('sale_id', $saleId)->orderBy('id')->value('id');
    $this->sell = fn (string $date, int $product, int $units, array $tenders, ?int $customer = null): array => SaleService::createSale([
        'businessDate' => $date,
        'customerId' => $customer,
        'items' => [['productId' => $product, 'qty' => $units * 1000]],
        'tenders' => $tenders,
    ], $this->actor);
    $this->deliver = fn (string $date, int $product, int $units, int $unitCost, int $paid) => PurchaseService::createPurchase([
        'businessDate' => $date,
        'supplierId' => $this->supplier,
        'items' => [['productId' => $product, 'qty' => $units * 1000, 'unitCost' => $unitCost]],
        'tenders' => [['paymentAccountId' => $this->cash, 'amount' => $paid]],
    ], $this->actor);
    $this->stockUp = function (): array {
        $rice = Shop::product($this->actor, 'Rice 5kg', 6_000, 10_000, ['unit' => 'bag']);
        $oil = Shop::product($this->actor, 'Oil 1L', 2_000, 3_500);
        StockAdjustmentService::createStockAdjustment([
            'businessDate' => RI_DAY,
            'reason' => AdjustmentReason::OpeningStock,
            'items' => [
                ['productId' => $rice, 'direction' => 'IN', 'qty' => 20_000, 'totalCost' => 120_000],
                ['productId' => $oil, 'direction' => 'IN', 'qty' => 30_000, 'totalCost' => 60_000],
            ],
        ], $this->actor);

        return [$rice, $oil];
    };
    $this->returnTwo = fn (int $saleId) => ReturnsService::createCustomerReturn($saleId, [
        'businessDate' => RI_LATER,
        'items' => [['itemId' => ($this->firstItemOf)($saleId), 'qty' => 2_000]],
        'refunds' => [],
    ], $this->actor);
});

describe('a messy but ordinary week', function () {
    it('leaves every report agreeing with every other', function () {
        [$rice, $oil] = ($this->stockUp)();
        riExpectReportsAgree('after opening stock');

        Shop::capital($this->actor, $this->cash, 50_000, RI_DAY);
        ($this->deliver)(RI_DAY, $rice, 10, 6_500, 30_000);
        riExpectReportsAgree('after a part-paid delivery');

        ($this->sell)(RI_DAY, $oil, 4, [['paymentAccountId' => $this->momo, 'amount' => 14_000]]);
        $credit = ($this->sell)(RI_DAY, $rice, 5, [], $this->customer);
        riExpectReportsAgree('after two sales');

        // The shop allows advance payments, so the customer ends up in credit.
        Shop::setting('allow_overpayment', true);
        CustomerPaymentService::recordCustomerPayment(['businessDate' => RI_LATER, 'customerId' => $this->customer, 'paymentAccountId' => $this->cash, 'amount' => 60_000], $this->actor);
        riExpectReportsAgree('after an overpayment');

        ($this->returnTwo)($credit['saleId']);
        riExpectReportsAgree('after a return');

        SupplierPaymentService::recordSupplierPayment(['supplierId' => $this->supplier, 'businessDate' => RI_LATER, 'paymentAccountId' => $this->cash, 'amount' => 20_000], $this->actor);
        CashbookService::recordExpense(['businessDate' => RI_LATER, 'categoryAccountId' => Shop::accountId('6010'), 'description' => 'Shop rent', 'amount' => 15_000, 'paymentAccountId' => $this->cash], $this->actor);
        // 4290 Miscellaneous Income: a postable leaf, not the heading above it.
        CashbookService::recordIncome(['businessDate' => RI_LATER, 'categoryAccountId' => Shop::accountId('4290'), 'description' => 'Sold empty sacks', 'amount' => 3_000, 'paymentAccountId' => $this->cash], $this->actor);
        riExpectReportsAgree('after costs and other income');

        CashbookService::recordOwnerDrawings(['businessDate' => RI_LATER, 'paymentAccountId' => $this->cash, 'amount' => 10_000], $this->actor);
        $mistake = ($this->sell)(RI_LATER, $oil, 3, [['paymentAccountId' => $this->cash, 'amount' => 10_500]]);
        SaleService::voidSale($mistake['saleId'], 'Rang up against the wrong customer', $this->actor);

        riExpectReportsAgree('after the whole week');
    });

    it("holds when the shop charges Ghana's three taxes", function () {
        Shop::setGhanaTaxes();
        [$rice, $oil] = ($this->stockUp)();
        riExpectReportsAgree('taxed: after opening stock');

        Shop::capital($this->actor, $this->cash, 200_000, RI_DAY);
        ($this->deliver)(RI_DAY, $rice, 10, 6_500, 30_000);
        riExpectReportsAgree('taxed: after a part-paid delivery');

        ($this->sell)(RI_DAY, $oil, 4, [['paymentAccountId' => $this->momo, 'amount' => 16_800]]);
        $credit = ($this->sell)(RI_DAY, $rice, 5, [], $this->customer);
        riExpectReportsAgree('taxed: after two sales');

        ($this->returnTwo)($credit['saleId']);
        riExpectReportsAgree('taxed: after a return');

        // GHS 105.00 of oil carries GHS 21.01 of tax: each levy rounds on its own base.
        $mistake = ($this->sell)(RI_LATER, $oil, 3, [['paymentAccountId' => $this->cash, 'amount' => 12_601]]);
        SaleService::voidSale($mistake['saleId'], 'Rang up against the wrong customer', $this->actor);
        riExpectReportsAgree('taxed: after the whole week');

        $nhil = BalancesService::getAccountBalanceByCode(ChartOfAccounts::NHIL_PAYABLE);
        $getfund = BalancesService::getAccountBalanceByCode(ChartOfAccounts::GETFUND_PAYABLE);
        $vat = BalancesService::getAccountBalanceByCode(ChartOfAccounts::TAX_PAYABLE);
        $charged = fn (string $code): int => (int) DB::table('sale_taxes')->where('code', $code)->sum('amount_minor');
        $reclaimed = fn (string $code): int => (int) DB::table('purchase_taxes')->where('code', $code)->where('is_recoverable', true)->sum('amount_minor');

        expect($nhil)->toBe($charged('NHIL') - $reclaimed('NHIL'), 'NHIL: collected less reclaimed')
            ->and($getfund)->toBe($charged('GETFUND') - $reclaimed('GETFUND'), 'GETFund: collected less reclaimed')
            ->and($charged('NHIL'))->toBeGreaterThan(0, 'NHIL actually collected')
            ->and($reclaimed('NHIL'))->toBeGreaterThan(0, 'and actually reclaimed on the delivery')
            ->and($getfund)->toBe($nhil, 'the two levies are the same rate on the same movements')
            // VAT nets negative here: the delivery reclaimed more than the sales collected.
            ->and($vat)->toBe($charged('VAT') - $reclaimed('VAT'), 'VAT collected less VAT reclaimed')
            ->and($reclaimed('VAT'))->toBeGreaterThan(0, 'VAT was reclaimed on the delivery')
            ->and($vat)->toBeLessThan(0, 'and the authority owes this shop');
    });

    it('holds when stock is allowed to go negative', function () {
        Shop::setting('allow_negative_stock', true);
        $rice = Shop::product($this->actor, 'Rice 5kg', 6_000, 10_000, ['unit' => 'bag']);
        Shop::openingStock($this->actor, $rice, 2_000, 12_000, RI_DAY);

        // Sell more than is on the shelf: the shop knows more is coming.
        ($this->sell)(RI_DAY, $rice, 5, [['paymentAccountId' => $this->cash, 'amount' => 50_000]]);
        riExpectReportsAgree('while oversold');

        ($this->deliver)(RI_LATER, $rice, 10, 6_500, 65_000);
        riExpectReportsAgree('after the delivery caught up');
    });
});
