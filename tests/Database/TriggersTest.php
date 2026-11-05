<?php

/*
| Rules MySQL cannot hold as a CHECK are held by triggers: no row may point at
| itself, and the audit log is insert-only.
*/

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ValidRows;

dataset('self references', [
    'an account as its own parent' => ['accounts', 'parent_id', 'An account cannot be its own parent.'],
    'an entry reversing itself' => ['journal_entries', 'reverses_entry_id', 'A journal entry cannot reverse itself.'],
    'an entry reversed by itself' => ['journal_entries', 'reversed_by_entry_id', 'A journal entry cannot be reversed by itself.'],
    'a sale voiding itself' => ['sales', 'voids_sale_id', 'A sale cannot void itself.'],
    'a sale returning itself' => ['sales', 'returns_sale_id', 'A sale cannot return itself.'],
    'a sale voided by itself' => ['sales', 'voided_by_sale_id', 'A sale cannot be voided by itself.'],
    'a purchase voiding itself' => ['purchases', 'voids_purchase_id', 'A purchase cannot void itself.'],
    'a purchase returning itself' => ['purchases', 'returns_purchase_id', 'A purchase cannot return itself.'],
    'a purchase voided by itself' => ['purchases', 'voided_by_purchase_id', 'A purchase cannot be voided by itself.'],
    'an adjustment voiding itself' => ['stock_adjustments', 'voids_adjustment_id', 'An adjustment cannot void itself.'],
    'an adjustment voided by itself' => ['stock_adjustments', 'voided_by_adjustment_id', 'An adjustment cannot be voided by itself.'],
]);

it('refuses a row that points at itself on update', function (string $table, string $column, string $message) {
    $rows = ValidRows::create();
    $id = $rows->ids[$table];

    expect(fn () => DB::table($table)->where('id', $id)->update([$column => $id]))
        ->toThrow(QueryException::class, $message);
})->with('self references');

it('refuses a row inserted with an explicit id that points at itself', function () {
    // The importer inserts with the old ids, so the insert trigger matters too.
    ValidRows::create();

    expect(fn () => DB::table('journal_entries')->insert([
        'id' => 9_001, 'entry_no' => 'JE-009001', 'entry_date' => '2026-11-05', 'occurred_at' => '2026-11-05 10:00:00',
        'source_type' => 'REVERSAL', 'source_id' => 1, 'reverses_entry_id' => 9_001,
    ]))->toThrow(QueryException::class, 'A journal entry cannot reverse itself.');
});

it('still lets a row point at a different row', function () {
    $rows = ValidRows::create();
    $reversal = DB::table('journal_entries')->insertGetId([
        'entry_no' => 'JE-000002', 'entry_date' => '2026-11-05', 'occurred_at' => '2026-11-05 10:00:00',
        'source_type' => 'REVERSAL', 'source_id' => $rows->ids['journal_entries'], 'reverses_entry_id' => $rows->ids['journal_entries'],
    ]);
    DB::table('journal_entries')->where('id', $rows->ids['journal_entries'])->update(['reversed_by_entry_id' => $reversal]);

    expect(DB::table('journal_entries')->where('id', $rows->ids['journal_entries'])->value('reversed_by_entry_id'))->toBe($reversal);
});

it('refuses to change an audit entry', function () {
    $id = DB::table('audit_logs')->value('id');

    expect(fn () => DB::table('audit_logs')->where('id', $id)->update(['summary' => 'nothing happened']))
        ->toThrow(QueryException::class, 'The audit log is insert-only');
});

it('refuses to delete an audit entry', function () {
    expect(fn () => DB::table('audit_logs')->delete())
        ->toThrow(QueryException::class, 'The audit log is insert-only');
    expect(DB::table('audit_logs')->count())->toBeGreaterThan(0);
});

it('allows only one open close per financial year', function () {
    $rows = ValidRows::create();
    $base = DB::table('year_end_closings')->where('id', $rows->ids['year_end_closings'])->first();
    $copy = fn (array $changes) => DB::table('year_end_closings')->insert(array_merge([
        'start_year' => $base->start_year, 'period_start' => $base->period_start, 'period_end' => $base->period_end,
        'journal_entry_id' => $base->journal_entry_id, 'profit_minor' => 0, 'drawings_minor' => 0, 'closed_at' => $base->closed_at,
    ], $changes));

    // A second open close of 2025 is refused (the old partial unique index).
    expect(fn () => $copy([]))->toThrow(QueryException::class, 'uq_year_end_closings_open');

    // Once the first is reopened, the year can be closed again.
    DB::table('year_end_closings')->where('id', $rows->ids['year_end_closings'])->update([
        'reversed_at' => '2026-02-01 09:00:00', 'reversal_entry_id' => $base->journal_entry_id,
    ]);
    $copy([]);

    expect(DB::table('year_end_closings')->where('start_year', 2025)->count())->toBe(2);
});
