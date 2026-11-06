<?php

declare(strict_types=1);

/*
| Owner decisions D1 and D2 (PLAN.md §10, approved 2026-11-06): a supplier
| return undoes exactly what the purchase posted. Each tax goes back to its own
| account, a levy folded into stock cost leaves through the stock movement,
| and the goods leave at their own stock-ledger cost (invoice discount and
| folded levies included), with any costing residual posted as a void posts it.
| Ghana taxes: 10 bags at 65.00 = 650.00 net, NHIL 16.25, GETFund 16.25,
| VAT 97.50, 780.00 in all, on credit.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\PurchaseService;
use App\Services\Reporting\TaxReturnService;
use App\Services\ReturnsService;
use App\Services\SupplierPaymentService;
use App\Services\SupplierService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const SRET_DAY = '2026-08-17';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->supplier = Shop::supplier($this->actor, 'Kofi Wholesale');
    $this->rice = Shop::product($this->actor, 'Rice 5kg', 6_500, 10_000, ['unit' => 'bag']);
    $this->levyIsACost = fn () => DB::table('tax_components')->where('code', '<>', 'VAT')->update(['is_recoverable' => false]);
    $this->buy = fn (array $extra = [], ?int $product = null, int $unitCost = 6_500): array => Shop::purchase(
        $this->actor, $this->supplier, [[$product ?? $this->rice, 10_000, $unitCost]], [], ['businessDate' => SRET_DAY, ...$extra],
    );
    $this->sendBack = fn (int $purchaseId, int $units): array => ReturnsService::createSupplierReturn($purchaseId, [
        'businessDate' => SRET_DAY,
        'items' => [['itemId' => (int) DB::table('purchase_items')->where('purchase_id', $purchaseId)->orderBy('id')->value('id'), 'qty' => $units * 1_000]],
        'refunds' => [],
    ], $this->actor);
    $this->taxAccounts = fn (): array => [
        Shop::balance(ChartOfAccounts::TAX_PAYABLE),
        Shop::balance(ChartOfAccounts::NHIL_PAYABLE),
        Shop::balance(ChartOfAccounts::GETFUND_PAYABLE),
    ];
});

afterEach(function () {
    Shop::assertBooksHealthy('after the supplier return');
});

describe('D1: a supplier return reverses each tax to its own account', function () {
    it('brings VAT, NHIL and GETFund back to zero when reclaimable levies all go back', function () {
        Shop::setGhanaTaxes();
        $purchase = ($this->buy)();
        expect(($this->taxAccounts)())->toBe([-9_750, -1_625, -1_625]);

        $return = ($this->sendBack)($purchase['purchaseId'], 10);

        expect(($this->taxAccounts)())->toBe([0, 0, 0])
            ->and(Shop::balance(ChartOfAccounts::ACCOUNTS_PAYABLE))->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(0)
            ->and($return['creditApplied'])->toBe(78_000);
    });

    it('leaves no inventory value on an empty shelf when folded levies all go back', function () {
        Shop::setGhanaTaxes();
        ($this->levyIsACost)();
        $purchase = ($this->buy)();
        expect(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(68_250);

        ($this->sendBack)($purchase['purchaseId'], 10);

        $shelf = Shop::productRow($this->rice);
        expect(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(0)
            ->and((int) $shelf->qty_on_hand_milli)->toBe(0)
            ->and((int) $shelf->stock_value_minor)->toBe(0)
            ->and(($this->taxAccounts)())->toBe([0, 0, 0])
            ->and(Shop::balance(ChartOfAccounts::ACCOUNTS_PAYABLE))->toBe(0);
    });

    it('returns a folded levy on goods not stock-tracked to where the purchase expensed it', function () {
        Shop::setGhanaTaxes();
        ($this->levyIsACost)();
        $bags = Shop::product($this->actor, 'Carrier bags', 6_500, 10_000, ['trackInventory' => false]);
        $purchase = ($this->buy)([], $bags);
        expect(Shop::balance('6900'))->toBe(68_250);

        ($this->sendBack)($purchase['purchaseId'], 10);

        expect(Shop::balance('6900'))->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(0)
            ->and(($this->taxAccounts)())->toBe([0, 0, 0])
            ->and(Shop::balance(ChartOfAccounts::ACCOUNTS_PAYABLE))->toBe(0);
    });

    it('sums a return of 3 then 7 to exactly one full return', function (bool $folded) {
        Shop::setGhanaTaxes();
        if ($folded) {
            ($this->levyIsACost)();
        }
        $purchase = ($this->buy)();
        $paid = DB::table('purchase_taxes')->where('purchase_id', $purchase['purchaseId'])->pluck('amount_minor', 'code')->map(fn ($v): int => (int) $v)->all();

        $first = ($this->sendBack)($purchase['purchaseId'], 3);
        $second = ($this->sendBack)($purchase['purchaseId'], 7);

        $returnIds = [$first['returnPurchaseId'], $second['returnPurchaseId']];
        $returned = DB::table('purchase_taxes')->whereIn('purchase_id', $returnIds)->groupBy('code')
            ->selectRaw('code, -SUM(amount_minor) AS t')->pluck('t', 'code')->map(fn ($v): int => (int) $v)->all();
        $shelf = Shop::productRow($this->rice);
        expect($returned)->toEqual($paid)
            ->and((int) DB::table('purchases')->whereIn('id', $returnIds)->sum('total_minor'))->toBe(-78_000)
            ->and($first['creditApplied'] + $second['creditApplied'])->toBe(78_000)
            ->and(($this->taxAccounts)())->toBe([0, 0, 0])
            ->and(Shop::balance(ChartOfAccounts::ACCOUNTS_PAYABLE))->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(0)
            ->and((int) $shelf->stock_value_minor)->toBe(0);
    })->with(['levies reclaimable' => false, 'levies folded into cost' => true]);

    it('keeps the tax return for the period in agreement with the ledger', function (bool $folded) {
        Shop::setGhanaTaxes();
        if ($folded) {
            ($this->levyIsACost)();
        }
        $purchase = ($this->buy)();
        ($this->sendBack)($purchase['purchaseId'], 3);

        $return = collect(TaxReturnService::getTaxReturn(Shop::monthOf(SRET_DAY))['components'])->keyBy('code');
        expect([$return['VAT']['netMinor'], $return['NHIL']['netMinor'], $return['GETFUND']['netMinor']])->toBe(($this->taxAccounts)())
            ->and(Shop::balance(ChartOfAccounts::TAX_PAYABLE))->toBe(-6_825);
    })->with(['levies reclaimable' => false, 'levies folded into cost' => true]);
});

describe('D2: a supplier return values goods at their actual stock cost', function () {
    it('credits the discounted cost, so nothing is left owing either way', function () {
        // 10 × 65.00 less a 65.00 invoice discount: 585.00 owed.
        $purchase = ($this->buy)(['invoiceDiscount' => 6_500]);
        expect(SupplierService::getSupplierBalance($this->supplier))->toBe(58_500);

        $return = ($this->sendBack)($purchase['purchaseId'], 10);

        $shelf = Shop::productRow($this->rice);
        expect($return['creditApplied'])->toBe(58_500)
            ->and(SupplierService::getSupplierBalance($this->supplier))->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(0)
            ->and((int) $shelf->qty_on_hand_milli)->toBe(0)
            ->and((int) $shelf->stock_value_minor)->toBe(0);
    });

    it('ends in the same place after a partial return and then the rest', function () {
        $purchase = ($this->buy)(['invoiceDiscount' => 6_500]);

        $first = ($this->sendBack)($purchase['purchaseId'], 3);
        $second = ($this->sendBack)($purchase['purchaseId'], 7);

        $shelf = Shop::productRow($this->rice);
        expect($first['creditApplied'] + $second['creditApplied'])->toBe(58_500)
            ->and(SupplierService::getSupplierBalance($this->supplier))->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(0)
            ->and((int) $shelf->qty_on_hand_milli)->toBe(0)
            ->and((int) $shelf->stock_value_minor)->toBe(0);
    });

    it('keeps the stock value on the running pair after the average has moved', function () {
        // 10 at 65.00 and 10 at 75.00: 20 on the shelf worth 1,400.00.
        $first = ($this->buy)();
        ($this->buy)([], null, 7_500);
        // 15 sold at the average: 1,050.00 out, 5 left worth 350.00.
        Shop::sell($this->actor, [[$this->rice, 15_000]], [['paymentAccountId' => Shop::paymentAccount('CASH'), 'amount' => 150_000]], ['businessDate' => SRET_DAY]);

        // The last 5 go back at what the first supplier charged: 325.00; the
        // 25.00 left on an empty shelf is released, as a void releases it.
        $return = ($this->sendBack)($first['purchaseId'], 5);

        $shelf = Shop::productRow($this->rice);
        expect($return['creditApplied'])->toBe(32_500)
            ->and((int) $shelf->qty_on_hand_milli)->toBe(0)
            ->and((int) $shelf->stock_value_minor)->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD))->toBe(107_500)
            ->and(SupplierService::getSupplierBalance($this->supplier))->toBe(65_000 + 75_000 - 32_500);
    });
});

describe('returns in pieces', function () {
    beforeEach(function () {
        // Return $lines ([itemIndex => units]) of a purchase, with optional cash refunds.
        $this->sendLines = function (int $purchaseId, array $lines, array $refunds = []): array {
            $itemIds = DB::table('purchase_items')->where('purchase_id', $purchaseId)->orderBy('line_no')->pluck('id')->all();

            return ReturnsService::createSupplierReturn($purchaseId, [
                'businessDate' => SRET_DAY,
                'items' => array_map(fn (int $index, int $units): array => ['itemId' => (int) $itemIds[$index], 'qty' => $units * 1_000], array_keys($lines), $lines),
                'refunds' => $refunds,
            ], $this->actor);
        };
        $this->identityHolds = function (int $returnId): void {
            $doc = DB::table('purchases')->where('id', $returnId)->first();
            expect((int) $doc->subtotal_minor - (int) $doc->discount_minor + (int) $doc->tax_minor)->toBe((int) $doc->total_minor, "identity on {$doc->purchase_no}");
        };
    });

    it('posts every piece when one tax share would otherwise step backwards', function () {
        // Unequal components where the shared rounding makes GETFund's running
        // share fall by a pesewa on the fourth bag of seven.
        Shop::setGhanaTaxes();
        foreach (['NHIL' => 321, 'GETFUND' => 14, 'VAT' => 1_739] as $code => $rate) {
            DB::table('tax_components')->where('code', $code)->update(['rate_bp' => $rate, 'basis' => 'NET', 'is_recoverable' => true]);
        }
        $purchase = Shop::purchase($this->actor, $this->supplier, [[$this->rice, 7_000, 136]], [], ['businessDate' => SRET_DAY])['purchaseId'];

        for ($bag = 1; $bag <= 7; $bag++) {
            ($this->identityHolds)(($this->sendBack)($purchase, 1)['returnPurchaseId']);
        }

        expect(($this->taxAccounts)())->toBe([0, 0, 0])
            ->and(Shop::balance(ChartOfAccounts::ACCOUNTS_PAYABLE))->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(0);
    });

    it('treats a line as stock only if it went onto the shelf when bought', function () {
        $bags = Shop::product($this->actor, 'Carrier bags', 6_500, 10_000, ['trackInventory' => false]);
        $purchase = ($this->buy)([], $bags);
        // Tracking switched on after the delivery: the bags were expensed, not shelved.
        DB::table('products')->where('id', $bags)->update(['track_inventory' => true]);

        ($this->sendBack)($purchase['purchaseId'], 10);

        expect(Shop::balance('6900'))->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(0)
            ->and((int) Shop::productRow($bags)->qty_on_hand_milli)->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::ACCOUNTS_PAYABLE))->toBe(0);
    });

    it('unwinds a mixed, discounted, Ghana-taxed delivery to zero across pieces and a payment', function (bool $folded) {
        Shop::setGhanaTaxes();
        if ($folded) {
            ($this->levyIsACost)();
        }
        $bags = Shop::product($this->actor, 'Carrier bags', 1_250, 2_000, ['trackInventory' => false]);
        // 10 rice at 65.00 and 7 packs of bags at 12.50, less 33.33 off the invoice.
        $purchase = Shop::purchase($this->actor, $this->supplier, [[$this->rice, 10_000, 6_500], [$bags, 7_000, 1_250]], [], ['businessDate' => SRET_DAY, 'invoiceDiscount' => 3_333]);
        $id = $purchase['purchaseId'];
        $total = $purchase['total'];

        $first = ($this->sendLines)($id, [0 => 3, 1 => 2]);
        ($this->identityHolds)($first['returnPurchaseId']);

        // A payment after the first piece settles against what is still owed.
        $owed = $total - $first['creditApplied'];
        expect(PurchaseService::getPurchaseOutstanding($id))->toBe($owed);
        SupplierPaymentService::recordSupplierPayment(['supplierId' => $this->supplier, 'businessDate' => SRET_DAY, 'paymentAccountId' => Shop::paymentAccount('CASH'), 'amount' => 10_000], $this->actor);
        expect(PurchaseService::getPurchaseOutstanding($id))->toBe($owed - 10_000)
            ->and(SupplierService::getSupplierBalance($this->supplier))->toBe($owed - 10_000);

        $second = ($this->sendLines)($id, [1 => 1]);
        ($this->identityHolds)($second['returnPurchaseId']);
        // The rest goes back; the supplier hands back the 100.00 paid on it.
        $last = ($this->sendLines)($id, [0 => 7, 1 => 4], [['paymentAccountId' => Shop::paymentAccount('CASH'), 'amount' => 10_000]]);
        ($this->identityHolds)($last['returnPurchaseId']);

        expect($first['creditApplied'] + $second['creditApplied'] + $last['creditApplied'] + $last['refunded'])->toBe($total)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(0)
            ->and(Shop::balance('6900'))->toBe(0)
            ->and(($this->taxAccounts)())->toBe([0, 0, 0])
            ->and(Shop::balance(ChartOfAccounts::ACCOUNTS_PAYABLE))->toBe(0)
            ->and(SupplierService::getSupplierBalance($this->supplier))->toBe(0)
            ->and((int) Shop::productRow($this->rice)->stock_value_minor)->toBe(0);
    })->with(['levies reclaimable' => false, 'levies folded into cost' => true]);
});
