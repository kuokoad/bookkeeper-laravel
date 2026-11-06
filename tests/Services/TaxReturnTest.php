<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/tax-return.test.ts: what the shop hands
| to the GRA, one row per tax, adjusted in the period a cancellation happens
| and reclaiming only what was reclaimable on the day. A void dates its mirror
| from the clock, so "this month" is the month the suite runs in, as the old
| helpers/clock thisMonth(). The whole old file is ported; nothing is deferred.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\JsCompat;
use App\Services\PurchaseService;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\TaxReturnService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const TR_AUGUST = ['from' => '2026-08-01', 'to' => '2026-08-31'];
const TR_SEPTEMBER = ['from' => '2026-09-01', 'to' => '2026-09-30'];
const TR_JULY = ['from' => '2026-07-01', 'to' => '2026-07-31'];
const TR_WIDE = ['from' => '2026-01-01', 'to' => '2099-12-31'];

/** Ghana's three; leviesRecoverable false is the law before Act 1151. */
function trGhanaTaxes(bool $leviesRecoverable = true): void
{
    Shop::setGhanaTaxes();
    DB::table('tax_components')->update(['is_recoverable' => true]);
    if (! $leviesRecoverable) {
        DB::table('tax_components')->where('code', '<>', 'VAT')->update(['is_recoverable' => false]);
    }
}

/** @return array<string, array> components by code */
function trByCode(array $period): array
{
    return array_column(TaxReturnService::getTaxReturn($period)['components'], null, 'code');
}

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->supplier = Shop::supplier($this->actor, 'Kofi Wholesale');
    trGhanaTaxes();
    $this->rice = fn (): int => Shop::product($this->actor, 'Rice 5kg', 1_000, 2_000, ['unit' => 'bag']);
    $this->buy = fn (int $product, string $date, int $units = 10, int $unitCost = 1_000): array => PurchaseService::createPurchase([
        'businessDate' => $date,
        'supplierId' => $this->supplier,
        'items' => [['productId' => $product, 'qty' => $units * 1000, 'unitCost' => $unitCost]],
        'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 0]],
    ], $this->actor);
    // A cash sale paid in full: 20.00 a bag plus 20% across the three taxes.
    $this->sell = function (int $product, string $date, int $units = 2): array {
        $net = $units * 2_000;

        return SaleService::createSale([
            'businessDate' => $date,
            'items' => [['productId' => $product, 'qty' => $units * 1000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => $net + JsCompat::round($net * 0.2)]],
        ], $this->actor);
    };
});

describe('the three taxes, kept apart', function () {
    it('reports each component separately rather than as one figure', function () {
        $rice = ($this->rice)();
        ($this->buy)($rice, '2026-08-05');
        ($this->sell)($rice, '2026-08-10');

        $codes = array_column(TaxReturnService::getTaxReturn(TR_AUGUST)['components'], 'code');
        sort($codes);
        $byCode = trByCode(TR_AUGUST);
        // 2 bags at 20.00 = 40.00 net. NHIL and GETFund at 2.5%, VAT at 15%.
        expect($codes)->toBe(['GETFUND', 'NHIL', 'VAT'])
            ->and($byCode['NHIL']['outputMinor'])->toBe(100)
            ->and($byCode['GETFUND']['outputMinor'])->toBe(100)
            ->and($byCode['VAT']['outputMinor'])->toBe(600);
    });

    it('nets recoverable input tax off the output, component by component', function () {
        $rice = ($this->rice)();
        ($this->buy)($rice, '2026-08-05');
        ($this->sell)($rice, '2026-08-10');

        $vat = trByCode(TR_AUGUST)['VAT'];
        expect($vat['outputMinor'])->toBe(600)
            ->and($vat['recoverableInputMinor'])->toBe(1_500)
            ->and($vat['netMinor'])->toBe(-900);
    });

    it('adds up to what is owed, or what is owed back', function () {
        $rice = ($this->rice)();
        ($this->buy)($rice, '2026-08-05');
        ($this->sell)($rice, '2026-08-10');

        $period = TaxReturnService::getTaxReturn(TR_AUGUST);
        expect($period['totalOutput'])->toBe(800)
            ->and($period['totalRecoverableInput'])->toBe(2_000)
            ->and($period['netPayable'])->toBe(-1_200)
            ->and($period['netPayable'])->toBe($period['totalOutput'] - $period['totalRecoverableInput']);
    });

    it('says what the tax was charged on, which the return form asks for', function () {
        $rice = ($this->rice)();
        ($this->buy)($rice, '2026-08-05');
        ($this->sell)($rice, '2026-08-10');

        $period = TaxReturnService::getTaxReturn(TR_AUGUST);
        expect($period['taxableSalesMinor'])->toBe(4_000)
            ->and($period['taxablePurchasesMinor'])->toBe(10_000)
            ->and($period['saleCount'])->toBe(1)
            ->and($period['purchaseCount'])->toBe(1);
    });
});

describe('tax that was never reclaimable', function () {
    it('is reported, not quietly dropped', function () {
        trGhanaTaxes(leviesRecoverable: false);
        ($this->buy)(($this->rice)(), '2026-08-05');

        $byCode = trByCode(TR_AUGUST);
        expect($byCode['NHIL']['recoverableInputMinor'])->toBe(0)
            ->and($byCode['NHIL']['nonRecoverableInputMinor'])->toBe(250)
            ->and($byCode['VAT']['recoverableInputMinor'])->toBe(1_500);
    });

    it('never reclaims it, however the setting is changed afterwards', function () {
        trGhanaTaxes(leviesRecoverable: false);
        ($this->buy)(($this->rice)(), '2026-08-05');
        trGhanaTaxes(leviesRecoverable: true);

        $byCode = trByCode(TR_AUGUST);
        expect($byCode['NHIL']['recoverableInputMinor'])->toBe(0)
            ->and($byCode['NHIL']['nonRecoverableInputMinor'])->toBe(250);
    });
});

describe('goods that come back', function () {
    it('takes the tax off a customer return', function () {
        $rice = ($this->rice)();
        ($this->buy)($rice, '2026-08-05');
        $sale = ($this->sell)($rice, '2026-08-10', 2);

        $items = ReturnsService::getReturnableSaleItems($sale['saleId']);
        ReturnsService::createCustomerReturn($sale['saleId'], [
            'businessDate' => '2026-08-12',
            'items' => [['itemId' => $items[0]['id'], 'qty' => 1_000]],
            'refunds' => [['paymentAccountId' => $this->cash, 'amount' => 2_400]],
        ], $this->actor);

        // Half the sale came back, so half the output tax goes with it.
        expect(trByCode(TR_AUGUST)['VAT']['outputMinor'])->toBe(300);
    });

    it('STOPS reclaiming tax on goods sent back to the supplier', function () {
        $purchase = ($this->buy)(($this->rice)(), '2026-08-05', 10, 1_000);
        $items = ReturnsService::getReturnablePurchaseItems($purchase['purchaseId']);
        ReturnsService::createSupplierReturn($purchase['purchaseId'], ['businessDate' => '2026-08-08', 'items' => [['itemId' => $items[0]['id'], 'qty' => 4_000]]], $this->actor);

        $byCode = trByCode(TR_AUGUST);
        // 4 of 10 bags went back, so 40% of the input tax goes with them.
        expect($byCode['VAT']['recoverableInputMinor'])->toBe(900)
            ->and($byCode['NHIL']['recoverableInputMinor'])->toBe(150)
            // And the rows really exist, rather than the figure being derived.
            ->and(DB::table('purchase_taxes')->where('amount_minor', '<', 0)->count())->toBe(3);
    });

    it('carries the original recoverability onto the return, not today’s', function () {
        trGhanaTaxes(leviesRecoverable: false);
        $purchase = ($this->buy)(($this->rice)(), '2026-08-05');
        trGhanaTaxes(leviesRecoverable: true);

        $items = ReturnsService::getReturnablePurchaseItems($purchase['purchaseId']);
        ReturnsService::createSupplierReturn($purchase['purchaseId'], ['businessDate' => '2026-08-08', 'items' => [['itemId' => $items[0]['id'], 'qty' => 10_000]]], $this->actor);

        $byCode = trByCode(TR_AUGUST);
        expect($byCode['NHIL']['recoverableInputMinor'])->toBe(0)
            ->and($byCode['NHIL']['nonRecoverableInputMinor'])->toBe(0);
    });
});

describe('a sale cancelled in a later month', function () {
    it('stays declared in the month it was made', function () {
        $rice = ($this->rice)();
        ($this->buy)($rice, '2026-07-05');
        $sale = ($this->sell)($rice, '2026-07-10');

        expect(TaxReturnService::getTaxReturn(TR_JULY)['totalOutput'])->toBe(800);
        SaleService::voidSale($sale['saleId'], 'Rang it up twice', $this->actor);
        expect(TaxReturnService::getTaxReturn(TR_JULY)['totalOutput'])->toBe(800);
    });

    it('shows the adjustment in the month it was cancelled', function () {
        $rice = ($this->rice)();
        ($this->buy)($rice, '2026-07-05');
        $sale = ($this->sell)($rice, '2026-07-10');
        SaleService::voidSale($sale['saleId'], 'Rang it up twice', $this->actor);

        expect(TaxReturnService::getTaxReturn(Shop::monthOf(Clock::today()))['totalOutput'])->toBe(-800)
            ->and(TaxReturnService::getTaxReturn(TR_JULY)['totalOutput'])->toBe(800)
            ->and(TaxReturnService::getTaxReturn(TR_WIDE)['totalOutput'])->toBe(0);
    });

    it('nets a voided purchase back out of the reclaim', function () {
        $purchase = ($this->buy)(($this->rice)(), '2026-08-05');
        expect(TaxReturnService::getTaxReturn(TR_AUGUST)['totalRecoverableInput'])->toBe(2_000);

        PurchaseService::voidPurchase($purchase['purchaseId'], 'Invoiced twice', $this->actor);
        expect(TaxReturnService::getTaxReturn(TR_WIDE)['totalRecoverableInput'])->toBe(0);
    });
});

describe('what the return says against what the books say', function () {
    it('agrees with the tax accounts in the general ledger', function () {
        $rice = ($this->rice)();
        ($this->buy)($rice, '2026-08-05');
        ($this->sell)($rice, '2026-08-10');

        expect(BalancesService::getAccountBalanceByCode(ChartOfAccounts::TAX_PAYABLE))->toBe(trByCode(TR_WIDE)['VAT']['netMinor'])
            ->and(BalancesService::getTrialBalance()['balanced'])->toBeTrue();
    });

    it('returns an empty return rather than nothing for a quiet month', function () {
        $period = TaxReturnService::getTaxReturn(TR_SEPTEMBER);
        expect($period['components'])->toBe([])
            ->and($period['netPayable'])->toBe(0)
            ->and($period['saleCount'])->toBe(0);
    });
});
