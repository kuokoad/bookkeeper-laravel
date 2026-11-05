<?php

declare(strict_types=1);

use App\Database\Columns;
use App\Database\Constraints as C;
use App\Enums\DocumentStatus;
use App\Enums\QuotationStatus;
use App\Enums\SaleKind;
use App\Enums\TaxBasis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales (and their return and void mirrors), customer payments and
 * quotations. Ported from the old sales.ts and quotations.ts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 32)->unique('uq_sales_receipt_no');
            $table->string('kind', 8)->default('SALE');
            $table->foreignId('returns_sale_id')->nullable()->constrained('sales');
            // Only a sale not paid in full gets an invoice number, so the sequence has no gaps.
            $table->string('invoice_no', 32)->nullable()->unique('uq_sales_invoice_no');
            $table->integer('terms_days')->nullable();
            $table->date('due_date')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->boolean('tax_inclusive')->default(false);
            $table->bigInteger('cogs_minor')->default(0);
            $table->foreignId('voided_by_sale_id')->nullable()->constrained('sales');
            $table->foreignId('voids_sale_id')->nullable()->constrained('sales');
            $table->text('note')->nullable();
            // The till's cart id: the same submission twice is one sale.
            $table->string('client_ref', 64)->nullable()->unique('uq_sales_client_ref');
            Columns::postedDocument($table);
            Columns::timestamps($table);

            $table->index('business_date', 'idx_sales_date');
            $table->index('due_date', 'idx_sales_due_date');
            $table->index('occurred_at', 'idx_sales_occurred');
            $table->index('status', 'idx_sales_status');
        });
        C::check('sales', [
            'ck_sales_status' => C::in('status', DocumentStatus::class),
            'ck_sales_kind' => C::in('kind', SaleKind::class),
            // A sale is all non-negative; its return or void mirror is all non-positive.
            'ck_sales_signs_consistent' => '(`subtotal_minor` >= 0 AND `discount_minor` >= 0 AND `tax_minor` >= 0 AND `total_minor` >= 0)'
                .' OR (`subtotal_minor` <= 0 AND `discount_minor` <= 0 AND `tax_minor` <= 0 AND `total_minor` <= 0)',
            'ck_sales_total_arithmetic' => '`total_minor` = `subtotal_minor` - `discount_minor` + `tax_minor`',
        ] + C::booleans('tax_inclusive', 'is_demo'));
        C::noSelfReference('sales', 'voids_sale_id', 'A sale cannot void itself.');
        C::noSelfReference('sales', 'returns_sale_id', 'A sale cannot return itself.');
        C::noSelfReference('sales', 'voided_by_sale_id', 'A sale cannot be voided by itself.');

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->integer('line_no');
            $table->foreignId('product_id')->constrained('products');
            $table->text('product_name');
            $table->string('unit', 768);
            $table->bigInteger('qty_milli');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('line_total_minor');
            // Cost basis snapshotted at sale time: a price change tomorrow cannot move last week's profit.
            $table->bigInteger('unit_cost_minor')->default(0);
            $table->bigInteger('total_cost_minor')->default(0);
            $table->bigInteger('returned_qty_milli')->default(0);
            Columns::entryUnit($table);
            Columns::createdAt($table);

            $table->unique(['sale_id', 'line_no'], 'uq_sale_items_sale_line');
        });
        C::check('sale_items', [
            'ck_sale_items_qty_nonzero' => '`qty_milli` <> 0',
            'ck_sale_items_price_nonneg' => '`unit_price_minor` >= 0',
            'ck_sale_items_discount_nonneg' => '`discount_minor` >= 0',
            'ck_sale_items_returned_nonneg' => '`returned_qty_milli` >= 0',
        ]);

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('payment_account_id')->constrained('payment_accounts');
            $table->bigInteger('amount_minor');
            $table->string('reference')->nullable();
            Columns::createdAt($table);
        });
        C::check('sale_payments', ['ck_sale_payments_amount_nonzero' => '`amount_minor` <> 0']);

        Schema::create('sale_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('component_id')->nullable()->constrained('tax_components')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->bigInteger('rate_bp');
            $table->string('basis', 16)->default('NET');
            $table->bigInteger('amount_minor');
            Columns::createdAt($table);

            $table->unique(['sale_id', 'code'], 'uq_sale_taxes_sale_code');
        });
        C::check('sale_taxes', ['ck_sale_taxes_basis' => C::in('basis', TaxBasis::class)]);

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_no', 32)->unique('uq_customer_payments_no');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('payment_account_id')->constrained('payment_accounts');
            $table->bigInteger('amount_minor');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            Columns::postedDocument($table);
            Columns::timestamps($table);

            $table->index('business_date', 'idx_customer_payments_date');
            $table->index('status', 'idx_customer_payments_status');
        });
        C::check('customer_payments', [
            'ck_customer_payments_status' => C::in('status', DocumentStatus::class),
            'ck_customer_payments_amount_positive' => '`amount_minor` > 0',
        ] + C::booleans('is_demo'));

        Schema::create('customer_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('customer_payments')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales');
            $table->bigInteger('amount_minor');
            Columns::createdAt($table);

            $table->unique(['payment_id', 'sale_id'], 'uq_customer_payment_allocations');
        });
        C::check('customer_payment_allocations', [
            'ck_customer_payment_allocations_amount_nonzero' => '`amount_minor` <> 0',
        ]);

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quote_no', 32)->unique('uq_quotations_no');
            $table->date('business_date');
            $table->date('valid_until');
            $table->string('customer_name');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_phone', 64)->nullable();
            $table->string('reference')->nullable();
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('quote_discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->boolean('tax_inclusive')->default(false);
            $table->string('status', 16)->default('OPEN');
            // A quote converts once.
            $table->foreignId('converted_sale_id')->nullable()->unique('uq_quotations_converted_sale')->constrained('sales');
            $table->dateTime('converted_at', 3)->nullable();
            $table->text('override_reason')->nullable();
            $table->dateTime('cancelled_at', 3)->nullable();
            $table->text('cancel_reason')->nullable();
            $table->text('notes')->nullable();
            Columns::createdBy($table);
            Columns::isDemo($table);
            Columns::timestamps($table);

            $table->index('business_date', 'idx_quotations_date');
            $table->index('status', 'idx_quotations_status');
            $table->index('valid_until', 'idx_quotations_valid_until');
        });
        C::check('quotations', [
            'ck_quotations_status' => C::in('status', QuotationStatus::class),
            'ck_quotations_name' => 'CHAR_LENGTH(TRIM(`customer_name`)) > 0',
            'ck_quotations_quote_no' => 'CHAR_LENGTH(TRIM(`quote_no`)) > 0',
            'ck_quotations_dates' => '`valid_until` >= `business_date`',
            'ck_quotations_signs' => '`subtotal_minor` >= 0 AND `discount_minor` >= 0 AND `tax_minor` >= 0 AND `total_minor` >= 0',
            'ck_quotations_total_arithmetic' => '`total_minor` = `subtotal_minor` - `discount_minor` + `tax_minor`',
            'ck_quotations_converted_link' => "(`status` = 'CONVERTED') = (`converted_sale_id` IS NOT NULL)",
            'ck_quotations_cancelled_reason' => "`status` = 'CANCELLED' OR `cancelled_at` IS NULL",
        ] + C::booleans('tax_inclusive', 'is_demo'));

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->integer('line_no');
            $table->foreignId('product_id')->constrained('products');
            $table->text('product_name');
            $table->string('unit', 768);
            $table->bigInteger('qty_milli');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('line_total_minor');
            Columns::entryUnit($table);
            Columns::createdAt($table);

            $table->unique(['quotation_id', 'line_no'], 'uq_quotation_items_line');
        });
        C::check('quotation_items', [
            'ck_quotation_items_qty_positive' => '`qty_milli` > 0',
            'ck_quotation_items_price_nonneg' => '`unit_price_minor` >= 0',
            'ck_quotation_items_discount_nonneg' => '`discount_minor` >= 0',
        ]);
    }

    public function down(): void
    {
        foreach (['quotation_items', 'quotations', 'customer_payment_allocations', 'customer_payments', 'sale_taxes', 'sale_payments', 'sale_items', 'sales'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
