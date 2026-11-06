<?php

declare(strict_types=1);

/*
| Owner decision D25 (PLAN.md §10, approved 2026-11-06): a customer return
| takes cumulative shares, as the supplier side does (D1/D2). Each piece's
| revenue, cost and each tax component is the share of (returned so far plus
| this piece) less the share of (returned so far), so returning a line in
| pieces gives back exactly what the sale took. The old app rounded each piece
| on its own: 29.00 returned a unit at a time refunded 29.01.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\CustomerService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const CRS_DAY = '2026-08-16';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->customer = Shop::customer($this->actor);
    // Three bags on the shelf for 10.00 in all: 3.33⅓ each, which no piece can carry exactly.
    $this->rice = Shop::product($this->actor, 'Rice 5kg', 333, 1_000, ['unit' => 'bag']);
    Shop::openingStock($this->actor, $this->rice, 3_000, 1_000, CRS_DAY);
    $this->sellOnCredit = fn (int $product, int $qty, array $extra = []): int => Shop::sell($this->actor, [[$product, $qty]], [], ['customerId' => $this->customer, 'businessDate' => CRS_DAY, ...$extra])['saleId'];
    $this->returnOne = fn (int $saleId): array => ReturnsService::createCustomerReturn($saleId, [
        'businessDate' => CRS_DAY,
        'items' => [['itemId' => SaleService::getSale($saleId)['items'][0]['id'], 'qty' => 1_000]],
        'refunds' => [],
    ], $this->actor);
    $this->returnRow = fn (array $return): object => DB::table('sales')->where('id', $return['returnSaleId'])->first();
    $this->netRevenue = fn (): int => Shop::balance(ChartOfAccounts::SALES_REVENUE) - Shop::balance(ChartOfAccounts::SALES_DISCOUNTS) - Shop::balance(ChartOfAccounts::SALES_RETURNS);
    $this->taxAccounts = fn (): array => [Shop::balance(ChartOfAccounts::TAX_PAYABLE), Shop::balance(ChartOfAccounts::NHIL_PAYABLE), Shop::balance(ChartOfAccounts::GETFUND_PAYABLE)];
});

describe('D25: a customer return in pieces gives back exactly what the sale took', function () {
    it('refunds exactly 29.00 and puts back exactly the 10.00 of cost when 3 × 10.00 less 1.00 comes back a unit at a time', function () {
        $sale = ($this->sellOnCredit)($this->rice, 3_000, ['invoiceDiscount' => 100, 'allowPriceOverride' => true]);
        expect((int) DB::table('sales')->where('id', $sale)->value('total_minor'))->toBe(2_900)
            ->and((int) DB::table('sales')->where('id', $sale)->value('cogs_minor'))->toBe(1_000);

        $pieces = [($this->returnOne)($sale), ($this->returnOne)($sale), ($this->returnOne)($sale)];

        $rows = array_map($this->returnRow, $pieces);
        expect(array_sum(array_column($pieces, 'creditApplied')))->toBe(2_900)
            ->and(array_map(fn (object $r): int => -(int) $r->total_minor, $rows))->toBe([967, 966, 967])
            ->and(array_sum(array_map(fn (object $r): int => -(int) $r->cogs_minor, $rows)))->toBe(1_000)
            ->and((int) Shop::productRow($this->rice)->stock_value_minor)->toBe(1_000);
        Shop::assertBooksHealthy('after the third piece');
    });

    it('puts every account the sale touched back where it was before the sale', function () {
        Shop::setGhanaTaxes();
        $before = [Shop::balance(ChartOfAccounts::INVENTORY), Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD), ($this->netRevenue)(), Shop::balance(ChartOfAccounts::ACCOUNTS_RECEIVABLE), ($this->taxAccounts)()];
        expect($before)->toBe([1_000, 0, 0, 0, [0, 0, 0]]);

        $sale = ($this->sellOnCredit)($this->rice, 3_000, ['invoiceDiscount' => 100, 'allowPriceOverride' => true]);
        expect(Shop::balance(ChartOfAccounts::ACCOUNTS_RECEIVABLE))->toBeGreaterThan(2_900);
        for ($bag = 1; $bag <= 3; $bag++) {
            ($this->returnOne)($sale);
        }

        expect([Shop::balance(ChartOfAccounts::INVENTORY), Shop::balance(ChartOfAccounts::COST_OF_GOODS_SOLD), ($this->netRevenue)(), Shop::balance(ChartOfAccounts::ACCOUNTS_RECEIVABLE), ($this->taxAccounts)()])->toBe($before)
            ->and(CustomerService::getCustomerBalance($this->customer))->toBe(0);
        Shop::assertBooksHealthy('after the full return in pieces');
    });

    it('posts every piece under three unequal tax components and returns each to zero', function () {
        Shop::setGhanaTaxes();
        foreach (['NHIL' => 321, 'GETFUND' => 14, 'VAT' => 1_739] as $code => $rate) {
            DB::table('tax_components')->where('code', $code)->update(['rate_bp' => $rate, 'basis' => 'NET']);
        }
        $sugar = Shop::product($this->actor, 'Sugar sachet', 50, 136);
        Shop::openingStock($this->actor, $sugar, 7_000, 350, CRS_DAY);
        $sale = ($this->sellOnCredit)($sugar, 7_000);
        $charged = (int) DB::table('sales')->where('id', $sale)->value('total_minor');

        $credited = 0;
        for ($sachet = 1; $sachet <= 7; $sachet++) {
            $piece = ($this->returnOne)($sale);
            $row = ($this->returnRow)($piece);
            expect((int) $row->subtotal_minor - (int) $row->discount_minor + (int) $row->tax_minor)->toBe((int) $row->total_minor, "identity on piece {$sachet}");
            $credited += $piece['creditApplied'];
        }

        expect($credited)->toBe($charged)
            ->and(($this->taxAccounts)())->toBe([0, 0, 0])
            ->and(($this->netRevenue)())->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::ACCOUNTS_RECEIVABLE))->toBe(0);
        Shop::assertBooksHealthy('after seven pieces');
    });

    it('leaves an imported return at its stored figures and measures the next one from the quantity already back', function () {
        $sale = ($this->sellOnCredit)($this->rice, 3_000, ['invoiceDiscount' => 100, 'allowPriceOverride' => true]);
        $line = DB::table('sale_items')->where('sale_id', $sale)->first();
        // Two returns as the old app stored them, each rounded on its own: 9.67 and 3.33 apiece.
        $legacy = [];
        foreach (['OLD-R1', 'OLD-R2'] as $no => $receipt) {
            $id = (int) DB::table('sales')->insertGetId([
                'receipt_no' => $receipt, 'kind' => 'RETURN', 'returns_sale_id' => $sale, 'customer_id' => $this->customer,
                'subtotal_minor' => -967, 'total_minor' => -967, 'cogs_minor' => -333,
                'business_date' => CRS_DAY, 'occurred_at' => CRS_DAY.' 10:00:00.000', 'status' => 'POSTED', 'created_by' => $this->actor->id,
            ]);
            DB::table('sale_items')->insert(['sale_id' => $id, 'line_no' => 1, 'product_id' => $this->rice, 'product_name' => 'Rice 5kg', 'unit' => 'bag',
                'qty_milli' => -1_000, 'unit_price_minor' => 1_000, 'line_total_minor' => -967, 'unit_cost_minor' => 333, 'total_cost_minor' => -333]);
            DB::table('sale_items')->where('id', $line->id)->update(['returned_qty_milli' => ($no + 1) * 1_000]);
            $legacy[] = $id;
        }

        $last = ($this->returnRow)(($this->returnOne)($sale));

        // The new piece is the line's last third by quantity (29.00 less 19.33, 10.00 less 6.67): the old rounding stays where it was stored.
        expect(-(int) $last->total_minor)->toBe(967)
            ->and(-(int) $last->cogs_minor)->toBe(333)
            ->and(DB::table('sales')->whereIn('id', $legacy)->pluck('total_minor')->map(fn ($v): int => (int) $v)->all())->toBe([-967, -967])
            ->and(DB::table('sale_items')->whereIn('sale_id', $legacy)->pluck('total_cost_minor')->map(fn ($v): int => (int) $v)->all())->toBe([-333, -333]);
    });
});

describe('D27: a returned stock item always goes back on the shelf', function () {
    it('restocks all three of a line costing 0.02 in all, returned a unit at a time', function () {
        // The cost shares are 0.01, 0.00, 0.01: the middle piece used to stay off the shelf.
        $nails = Shop::product($this->actor, 'Nails', 1, 50);
        Shop::openingStock($this->actor, $nails, 3_000, 2, CRS_DAY);
        $sale = ($this->sellOnCredit)($nails, 3_000);

        $costs = [];
        for ($nail = 1; $nail <= 3; $nail++) {
            $costs[] = -(int) ($this->returnRow)(($this->returnOne)($sale))->cogs_minor;
        }

        $shelf = Shop::productRow($nails);
        expect($costs)->toBe([1, 0, 1])
            ->and((int) $shelf->qty_on_hand_milli)->toBe(3_000)
            ->and((int) $shelf->stock_value_minor)->toBe(2);
        Shop::assertBooksHealthy('after the nails came back');
    });

    it('restocks an item that cost nothing', function () {
        $sample = Shop::product($this->actor, 'Free sample', 0, 200);
        Shop::openingStock($this->actor, $sample, 1_000, 0, CRS_DAY);
        $sale = ($this->sellOnCredit)($sample, 1_000);

        ($this->returnOne)($sale);

        expect((int) Shop::productRow($sample)->qty_on_hand_milli)->toBe(1_000)
            ->and((int) Shop::productRow($sample)->stock_value_minor)->toBe(0);
        Shop::assertBooksHealthy('after the sample came back');
    });
});
