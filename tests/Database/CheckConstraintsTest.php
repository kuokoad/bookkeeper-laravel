<?php

/*
| Every rule the old schema enforced is enforced by MySQL, by name.
|
| Each case takes the one valid row in a table and changes only the fields
| needed to break a single CHECK. The database must refuse it, and the error
| must name that CHECK. The last test proves every CHECK in the database has a
| case here, so a new constraint cannot go untested.
|
| Ported from the old tests/db/foundation.test.ts ("enum values are enforced by
| the DATABASE, not just TypeScript", "journal line constraints").
*/

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ValidRows;

/**
 * table => [constraint => changes]. Booleans are added below. A value of
 * '@id' means "this row's own id".
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function checkBreakers(): array
{
    return [
        'users' => [
            'ck_users_username_len' => ['username' => 'ab'],
            'ck_users_failed_login_count' => ['failed_login_count' => -1],
            'ck_users_role' => ['role' => 'ADMIN'],
        ],
        'user_permissions' => ['ck_user_permissions_module' => ['module' => 'payroll']],
        'rate_limits' => ['ck_rate_limits_attempts' => ['attempts' => -1]],
        'business_settings' => [
            'ck_business_settings_singleton' => ['id' => 2],
            'ck_business_settings_tax_rate' => ['tax_rate_bp' => 100_001],
            'ck_business_settings_fy_month' => ['financial_year_start_month' => 13],
            'ck_business_settings_currency' => ['currency_code' => 'G'],
            'ck_business_settings_look' => ['look' => 'dark'],
            'ck_business_settings_business_type' => ['business_type' => 'pharmacy'],
        ],
        'sequences' => [
            'ck_sequences_next_positive' => ['next_number' => 0],
            'ck_sequences_padding' => ['padding' => 13],
        ],
        'accounts' => [
            'ck_accounts_code_len' => ['code' => ''],
            'ck_accounts_type' => ['type' => 'INCOME'],
            'ck_accounts_normal_balance' => ['normal_balance' => 'LEFT'],
            // A revenue account carrying a debit balance would print backwards on every report.
            'ck_accounts_normal_balance_matches_type' => ['normal_balance' => 'CREDIT'],
        ],
        'payment_accounts' => [
            'ck_payment_accounts_kind' => ['kind' => 'CRYPTO'],
            'ck_payment_accounts_name' => ['name' => ''],
        ],
        'tax_components' => [
            'ck_tax_components_rate' => ['rate_bp' => -1],
            'ck_tax_components_basis' => ['basis' => 'GROSS'],
            'ck_tax_components_code' => ['code' => ''],
            'ck_tax_components_name' => ['name' => ''],
        ],
        'customers' => [
            'ck_customers_name' => ['name' => '   '],
            'ck_customers_credit_limit' => ['credit_limit_minor' => -1],
        ],
        'suppliers' => ['ck_suppliers_name' => ['name' => ' ']],
        'journal_entries' => [
            'ck_journal_entries_source_type' => ['source_type' => 'MANUAL'],
            // An entry with no traceable business transaction cannot exist.
            'ck_journal_entries_traceable' => ['source_id' => null],
        ],
        'journal_lines' => [
            'ck_journal_lines_debit_nonneg' => ['debit_minor' => -500],
            'ck_journal_lines_credit_nonneg' => ['debit_minor' => 0, 'credit_minor' => -500],
            'ck_journal_lines_one_sided' => ['credit_minor' => 500],
        ],
        'year_end_closings' => [
            'ck_year_end_closings_period' => ['period_end' => '2025-01-01'],
            'ck_year_end_closings_reversal' => ['reversed_at' => '2026-01-10 09:00:00'],
        ],
        'units' => [
            'ck_units_symbol' => ['symbol' => ' '],
            'ck_units_decimals' => ['decimal_places' => 4],
        ],
        'categories' => ['ck_categories_name' => ['name' => ' ']],
        'products' => [
            'ck_products_name' => ['name' => ' '],
            'ck_products_unit' => ['unit' => ' '],
            'ck_products_alt_unit' => ['alt_unit' => 'ctn'],
            'ck_products_alt_prices' => ['alt_unit' => 'ctn', 'alt_factor_milli' => 24_000, 'alt_cost_price_minor' => -1],
            'ck_products_cost_price' => ['cost_price_minor' => -1],
            'ck_products_selling_price' => ['selling_price_minor' => -1],
            'ck_products_min_stock' => ['min_stock_milli' => -1],
            'ck_products_warn_days' => ['warn_days' => -1],
            // Inventory can never hold value with zero quantity.
            'ck_products_zero_qty_zero_value' => ['qty_on_hand_milli' => 0],
        ],
        'product_batches' => [
            // An empty batch, so only the boolean rule is broken.
            'ck_product_batches_is_closed_bool' => ['is_closed' => 2, 'qty_milli' => 0],
            'ck_product_batches_ref' => ['batch_ref' => ' '],
            'ck_product_batches_warn_days' => ['warn_days' => -1],
            'ck_product_batches_closed_is_empty' => ['is_closed' => 1],
        ],
        'stock_ledger' => [
            'ck_stock_ledger_movement_type' => ['movement_type' => 'THEFT'],
            'ck_stock_ledger_qty_in_nonneg' => ['qty_in_milli' => -1],
            'ck_stock_ledger_qty_out_nonneg' => ['qty_in_milli' => 0, 'qty_out_milli' => -1],
            'ck_stock_ledger_one_direction' => ['qty_out_milli' => 1_000],
            'ck_stock_ledger_zero_qty_zero_value' => ['balance_qty_milli' => 0],
        ],
        'stock_ledger_batches' => ['ck_stock_ledger_batches_one_direction' => ['qty_out_milli' => 1_000]],
        'stock_adjustments' => [
            'ck_stock_adjustments_reason' => ['reason' => 'GIFT'],
            'ck_stock_adjustments_status' => ['status' => 'DELETED'],
        ],
        'stock_adjustment_items' => [
            'ck_stock_adjustment_items_direction' => ['direction' => 'UP'],
            'ck_stock_adjustment_items_qty_positive' => ['qty_milli' => 0],
            'ck_stock_adjustment_items_cost_nonneg' => ['total_cost_minor' => -1],
        ],
        'sales' => [
            'ck_sales_status' => ['status' => 'DELETED'],
            'ck_sales_kind' => ['kind' => 'GIFT'],
            // A sale is all non-negative; its mirror all non-positive. Never mixed.
            'ck_sales_signs_consistent' => ['subtotal_minor' => 1_000, 'discount_minor' => -100, 'total_minor' => 1_100],
            'ck_sales_total_arithmetic' => ['total_minor' => 999],
        ],
        'sale_items' => [
            'ck_sale_items_qty_nonzero' => ['qty_milli' => 0],
            'ck_sale_items_price_nonneg' => ['unit_price_minor' => -1],
            'ck_sale_items_discount_nonneg' => ['discount_minor' => -1],
            'ck_sale_items_returned_nonneg' => ['returned_qty_milli' => -1],
        ],
        'sale_payments' => ['ck_sale_payments_amount_nonzero' => ['amount_minor' => 0]],
        'sale_taxes' => ['ck_sale_taxes_basis' => ['basis' => 'GROSS']],
        'customer_payments' => [
            'ck_customer_payments_status' => ['status' => 'DELETED'],
            'ck_customer_payments_amount_positive' => ['amount_minor' => 0],
        ],
        'customer_payment_allocations' => ['ck_customer_payment_allocations_amount_nonzero' => ['amount_minor' => 0]],
        'quotations' => [
            'ck_quotations_status' => ['status' => 'WON'],
            'ck_quotations_name' => ['customer_name' => ' '],
            'ck_quotations_quote_no' => ['quote_no' => ' '],
            'ck_quotations_dates' => ['valid_until' => '2026-11-04'],
            'ck_quotations_signs' => ['subtotal_minor' => -1_000, 'total_minor' => -1_000],
            'ck_quotations_total_arithmetic' => ['total_minor' => 999],
            'ck_quotations_converted_link' => ['status' => 'CONVERTED'],
            'ck_quotations_cancelled_reason' => ['cancelled_at' => '2026-11-05 12:00:00'],
        ],
        'quotation_items' => [
            'ck_quotation_items_qty_positive' => ['qty_milli' => 0],
            'ck_quotation_items_price_nonneg' => ['unit_price_minor' => -1],
            'ck_quotation_items_discount_nonneg' => ['discount_minor' => -1],
        ],
        'purchases' => [
            'ck_purchases_status' => ['status' => 'DELETED'],
            'ck_purchases_kind' => ['kind' => 'GIFT'],
            'ck_purchases_total_arithmetic' => ['total_minor' => 1],
        ],
        'purchase_items' => [
            'ck_purchase_items_qty_nonzero' => ['qty_milli' => 0],
            'ck_purchase_items_returned_nonneg' => ['returned_qty_milli' => -1],
        ],
        'purchase_payments' => ['ck_purchase_payments_amount_nonzero' => ['amount_minor' => 0]],
        'purchase_taxes' => ['ck_purchase_taxes_basis' => ['basis' => 'GROSS']],
        'supplier_payments' => [
            'ck_supplier_payments_status' => ['status' => 'DELETED'],
            'ck_supplier_payments_amount_positive' => ['amount_minor' => -5],
        ],
        'supplier_payment_allocations' => ['ck_supplier_payment_allocations_amount_nonzero' => ['amount_minor' => 0]],
        'expenses' => [
            'ck_expenses_status' => ['status' => 'DELETED'],
            'ck_expenses_amount_positive' => ['amount_minor' => 0],
            'ck_expenses_description' => ['description' => ' '],
        ],
        'incomes' => [
            'ck_incomes_status' => ['status' => 'DELETED'],
            'ck_incomes_amount_positive' => ['amount_minor' => 0],
            'ck_incomes_description' => ['description' => ' '],
        ],
        'owner_movements' => [
            'ck_owner_movements_kind' => ['kind' => 'LOAN'],
            'ck_owner_movements_status' => ['status' => 'DELETED'],
            'ck_owner_movements_amount_positive' => ['amount_minor' => 0],
        ],
        'account_transfers' => [
            'ck_account_transfers_status' => ['status' => 'DELETED'],
            'ck_account_transfers_amount_positive' => ['amount_minor' => 0],
            'ck_account_transfers_distinct' => ['to_account_id' => '@from_account_id'],
        ],
        'reconciliations' => [
            'ck_reconciliations_status' => ['status' => 'DELETED'],
            'ck_reconciliations_difference' => ['actual_minor' => 2_400],
            // A difference needs an explanation.
            'ck_reconciliations_explained' => ['actual_minor' => 2_400, 'difference_minor' => -50],
        ],
    ];
}

/**
 * Rules another rule already implies: no row can break the first without also
 * breaking the second, and MySQL may report either. Both stay, as in the old app.
 */
const IMPLIED_BY = [
    'ck_accounts_type' => 'ck_accounts_normal_balance_matches_type',
    'ck_journal_lines_debit_nonneg' => 'ck_journal_lines_one_sided',
    'ck_journal_lines_credit_nonneg' => 'ck_journal_lines_one_sided',
    'ck_stock_ledger_qty_in_nonneg' => 'ck_stock_ledger_one_direction',
    'ck_stock_ledger_qty_out_nonneg' => 'ck_stock_ledger_one_direction',
];

/** All cases: every boolean, then the hand-written breakers (which win). */
function allBreakers(): array
{
    return array_replace_recursive(booleanBreakers(), checkBreakers(), [
        'audit_logs' => [
            'ck_audit_logs_action' => ['action' => 'HACK'],
            'ck_audit_logs_summary' => ['summary' => ''],
        ],
    ]);
}

/** Every *_bool CHECK in the database, broken by storing 2. */
function booleanBreakers(): array
{
    $cases = [];
    $rows = DB::select("SELECT tc.TABLE_NAME AS t, tc.CONSTRAINT_NAME AS c FROM information_schema.TABLE_CONSTRAINTS tc
        WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.CONSTRAINT_TYPE = 'CHECK' AND tc.CONSTRAINT_NAME LIKE '%\\_bool'");
    foreach ($rows as $row) {
        $column = substr($row->c, strlen('ck_'.$row->t.'_'), -strlen('_bool'));
        $cases[$row->t][$row->c] = [$column => 2];
    }

    return $cases;
}

function breakRow(ValidRows $rows, string $table, array $changes): void
{
    $key = ValidRows::keyOf($table);
    $id = $rows->ids[$table];
    foreach ($changes as $column => $value) {
        if (is_string($value) && str_starts_with($value, '@')) {
            $changes[$column] = DB::table($table)->where($key, $id)->value(substr($value, 1));
        }
    }

    if ($table === 'audit_logs') {
        // Insert-only: an UPDATE is refused by trigger before any CHECK runs.
        DB::table('audit_logs')->insert(array_merge(['action' => 'CREATE', 'entity_type' => 'test', 'summary' => 'x'], $changes));

        return;
    }

    DB::table($table)->where($key, $id)->update($changes);
}

it('refuses a row that breaks each CHECK, naming the CHECK', function () {
    $rows = ValidRows::create();
    $cases = allBreakers();

    $failures = [];
    foreach ($cases as $table => $constraints) {
        foreach ($constraints as $constraint => $changes) {
            // A savepoint per case, so a wrongly accepted change cannot leak into the next case.
            DB::beginTransaction();
            try {
                breakRow($rows, $table, $changes);
                $failures[] = "{$constraint}: accepted";
            } catch (QueryException $e) {
                // MySQL 3819: Check constraint '<name>' is violated.
                $named = fn (string $name): bool => str_contains($e->getMessage(), "Check constraint '{$name}' is violated");
                if (! $named($constraint) && ! (isset(IMPLIED_BY[$constraint]) && $named(IMPLIED_BY[$constraint]))) {
                    $failures[] = "{$constraint}: refused for another reason: ".$e->getPrevious()?->getMessage();
                }
            } finally {
                DB::rollBack();
            }
        }
    }

    expect($failures)->toBe([]);
});

it('has a breaking case for every CHECK in the database', function () {
    $inDatabase = collect(DB::select('SELECT CONSTRAINT_NAME AS c FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()'))
        ->pluck('c')->sort()->values()->all();
    $covered = collect(allBreakers())
        ->flatMap(fn (array $constraints): array => array_keys($constraints))
        ->sort()->values()->all();

    expect($covered)->toBe($inDatabase);
});
