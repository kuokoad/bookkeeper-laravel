<?php

/*
| The baseline seed. Ported from the old tests/db/foundation.test.ts
| ("migrations and pragmas", "chart of accounts seed").
*/

use App\Domain\Accounting\ChartOfAccounts;
use Database\Seeders\CoreSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('creates the singleton settings row in GHS, refusing stock it does not have', function () {
    $settings = DB::table('business_settings')->get();

    expect($settings)->toHaveCount(1)
        ->and($settings[0]->currency_code)->toBe('GHS')
        ->and($settings[0]->currency_symbol)->toBe('₵')
        ->and($settings[0]->allow_negative_stock)->toBe(0)
        ->and($settings[0]->tax_enabled)->toBe(0);
});

it('refuses a second settings row', function () {
    expect(fn () => DB::table('business_settings')->insert(['id' => 2]))->toThrow(QueryException::class);
});

it('creates every system account exactly once, idempotently', function () {
    $before = DB::table('accounts')->count();
    (new CoreSeeder)->run();

    expect($before)->toBe(39)
        ->and(DB::table('accounts')->count())->toBe($before)
        ->and(DB::table('payment_accounts')->count())->toBe(3)
        ->and(DB::table('tax_components')->count())->toBe(3)
        ->and(DB::table('sequences')->count())->toBe(15);
});

it('seeds the chart of accounts with the old codes, types and parents', function () {
    $accounts = DB::table('accounts as a')
        ->leftJoin('accounts as p', 'p.id', '=', 'a.parent_id')
        ->orderBy('a.code')
        ->get(['a.code', 'a.type', 'a.normal_balance', 'p.code as parent'])
        ->keyBy('code');

    expect($accounts[ChartOfAccounts::INVENTORY]->type)->toBe('ASSET')
        ->and($accounts[ChartOfAccounts::OWNERS_DRAWINGS]->type)->toBe('CONTRA_EQUITY')
        ->and($accounts[ChartOfAccounts::OWNERS_DRAWINGS]->normal_balance)->toBe('DEBIT')
        ->and($accounts[ChartOfAccounts::SALES_RETURNS]->type)->toBe('CONTRA_REVENUE')
        ->and($accounts[ChartOfAccounts::CASH_OVER_SHORT]->type)->toBe('EXPENSE')
        ->and($accounts['6900']->parent)->toBe(ChartOfAccounts::OPERATING_EXPENSES)
        ->and($accounts['4290']->parent)->toBe(ChartOfAccounts::OTHER_INCOME)
        ->and($accounts['1001']->parent)->toBe(ChartOfAccounts::CASH);
});

it('marks system accounts as undeletable', function () {
    expect(DB::table('accounts')->where('code', ChartOfAccounts::CASH)->value('is_system'))->toBe(1);
});

it('gives every payment account its own ledger account', function () {
    $rows = DB::table('payment_accounts as pa')->join('accounts as a', 'a.id', '=', 'pa.gl_account_id')
        ->get(['pa.gl_account_id', 'a.type', 'a.parent_id']);

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('gl_account_id')->unique())->toHaveCount(3)
        ->and($rows->pluck('type')->unique()->all())->toBe(['ASSET'])
        ->and($rows->whereNull('parent_id'))->toHaveCount(0);
});

it('has exactly one default payment account, the cash drawer', function () {
    $defaults = DB::table('payment_accounts')->where('is_default', true)->get();

    expect($defaults)->toHaveCount(1)->and($defaults[0]->kind)->toBe('CASH');
});

it('does not hard-code a mobile network', function () {
    // MTN is only the seeded example; any name and provider is accepted.
    expect(DB::table('payment_accounts')->where('kind', 'MOBILE_MONEY')->value('provider'))->toBe('MTN');

    $gl = DB::table('accounts')->insertGetId(['code' => '1012', 'name' => 'Telecel Cash', 'type' => 'ASSET', 'normal_balance' => 'DEBIT']);
    DB::table('payment_accounts')->insert(['name' => 'Telecel Cash', 'kind' => 'MOBILE_MONEY', 'provider' => 'Telecel', 'gl_account_id' => $gl]);

    expect(DB::table('payment_accounts')->where('kind', 'MOBILE_MONEY')->count())->toBe(2);
});

it('rejects a duplicate account code', function () {
    expect(fn () => DB::table('accounts')->insert(['code' => '1000', 'name' => 'Cash again', 'type' => 'ASSET', 'normal_balance' => 'DEBIT']))
        ->toThrow(QueryException::class);
});

it('seeds the Ghana taxes at statutory rates, with tax itself off until the owner turns it on', function () {
    $taxes = DB::table('tax_components as t')->join('accounts as a', 'a.id', '=', 't.gl_account_id')
        ->orderBy('t.sort_order')->get(['t.code', 't.rate_bp', 't.basis', 't.is_active', 'a.code as account']);

    expect($taxes->map(fn ($t) => [$t->code, $t->rate_bp, $t->basis, $t->account])->all())->toBe([
        ['NHIL', 250, 'NET', '2110'],
        ['GETFUND', 250, 'NET', '2120'],
        ['VAT', 1_500, 'NET', '2100'],
    ]);

    $settings = DB::table('business_settings')->first();
    expect($settings->tax_enabled)->toBe(0)
        ->and($settings->tax_rate_bp)->toBe(2_000)
        ->and($settings->tax_label)->toBe('Tax');
});

it('keeps a rate the shop had already set, as VAT, and switches the levies off', function () {
    DB::table('tax_components')->delete();
    DB::table('business_settings')->where('id', 1)->update(['tax_rate_bp' => 1_250]);

    (new CoreSeeder)->run();

    $taxes = DB::table('tax_components')->orderBy('sort_order')->get(['code', 'rate_bp', 'is_active']);
    expect($taxes->map(fn ($t) => [$t->code, $t->rate_bp, $t->is_active])->all())->toBe([
        ['NHIL', 250, 0],
        ['GETFUND', 250, 0],
        ['VAT', 1_250, 1],
    ]);

    $settings = DB::table('business_settings')->first();
    expect($settings->tax_rate_bp)->toBe(1_250)->and($settings->tax_label)->toBe('VAT');
});

it('numbers documents with the old prefixes and padding', function () {
    $sequences = DB::table('sequences')->pluck('prefix', 'doc_type');

    expect($sequences['RECEIPT'])->toBe('RCP-')
        ->and($sequences['INVOICE'])->toBe('INV-')
        ->and($sequences['TRANSFER'])->toBe('TRF-')
        ->and(DB::table('sequences')->where('doc_type', 'JOURNAL')->value('padding'))->toBe(6)
        ->and(DB::table('sequences')->where('next_number', '<>', 1)->count())->toBe(0);
});

it('records that the baseline was seeded', function () {
    expect(DB::table('audit_logs')->where('entity_id', 'core-seed')->exists())->toBeTrue();
});

it('seeds the pieces unit, as the old schema did', function () {
    expect(DB::table('units')->where('symbol', 'pcs')->value('name'))->toBe('Pieces');
});
