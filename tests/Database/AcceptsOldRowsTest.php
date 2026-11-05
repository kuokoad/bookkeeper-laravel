<?php

/*
| The other direction from CheckConstraintsTest: rows the old app wrote must
| still be accepted. A CHECK that is stricter than the old one would make
| real data fail to import, or refuse a return the old app allowed.
|
| Also the ON DELETE behaviour, and the text-comparison rules that changed
| with the move from SQLite.
*/

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ValidRows;

it('accepts the all-negative mirror of a sale, as a return or void writes it', function () {
    $rows = ValidRows::create();
    $mirror = DB::table('sales')->insertGetId([
        'receipt_no' => 'SRT-00001', 'kind' => 'VOID', 'voids_sale_id' => $rows->ids['sales'],
        'business_date' => '2026-11-06', 'occurred_at' => '2026-11-06 09:00:00',
        'subtotal_minor' => -1_100, 'discount_minor' => -100, 'tax_minor' => -50, 'total_minor' => -1_050,
    ]);
    DB::table('sale_items')->insert([
        'sale_id' => $mirror, 'line_no' => 1, 'product_id' => $rows->ids['products'], 'product_name' => 'Malt', 'unit' => 'pcs',
        'qty_milli' => -2_000, 'unit_price_minor' => 500, 'line_total_minor' => -1_000,
    ]);
    DB::table('sale_payments')->insert(['sale_id' => $mirror, 'payment_account_id' => $rows->ids['payment_accounts'], 'amount_minor' => -1_050]);
    DB::table('sales')->where('id', $rows->ids['sales'])->update(['status' => 'VOIDED', 'voided_by_sale_id' => $mirror]);

    expect(DB::table('sales')->where('kind', 'VOID')->value('total_minor'))->toBe(-1_050);
});

it('accepts the negative mirror of a purchase', function () {
    $rows = ValidRows::create();
    $mirror = DB::table('purchases')->insertGetId([
        'purchase_no' => 'PRT-00001', 'kind' => 'RETURN', 'returns_purchase_id' => $rows->ids['purchases'],
        'business_date' => '2026-11-06', 'occurred_at' => '2026-11-06 09:00:00',
        'subtotal_minor' => -500, 'total_minor' => -500,
    ]);
    DB::table('purchase_items')->insert([
        'purchase_id' => $mirror, 'line_no' => 1, 'product_id' => $rows->ids['products'], 'product_name' => 'Malt', 'unit' => 'pcs',
        'qty_milli' => -1_000, 'unit_cost_minor' => 500, 'line_total_minor' => -500,
    ]);

    expect(DB::table('purchase_items')->where('purchase_id', $mirror)->value('qty_milli'))->toBe(-1_000);
});

it('accepts stock driven below zero when the shop allows it', function () {
    $rows = ValidRows::create();
    DB::table('products')->where('id', $rows->ids['products'])->update(['qty_on_hand_milli' => -3_000, 'stock_value_minor' => -1_500]);
    DB::table('stock_ledger')->insert([
        'product_id' => $rows->ids['products'], 'business_date' => '2026-11-05', 'occurred_at' => '2026-11-05 11:00:00',
        'movement_type' => 'SALE', 'source_type' => 'SALE', 'source_id' => 1, 'qty_out_milli' => 27_000,
        'total_cost_minor' => 13_500, 'balance_qty_milli' => -3_000, 'balance_value_minor' => -1_500,
    ]);

    expect(DB::table('products')->where('id', $rows->ids['products'])->value('qty_on_hand_milli'))->toBe(-3_000);
});

it('accepts a reconciliation with an explained shortage, a converted quote, a cancelled one, and a closed year that was reopened', function () {
    $rows = ValidRows::create();
    DB::table('reconciliations')->where('id', $rows->ids['reconciliations'])->update(['actual_minor' => 2_400, 'difference_minor' => -50, 'explanation' => 'Change given twice']);
    DB::table('quotations')->where('id', $rows->ids['quotations'])->update(['status' => 'CONVERTED', 'converted_sale_id' => $rows->ids['sales']]);
    DB::table('quotations')->insert([
        'quote_no' => 'QTE-00002', 'business_date' => '2026-11-05', 'valid_until' => '2026-11-05', 'customer_name' => 'Kwame',
        'subtotal_minor' => 0, 'total_minor' => 0, 'status' => 'CANCELLED', 'cancelled_at' => '2026-11-05 12:00:00',
    ]);
    DB::table('year_end_closings')->where('id', $rows->ids['year_end_closings'])->update([
        'reversed_at' => '2026-02-01 09:00:00', 'reversal_entry_id' => $rows->ids['journal_entries'],
    ]);

    expect(DB::table('quotations')->where('status', 'CANCELLED')->count())->toBe(1);
});

it('accepts a carton with its size and prices', function () {
    $rows = ValidRows::create();
    DB::table('products')->where('id', $rows->ids['products'])->update([
        'alt_unit' => 'ctn', 'alt_factor_milli' => 24_000, 'alt_cost_price_minor' => 12_000, 'alt_selling_price_minor' => 13_200,
    ]);

    expect(DB::table('products')->where('id', $rows->ids['products'])->value('alt_unit'))->toBe('ctn');
});

it('accepts the long cells the old CSV import allowed', function () {
    // The old import capped a cell at 1,000 characters and created units from any text.
    $unit = str_repeat('u', 700);
    DB::table('units')->insert(['symbol' => $unit, 'name' => str_repeat('n', 1_000)]);
    $id = DB::table('products')->insertGetId([
        'name' => str_repeat('p', 1_000), 'unit' => $unit, 'sku' => str_repeat('s', 700), 'barcode' => str_repeat('9', 700),
    ]);

    expect(DB::table('products')->where('id', $id)->value('sku'))->toHaveLength(700);
});

it('keeps trailing spaces significant, as SQLite did', function () {
    // utf8mb4_0900_bin is NO PAD; the older utf8mb4_bin would call these equal.
    DB::table('products')->insert(['name' => 'A', 'unit' => 'pcs', 'barcode' => 'X1']);
    DB::table('products')->insert(['name' => 'B', 'unit' => 'pcs', 'barcode' => 'X1 ']);

    expect(DB::table('products')->where('barcode', 'X1')->count())->toBe(1);
});

describe('ON DELETE, as the old schema had it', function () {
    it('takes a document\'s lines with it', function () {
        $rows = ValidRows::create();
        DB::table('customer_payment_allocations')->delete();
        DB::table('quotations')->where('converted_sale_id', $rows->ids['sales'])->delete();
        DB::table('sales')->where('id', $rows->ids['sales'])->delete();

        expect(DB::table('sale_items')->where('sale_id', $rows->ids['sales'])->count())->toBe(0)
            ->and(DB::table('sale_payments')->where('sale_id', $rows->ids['sales'])->count())->toBe(0);
    });

    it('refuses to delete an account that has been posted to', function () {
        $rows = ValidRows::create();
        $posted = DB::table('journal_lines')->where('id', $rows->ids['journal_lines'])->value('account_id');

        expect(fn () => DB::table('accounts')->where('id', $posted)->delete())->toThrow(QueryException::class);
    });

    it('leaves a product uncategorised when its category goes', function () {
        $rows = ValidRows::create();
        DB::table('categories')->where('id', $rows->ids['categories'])->delete();

        expect(DB::table('products')->where('id', $rows->ids['products'])->value('category_id'))->toBeNull();
    });

    it('refuses to delete a user who appears in the audit log', function () {
        $rows = ValidRows::create();
        DB::table('audit_logs')->insert(['user_id' => $rows->ids['users'], 'action' => 'LOGIN_SUCCESS', 'entity_type' => 'user', 'summary' => 'Signed in']);

        expect(fn () => DB::table('users')->where('id', $rows->ids['users'])->delete())->toThrow(QueryException::class);
    });
});
