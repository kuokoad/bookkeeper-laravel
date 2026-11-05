<?php

use App\Database\Columns;
use App\Database\Constraints as C;
use App\Enums\DocumentStatus;
use App\Enums\PurchaseKind;
use App\Enums\TaxBasis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchases (and their return and void mirrors) and supplier payments.
 * Ported from the old purchases.ts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('purchase_no', 32)->unique('uq_purchases_no');
            $table->string('kind', 16)->default('PURCHASE');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');
            $table->string('invoice_no', 64)->nullable();
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->foreignId('returns_purchase_id')->nullable()->constrained('purchases');
            $table->foreignId('voids_purchase_id')->nullable()->constrained('purchases');
            $table->foreignId('voided_by_purchase_id')->nullable()->constrained('purchases');
            $table->text('note')->nullable();
            Columns::postedDocument($table);
            Columns::timestamps($table);

            $table->index('business_date', 'idx_purchases_date');
            $table->index('kind', 'idx_purchases_kind');
            $table->index('status', 'idx_purchases_status');
        });
        C::check('purchases', [
            'ck_purchases_status' => C::in('status', DocumentStatus::class),
            'ck_purchases_kind' => C::in('kind', PurchaseKind::class),
            'ck_purchases_total_arithmetic' => '`total_minor` = `subtotal_minor` - `discount_minor` + `tax_minor`',
        ] + C::booleans('is_demo'));
        C::noSelfReference('purchases', 'voids_purchase_id', 'A purchase cannot void itself.');
        C::noSelfReference('purchases', 'returns_purchase_id', 'A purchase cannot return itself.');
        C::noSelfReference('purchases', 'voided_by_purchase_id', 'A purchase cannot be voided by itself.');

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $table->integer('line_no');
            $table->foreignId('product_id')->constrained('products');
            $table->text('product_name');
            $table->string('unit', 768);
            $table->bigInteger('qty_milli');
            $table->bigInteger('unit_cost_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('line_total_minor');
            $table->bigInteger('returned_qty_milli')->default(0);
            Columns::entryUnit($table);
            Columns::createdAt($table);

            $table->unique(['purchase_id', 'line_no'], 'uq_purchase_items_purchase_line');
        });
        C::check('purchase_items', [
            'ck_purchase_items_qty_nonzero' => '`qty_milli` <> 0',
            'ck_purchase_items_returned_nonneg' => '`returned_qty_milli` >= 0',
        ]);

        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $table->foreignId('payment_account_id')->constrained('payment_accounts');
            $table->bigInteger('amount_minor');
            $table->string('reference')->nullable();
            Columns::createdAt($table);
        });
        C::check('purchase_payments', ['ck_purchase_payments_amount_nonzero' => '`amount_minor` <> 0']);

        Schema::create('purchase_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $table->foreignId('component_id')->nullable()->constrained('tax_components')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->bigInteger('rate_bp');
            $table->string('basis', 16)->default('NET');
            $table->bigInteger('amount_minor');
            // Recoverability is frozen at the time of the document.
            $table->boolean('is_recoverable')->default(false);
            Columns::createdAt($table);

            $table->unique(['purchase_id', 'code'], 'uq_purchase_taxes_purchase_code');
        });
        C::check('purchase_taxes', ['ck_purchase_taxes_basis' => C::in('basis', TaxBasis::class)] + C::booleans('is_recoverable'));

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_no', 32)->unique('uq_supplier_payments_no');
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('payment_account_id')->constrained('payment_accounts');
            $table->bigInteger('amount_minor');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            Columns::postedDocument($table);
            Columns::timestamps($table);

            $table->index('business_date', 'idx_supplier_payments_date');
        });
        C::check('supplier_payments', [
            'ck_supplier_payments_status' => C::in('status', DocumentStatus::class),
            'ck_supplier_payments_amount_positive' => '`amount_minor` > 0',
        ] + C::booleans('is_demo'));

        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('supplier_payments')->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained('purchases');
            $table->bigInteger('amount_minor');
            Columns::createdAt($table);

            $table->unique(['payment_id', 'purchase_id'], 'uq_supplier_payment_allocations');
        });
        C::check('supplier_payment_allocations', [
            'ck_supplier_payment_allocations_amount_nonzero' => '`amount_minor` <> 0',
        ]);
    }

    public function down(): void
    {
        foreach (['supplier_payment_allocations', 'supplier_payments', 'purchase_taxes', 'purchase_payments', 'purchase_items', 'purchases'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
