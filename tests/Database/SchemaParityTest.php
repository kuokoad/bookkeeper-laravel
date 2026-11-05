<?php

declare(strict_types=1);

/*
| The MySQL schema carries every table, CHECK and unique index the old
| SQLite schema had (lists in tests/Fixtures, taken from the old migrations),
| and keeps the storage rules: integers for money, DATE for trading days,
| binary collation, InnoDB, strict mode, UTC.
*/

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function oldSchemaList(string $name): array
{
    $lines = file(base_path("tests/Fixtures/{$name}"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    return array_values(array_filter($lines, fn (string $line): bool => ! str_starts_with($line, '#')));
}

/**
 * Old CHECKs that MySQL cannot hold as a CHECK, and what holds the rule instead.
 * A `*_format` check (GLOB 'YYYY-MM-DD' on a text date) is now the DATE column
 * type itself. A `*_not_self*` check compared a column with the AUTO_INCREMENT
 * id, which MySQL forbids in a CHECK, so a trigger pair holds it.
 */
const REPLACED_CHECKS = [
    'ck_accounts_parent_not_self' => ['trigger', 'accounts', 'parent_id'],
    'ck_journal_entries_not_self_reversing' => ['trigger', 'journal_entries', 'reverses_entry_id'],
    'ck_sales_not_self_voiding' => ['trigger', 'sales', 'voids_sale_id'],
    'ck_sales_not_self_returning' => ['trigger', 'sales', 'returns_sale_id'],
    'ck_purchases_not_self_voiding' => ['trigger', 'purchases', 'voids_purchase_id'],
    'ck_purchases_not_self_returning' => ['trigger', 'purchases', 'returns_purchase_id'],
    'ck_stock_adjustments_not_self_voiding' => ['trigger', 'stock_adjustments', 'voids_adjustment_id'],
    'ck_business_settings_lock_date_format' => ['date', 'business_settings', 'books_locked_before'],
    'ck_journal_entries_date_format' => ['date', 'journal_entries', 'entry_date'],
    'ck_product_batches_expiry_format' => ['date', 'product_batches', 'expiry_date'],
    'ck_product_batches_received_format' => ['date', 'product_batches', 'received_date'],
    'ck_quotations_valid_format' => ['date', 'quotations', 'valid_until'],
];

const DATE_FORMAT_TABLES = [
    'account_transfers', 'customer_payments', 'expenses', 'incomes', 'owner_movements', 'purchases', 'quotations',
    'reconciliations', 'sales', 'stock_adjustments', 'stock_ledger', 'supplier_payments',
];

it('has every table the old app had', function () {
    $tables = collect(DB::select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'))->pluck('t');

    expect(oldSchemaList('old-tables.txt'))->each(fn ($table) => $table->toBeIn($tables->all()));
});

it('keeps every old CHECK, or the rule that replaced it', function () {
    $checks = collect(DB::select('SELECT CONSTRAINT_NAME AS c FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()'))->pluck('c')->all();
    $triggers = collect(DB::select('SELECT TRIGGER_NAME AS t FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'))->pluck('t')->all();

    $missing = [];
    foreach (oldSchemaList('old-check-constraints.txt') as $name) {
        if (in_array($name, $checks, true)) {
            continue;
        }
        if (preg_match('/^ck_([a-z_]+)_date_format$/', $name, $m) === 1 && in_array($m[1], DATE_FORMAT_TABLES, true)) {
            [$kind, $table, $column] = ['date', $m[1], 'business_date'];
        } elseif (isset(REPLACED_CHECKS[$name])) {
            [$kind, $table, $column] = REPLACED_CHECKS[$name];
        } else {
            $missing[] = $name;

            continue;
        }

        $held = $kind === 'trigger'
            ? in_array("trg_{$table}_{$column}_bi", $triggers, true) && in_array("trg_{$table}_{$column}_bu", $triggers, true)
            : DB::selectOne('SELECT DATA_TYPE AS d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column])?->d === 'date';
        if (! $held) {
            $missing[] = "{$name} (expected {$kind} on {$table}.{$column})";
        }
    }

    expect($missing)->toBe([]);
});

it('keeps every old unique index', function () {
    $indexes = collect(DB::select('SELECT DISTINCT INDEX_NAME AS i FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0'))->pluck('i')->all();

    expect(oldSchemaList('old-unique-indexes.txt'))->each(fn ($index) => $index->toBeIn($indexes));
});

it('stores every amount, quantity and rate as BIGINT', function () {
    $wrong = DB::select("SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS type FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND (COLUMN_NAME LIKE '%\\_minor' OR COLUMN_NAME LIKE '%\\_milli' OR COLUMN_NAME LIKE '%\\_bp')
          AND DATA_TYPE <> 'bigint'");

    expect($wrong)->toBe([]);
});

it('has no DECIMAL, FLOAT or DOUBLE column anywhere', function () {
    $wrong = DB::select("SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('decimal', 'float', 'double')");

    expect($wrong)->toBe([]);
});

it('uses InnoDB and the binary, no-pad utf8mb4 collation for every table', function () {
    $wrong = DB::select("SELECT TABLE_NAME AS t, ENGINE AS e, TABLE_COLLATION AS c FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND (ENGINE <> 'InnoDB' OR TABLE_COLLATION <> 'utf8mb4_0900_bin')");

    expect($wrong)->toBe([]);
});

it('runs each connection in strict mode on UTC', function () {
    $session = DB::selectOne('SELECT @@SESSION.sql_mode AS mode, @@SESSION.time_zone AS tz, @@SESSION.transaction_isolation AS isolation');

    expect($session->tz)->toBe('+00:00')
        ->and($session->isolation)->toBe('READ-COMMITTED')
        ->and($session->mode)->toContain('STRICT_ALL_TABLES')
        ->and($session->mode)->toContain('STRICT_TRANS_TABLES')
        ->and($session->mode)->toContain('NO_ZERO_DATE');
});

it('refuses an impossible trading date rather than storing zeros', function () {
    expect(fn () => DB::table('business_settings')->where('id', 1)->update(['books_locked_before' => '2026-02-30']))
        ->toThrow(QueryException::class);
});

it('compares names case-insensitively only where the old app did', function () {
    DB::table('categories')->insert(['name' => 'Rice']);

    // uq_categories_name is UNIQUE (LOWER(name)), as in the old schema.
    expect(fn () => DB::table('categories')->insert(['name' => 'rice']))
        ->toThrow(QueryException::class);

    // Barcodes stay exact: binary collation, like SQLite's default.
    DB::table('products')->insert(['name' => 'A', 'unit' => 'pcs', 'barcode' => 'abc']);
    DB::table('products')->insert(['name' => 'B', 'unit' => 'pcs', 'barcode' => 'ABC']);
    expect(DB::table('products')->where('barcode', 'abc')->count())->toBe(1);
});
