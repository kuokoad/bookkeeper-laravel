<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/void-ageing.test.ts: a void's mirror is
| not a debt going the other way.
|
| Both ageing reports are read through LedgerService, and the ledger side
| through BalancesService::getAccountBalanceByCode with a to date, as the old
| test did. Nothing is deferred.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\CustomerService;
use App\Services\PurchaseService;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\LedgerService;
use App\Services\SaleService;
use App\Services\SupplierService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const VA_BEFORE_VOID = '2026-08-20';
const VA_VOID_DAY = '2026-09-05';
const VA_AFTER_VOID = '2026-09-30';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->voidAt = Carbon::parse(VA_VOID_DAY.' 10:00:00');
    $this->customer = Shop::customer($this->actor, 'Mensah Provisions');
    $this->supplier = Shop::supplier($this->actor, 'Kofi Traders');
    $this->product = Shop::product($this->actor, 'Rice 5kg', 3_000, 10_000, ['unit' => 'bag']);
    Shop::openingStock($this->actor, $this->product, 40_000, 120_000, '2026-08-01');
    $this->creditSale = fn (int $units, int $tendered = 0): array => SaleService::createSale([
        'businessDate' => '2026-08-10',
        'customerId' => $this->customer,
        'items' => [['productId' => $this->product, 'qty' => $units * 1000]],
        'tenders' => $tendered > 0 ? [['paymentAccountId' => $this->cash, 'amount' => $tendered]] : [],
    ], $this->actor);
    $this->creditPurchase = fn (int $units): array => Shop::purchase($this->actor, $this->supplier, [[$this->product, $units * 1000, 10_000]], [], ['businessDate' => '2026-08-10']);
    $this->agedPayables = fn (string $asAt): int => array_sum(array_column(LedgerService::getPayablesAgeing($asAt), 'total'));
    $this->aged = fn (string $asAt): int => array_sum(array_column(LedgerService::getReceivablesAgeing($asAt), 'total'));
});

describe('receivables ageing after a void', function () {
    it('shows what the customer really owes, not the void set against it', function () {
        ($this->creditSale)(5);
        $voided = ($this->creditSale)(10);
        SaleService::voidSale($voided['saleId'], 'wrong customer', $this->actor, $this->voidAt);

        expect(CustomerService::getCustomerBalance($this->customer))->toBe(50_000)
            ->and(($this->aged)(VA_AFTER_VOID))->toBe(50_000);
    });

    it('ignores a void written before mirrors were marked as voids', function () {
        ($this->creditSale)(5);
        $voided = ($this->creditSale)(10);
        $reversal = SaleService::voidSale($voided['saleId'], 'wrong customer', $this->actor, $this->voidAt)['reversalSaleId'];
        DB::table('sales')->where('id', $reversal)->update(['kind' => 'SALE']);

        expect(($this->aged)(VA_AFTER_VOID))->toBe(50_000)
            ->and(SaleService::getFilteredSalesSummary()['outstanding'])->toBe(50_000)
            ->and(SaleService::getSaleOutstanding($reversal))->toBe(0);
    });

    it('leaves nobody on the list when the only sale was voided', function () {
        $only = ($this->creditSale)(10);
        SaleService::voidSale($only['saleId'], 'wrong customer', $this->actor, $this->voidAt);

        expect(LedgerService::getReceivablesAgeing(VA_AFTER_VOID))->toBe([]);
    });

    it('counts only what was left unpaid on a part-paid sale, before the void', function () {
        $sale = ($this->creditSale)(10, 30_000);
        SaleService::voidSale($sale['saleId'], 'wrong customer', $this->actor, $this->voidAt);

        expect(($this->aged)(VA_BEFORE_VOID))->toBe(70_000)
            ->and(($this->aged)(VA_AFTER_VOID))->toBe(0);
    });

    it('agrees with the ledger as at a date', function (string $label, string $asAt) {
        ($this->creditSale)(5);
        $voided = ($this->creditSale)(10);
        SaleService::voidSale($voided['saleId'], 'wrong customer', $this->actor, $this->voidAt);

        expect(($this->aged)($asAt))->toBe(BalancesService::getAccountBalanceByCode(ChartOfAccounts::ACCOUNTS_RECEIVABLE, ['to' => $asAt]));
    })->with([
        ['before the void', VA_BEFORE_VOID],
        ['on the day of the void', VA_VOID_DAY],
        ['after the void', VA_AFTER_VOID],
    ]);
});

describe('the sales list after a void', function () {
    it('does not report a negative amount still owing', function () {
        ($this->creditSale)(5);
        $voided = ($this->creditSale)(10);
        $reversal = SaleService::voidSale($voided['saleId'], 'wrong customer', $this->actor, $this->voidAt)['reversalSaleId'];

        expect(SaleService::getFilteredSalesSummary()['outstanding'])->toBe(50_000)
            ->and(SaleService::getSaleOutstanding($reversal))->toBe(0);
    });
});

describe('payables ageing after a void', function () {
    it('shows what the shop really owes, not the void set against it', function () {
        ($this->creditPurchase)(10);
        $voided = ($this->creditPurchase)(5);
        PurchaseService::voidPurchase($voided['purchaseId'], 'wrong supplier', $this->actor, $this->voidAt);

        expect(SupplierService::getSupplierBalance($this->supplier))->toBe(100_000)
            ->and(($this->agedPayables)(VA_AFTER_VOID))->toBe(100_000);
    });

    it('agrees with the ledger as at a date', function (string $label, string $asAt) {
        ($this->creditPurchase)(10);
        $voided = ($this->creditPurchase)(5);
        PurchaseService::voidPurchase($voided['purchaseId'], 'wrong supplier', $this->actor, $this->voidAt);

        expect(($this->agedPayables)($asAt))->toBe(BalancesService::getAccountBalanceByCode(ChartOfAccounts::ACCOUNTS_PAYABLE, ['to' => $asAt]));
    })->with([
        ['before the void', VA_BEFORE_VOID],
        ['on the day of the void', VA_VOID_DAY],
        ['after the void', VA_AFTER_VOID],
    ]);

    it('does not report a negative amount owing on the purchases list', function () {
        ($this->creditPurchase)(10);
        $voided = ($this->creditPurchase)(5);
        $reversal = PurchaseService::voidPurchase($voided['purchaseId'], 'wrong supplier', $this->actor, $this->voidAt)['reversalPurchaseId'];

        expect(PurchaseService::getFilteredPurchasesSummary()['outstanding'])->toBe(100_000)
            ->and(PurchaseService::getPurchaseOutstanding($reversal))->toBe(0);
    });
});
