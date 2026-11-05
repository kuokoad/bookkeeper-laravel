<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * One valid row in every table, built on the seeded baseline, so a test can
 * break a single field and see the database refuse it.
 *
 * These rows are not meaningful bookkeeping (the journal entry does not
 * balance, for one); they only satisfy the schema's own rules. Balance is a
 * service rule, not a CHECK, and is tested with the services.
 */
final class ValidRows
{
    /** @var array<string, int|string> table => id of its valid row */
    public array $ids = [];

    public static function create(): self
    {
        $rows = new self;
        $rows->build();

        return $rows;
    }

    private function build(): void
    {
        $now = '2026-11-05 10:00:00.000';
        $date = '2026-11-05';
        $cashAccount = (int) DB::table('payment_accounts')->where('name', 'Cash')->value('id');
        $vat = (int) DB::table('tax_components')->where('code', 'VAT')->value('id');
        $rent = (int) DB::table('accounts')->where('code', '6010')->value('id');
        $commission = (int) DB::table('accounts')->where('code', '4210')->value('id');
        $cashGl = (int) DB::table('accounts')->where('code', '1001')->value('id');

        $this->ids['users'] = DB::table('users')->insertGetId([
            'username' => 'owner', 'display_name' => 'Owner', 'role' => 'OWNER', 'password_hash' => 'x',
        ]);
        $user = $this->ids['users'];
        $this->ids['user_permissions'] = DB::table('user_permissions')->insertGetId(['user_id' => $user, 'module' => 'sales', 'can_view' => 1]);
        DB::table('rate_limits')->insert(['key' => 'login:shared', 'attempts' => 1, 'reset_at' => $now]);
        $this->ids['rate_limits'] = 'login:shared';
        $this->ids['business_settings'] = 1;
        $this->ids['sequences'] = 'RECEIPT';
        DB::table('units')->insert(['symbol' => 'ctn', 'name' => 'Carton', 'decimal_places' => 0]);
        $this->ids['units'] = 'ctn';

        $this->ids['accounts'] = $rent;
        $this->ids['payment_accounts'] = $cashAccount;
        $this->ids['tax_components'] = $vat;

        $this->ids['categories'] = DB::table('categories')->insertGetId(['name' => 'Drinks']);
        $this->ids['customers'] = $customer = DB::table('customers')->insertGetId(['name' => 'Ama']);
        $this->ids['suppliers'] = $supplier = DB::table('suppliers')->insertGetId(['name' => 'Kofi Wholesale']);

        $this->ids['journal_entries'] = $entry = DB::table('journal_entries')->insertGetId([
            'entry_no' => 'JE-000001', 'entry_date' => $date, 'occurred_at' => $now, 'source_type' => 'SALE', 'source_id' => 1,
        ]);
        $this->ids['journal_lines'] = DB::table('journal_lines')->insertGetId([
            'entry_id' => $entry, 'line_no' => 1, 'account_id' => $cashGl, 'debit_minor' => 500, 'payment_account_id' => $cashAccount,
        ]);
        $this->ids['year_end_closings'] = DB::table('year_end_closings')->insertGetId([
            'start_year' => 2025, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'journal_entry_id' => $entry,
            'profit_minor' => 0, 'drawings_minor' => 0, 'closed_at' => $now,
        ]);

        $this->ids['products'] = $product = DB::table('products')->insertGetId([
            'name' => 'Malt', 'sku' => 'MALT-1', 'unit' => 'pcs', 'category_id' => $this->ids['categories'],
            'qty_on_hand_milli' => 24_000, 'stock_value_minor' => 12_000,
        ]);
        $this->ids['product_batches'] = $batch = DB::table('product_batches')->insertGetId([
            'product_id' => $product, 'batch_ref' => 'BAT-00001', 'received_date' => $date, 'qty_milli' => 24_000, 'source_type' => 'OPENING',
        ]);
        $this->ids['stock_ledger'] = $ledger = DB::table('stock_ledger')->insertGetId([
            'product_id' => $product, 'business_date' => $date, 'occurred_at' => $now, 'movement_type' => 'OPENING_STOCK',
            'source_type' => 'STOCK_ADJUSTMENT', 'qty_in_milli' => 24_000, 'total_cost_minor' => 12_000,
            'balance_qty_milli' => 24_000, 'balance_value_minor' => 12_000,
        ]);
        $this->ids['stock_ledger_batches'] = DB::table('stock_ledger_batches')->insertGetId(['ledger_id' => $ledger, 'batch_id' => $batch, 'qty_in_milli' => 24_000]);
        $this->ids['stock_adjustments'] = $adjustment = DB::table('stock_adjustments')->insertGetId([
            'adjustment_no' => 'ADJ-00001', 'reason' => 'OPENING_STOCK', 'business_date' => $date, 'occurred_at' => $now,
        ]);
        $this->ids['stock_adjustment_items'] = DB::table('stock_adjustment_items')->insertGetId([
            'adjustment_id' => $adjustment, 'product_id' => $product, 'direction' => 'IN', 'qty_milli' => 24_000, 'total_cost_minor' => 12_000,
        ]);

        $this->ids['sales'] = $sale = DB::table('sales')->insertGetId([
            'receipt_no' => 'RCP-00001', 'business_date' => $date, 'occurred_at' => $now,
            'subtotal_minor' => 1_000, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 1_000,
        ]);
        $this->ids['sale_items'] = DB::table('sale_items')->insertGetId([
            'sale_id' => $sale, 'line_no' => 1, 'product_id' => $product, 'product_name' => 'Malt', 'unit' => 'pcs',
            'qty_milli' => 2_000, 'unit_price_minor' => 500, 'line_total_minor' => 1_000,
        ]);
        $this->ids['sale_payments'] = DB::table('sale_payments')->insertGetId(['sale_id' => $sale, 'payment_account_id' => $cashAccount, 'amount_minor' => 1_000]);
        $this->ids['sale_taxes'] = DB::table('sale_taxes')->insertGetId([
            'sale_id' => $sale, 'component_id' => $vat, 'code' => 'VAT', 'name' => 'VAT', 'rate_bp' => 1_500, 'amount_minor' => 0,
        ]);
        $this->ids['customer_payments'] = $payment = DB::table('customer_payments')->insertGetId([
            'payment_no' => 'RCV-00001', 'customer_id' => $customer, 'payment_account_id' => $cashAccount, 'amount_minor' => 500,
            'business_date' => $date, 'occurred_at' => $now,
        ]);
        $this->ids['customer_payment_allocations'] = DB::table('customer_payment_allocations')->insertGetId(['payment_id' => $payment, 'sale_id' => $sale, 'amount_minor' => 500]);
        $this->ids['quotations'] = $quote = DB::table('quotations')->insertGetId([
            'quote_no' => 'QTE-00001', 'business_date' => $date, 'valid_until' => '2026-11-30', 'customer_name' => 'Ama',
            'subtotal_minor' => 1_000, 'total_minor' => 1_000,
        ]);
        $this->ids['quotation_items'] = DB::table('quotation_items')->insertGetId([
            'quotation_id' => $quote, 'line_no' => 1, 'product_id' => $product, 'product_name' => 'Malt', 'unit' => 'pcs',
            'qty_milli' => 2_000, 'unit_price_minor' => 500, 'line_total_minor' => 1_000,
        ]);

        $this->ids['purchases'] = $purchase = DB::table('purchases')->insertGetId([
            'purchase_no' => 'PUR-00001', 'supplier_id' => $supplier, 'business_date' => $date, 'occurred_at' => $now,
            'subtotal_minor' => 12_000, 'total_minor' => 12_000,
        ]);
        $this->ids['purchase_items'] = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchase, 'line_no' => 1, 'product_id' => $product, 'product_name' => 'Malt', 'unit' => 'pcs',
            'qty_milli' => 24_000, 'unit_cost_minor' => 500, 'line_total_minor' => 12_000,
        ]);
        $this->ids['purchase_payments'] = DB::table('purchase_payments')->insertGetId(['purchase_id' => $purchase, 'payment_account_id' => $cashAccount, 'amount_minor' => 12_000]);
        $this->ids['purchase_taxes'] = DB::table('purchase_taxes')->insertGetId([
            'purchase_id' => $purchase, 'component_id' => $vat, 'code' => 'VAT', 'name' => 'VAT', 'rate_bp' => 1_500, 'amount_minor' => 0,
        ]);
        $this->ids['supplier_payments'] = $supplierPayment = DB::table('supplier_payments')->insertGetId([
            'payment_no' => 'PAY-00001', 'supplier_id' => $supplier, 'payment_account_id' => $cashAccount, 'amount_minor' => 500,
            'business_date' => $date, 'occurred_at' => $now,
        ]);
        $this->ids['supplier_payment_allocations'] = DB::table('supplier_payment_allocations')->insertGetId(['payment_id' => $supplierPayment, 'purchase_id' => $purchase, 'amount_minor' => 500]);

        $this->ids['expenses'] = DB::table('expenses')->insertGetId([
            'expense_no' => 'EXP-00001', 'category_account_id' => $rent, 'description' => 'Shop rent', 'amount_minor' => 50_000,
            'payment_account_id' => $cashAccount, 'business_date' => $date, 'occurred_at' => $now,
        ]);
        $this->ids['incomes'] = DB::table('incomes')->insertGetId([
            'income_no' => 'INC-00001', 'category_account_id' => $commission, 'description' => 'MoMo commission', 'amount_minor' => 2_000,
            'payment_account_id' => $cashAccount, 'business_date' => $date, 'occurred_at' => $now,
        ]);
        $this->ids['owner_movements'] = DB::table('owner_movements')->insertGetId([
            'movement_no' => 'INC-00002', 'kind' => 'CAPITAL', 'payment_account_id' => $cashAccount, 'amount_minor' => 100_000,
            'description' => 'Opening capital', 'business_date' => $date, 'occurred_at' => $now,
        ]);
        $bank = (int) DB::table('payment_accounts')->where('name', 'Bank Account')->value('id');
        $this->ids['account_transfers'] = DB::table('account_transfers')->insertGetId([
            'transfer_no' => 'TRF-00001', 'from_account_id' => $cashAccount, 'to_account_id' => $bank, 'amount_minor' => 10_000,
            'business_date' => $date, 'occurred_at' => $now,
        ]);
        $this->ids['reconciliations'] = DB::table('reconciliations')->insertGetId([
            'reconciliation_no' => 'REC-00001', 'payment_account_id' => $cashAccount, 'expected_minor' => 2_450, 'actual_minor' => 2_450,
            'difference_minor' => 0, 'business_date' => $date, 'occurred_at' => $now,
        ]);
        $this->ids['audit_logs'] = (int) DB::table('audit_logs')->value('id');
    }

    /** The primary-key column for a table's valid row. */
    public static function keyOf(string $table): string
    {
        return match ($table) {
            'rate_limits' => 'key',
            'sequences' => 'doc_type',
            'units' => 'symbol',
            default => 'id',
        };
    }
}
