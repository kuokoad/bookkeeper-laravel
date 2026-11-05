<?php

use App\Database\Columns;
use App\Database\Constraints as C;
use App\Enums\AdjustmentDirection;
use App\Enums\AdjustmentReason;
use App\Enums\DocumentStatus;
use App\Enums\MovementType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Units, categories, products, batches, the stock ledger and stock
 * adjustments. Ported from the old catalog.ts and inventory.ts.
 *
 * products.qty_on_hand_milli / stock_value_minor are the running pair (Q, V):
 * a read cache of the newest stock_ledger row, recomputable from row 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            // Widths follow the old CSV import, which accepted cells up to 1,000
            // characters. 768 is the widest a utf8mb4 key can be (3,072 bytes).
            $table->string('symbol', 768)->primary();
            $table->string('name', 1000);
            $table->unsignedTinyInteger('decimal_places')->default(0);
            Columns::timestamps($table);
        });
        C::check('units', [
            'ck_units_symbol' => 'CHAR_LENGTH(TRIM(`symbol`)) > 0',
            'ck_units_decimals' => '`decimal_places` BETWEEN 0 AND 3',
        ]);
        C::uniqueLower('units', 'uq_units_symbol_nocase', 'symbol');
        // The old migration 0025 seeded this one unit as part of the schema.
        DB::table('units')->insert(['symbol' => 'pcs', 'name' => 'Pieces', 'decimal_places' => 0]);

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 768);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            Columns::isDemo($table);
            Columns::timestamps($table);

            $table->index('is_active', 'idx_categories_active');
        });
        C::check('categories', ['ck_categories_name' => 'CHAR_LENGTH(TRIM(`name`)) > 0'] + C::booleans('is_active', 'is_demo'));
        C::uniqueLower('categories', 'uq_categories_name', 'name');

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 1000);
            $table->string('sku', 768)->nullable();
            $table->string('barcode', 768)->nullable()->unique('uq_products_barcode');
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            // No ON UPDATE CASCADE: MySQL forbids a CHECK on a column with an FK
            // action, so a unit rename updates products in UnitsService (PLAN Q6).
            $table->string('unit', 768);
            $table->string('alt_unit', 768)->nullable();
            $table->bigInteger('alt_factor_milli')->nullable();
            $table->bigInteger('alt_cost_price_minor')->nullable();
            $table->bigInteger('alt_selling_price_minor')->nullable();
            $table->text('description')->nullable();
            $table->bigInteger('cost_price_minor')->default(0);
            $table->bigInteger('selling_price_minor')->default(0);
            $table->bigInteger('min_stock_milli')->nullable();
            $table->integer('warn_days')->nullable();
            $table->bigInteger('qty_on_hand_milli')->default(0);
            $table->bigInteger('stock_value_minor')->default(0);
            $table->boolean('track_inventory')->default(true);
            $table->boolean('is_active')->default(true);
            Columns::createdBy($table);
            Columns::isDemo($table);
            Columns::timestamps($table);

            $table->foreign('unit')->references('symbol')->on('units');
            $table->foreign('alt_unit')->references('symbol')->on('units');
            $table->index('is_active', 'idx_products_active');
        });
        C::check('products', [
            'ck_products_name' => 'CHAR_LENGTH(TRIM(`name`)) > 0',
            'ck_products_unit' => 'CHAR_LENGTH(TRIM(`unit`)) > 0',
            // A carton (second unit) is all-or-nothing, has a positive size and differs from the base unit.
            // Stricter than the old app: there a NULL size made the check NULL, which passes,
            // so a carton with no size was accepted. `IS NOT NULL` closes that.
            'ck_products_alt_unit' => '(`alt_unit` IS NULL AND `alt_factor_milli` IS NULL AND `alt_cost_price_minor` IS NULL AND `alt_selling_price_minor` IS NULL)'
                .' OR (`alt_unit` IS NOT NULL AND `alt_factor_milli` IS NOT NULL AND `alt_factor_milli` > 0 AND `alt_unit` <> `unit`)',
            'ck_products_alt_prices' => 'COALESCE(`alt_cost_price_minor`, 0) >= 0 AND COALESCE(`alt_selling_price_minor`, 0) >= 0',
            'ck_products_cost_price' => '`cost_price_minor` >= 0',
            'ck_products_selling_price' => '`selling_price_minor` >= 0',
            'ck_products_min_stock' => '`min_stock_milli` IS NULL OR `min_stock_milli` >= 0',
            'ck_products_warn_days' => '`warn_days` IS NULL OR `warn_days` >= 0',
            // Inventory can never hold value with zero quantity.
            'ck_products_zero_qty_zero_value' => '`qty_on_hand_milli` <> 0 OR `stock_value_minor` = 0',
        ] + C::booleans('track_inventory', 'is_active', 'is_demo'));
        C::uniqueLower('products', 'uq_products_sku', 'sku');
        C::prefixIndex('products', 'idx_products_name', 'name');

        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            $table->string('batch_ref', 64)->unique('uq_product_batches_ref');
            $table->date('expiry_date')->nullable();
            $table->date('received_date');
            $table->bigInteger('qty_milli')->default(0);
            $table->bigInteger('opening_qty_milli')->default(0);
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->integer('warn_days')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_closed')->default(false);
            Columns::isDemo($table);
            Columns::timestamps($table);

            $table->index('expiry_date', 'idx_product_batches_expiry');
            $table->index(['product_id', 'is_closed'], 'idx_product_batches_open');
            $table->index(['product_id', 'expiry_date'], 'idx_product_batches_product');
            $table->index(['source_type', 'source_id'], 'idx_product_batches_source');
        });
        C::check('product_batches', [
            'ck_product_batches_ref' => 'CHAR_LENGTH(TRIM(`batch_ref`)) > 0',
            'ck_product_batches_warn_days' => '`warn_days` IS NULL OR `warn_days` >= 0',
            'ck_product_batches_closed_is_empty' => '`is_closed` = 0 OR `qty_milli` = 0',
        ] + C::booleans('is_closed', 'is_demo'));

        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            $table->date('business_date');
            $table->dateTime('occurred_at', 3);
            $table->string('movement_type', 32);
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_ref', 64)->nullable();
            $table->bigInteger('qty_in_milli')->default(0);
            $table->bigInteger('qty_out_milli')->default(0);
            // unit_cost is for display; total_cost is the exact figure.
            $table->bigInteger('unit_cost_minor')->default(0);
            $table->bigInteger('total_cost_minor')->default(0);
            // The running pair (Q, V) after this movement.
            $table->bigInteger('balance_qty_milli');
            $table->bigInteger('balance_value_minor');
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            Columns::isDemo($table);
            Columns::createdAt($table);

            $table->index('business_date', 'idx_stock_ledger_date');
            $table->index('movement_type', 'idx_stock_ledger_movement');
            $table->index('occurred_at', 'idx_stock_ledger_occurred');
            $table->index(['product_id', 'id'], 'idx_stock_ledger_product');
            $table->index(['source_type', 'source_id'], 'idx_stock_ledger_source');
        });
        C::check('stock_ledger', [
            'ck_stock_ledger_movement_type' => C::in('movement_type', MovementType::class),
            'ck_stock_ledger_qty_in_nonneg' => '`qty_in_milli` >= 0',
            'ck_stock_ledger_qty_out_nonneg' => '`qty_out_milli` >= 0',
            'ck_stock_ledger_one_direction' => '(`qty_in_milli` > 0 AND `qty_out_milli` = 0) OR (`qty_in_milli` = 0 AND `qty_out_milli` > 0)',
            'ck_stock_ledger_zero_qty_zero_value' => '`balance_qty_milli` <> 0 OR `balance_value_minor` = 0',
        ] + C::booleans('is_demo'));

        Schema::create('stock_ledger_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_id')->constrained('stock_ledger');
            $table->foreignId('batch_id')->constrained('product_batches');
            $table->bigInteger('qty_in_milli')->default(0);
            $table->bigInteger('qty_out_milli')->default(0);
            Columns::createdAt($table);

            $table->unique(['ledger_id', 'batch_id'], 'uq_stock_ledger_batches');
        });
        C::check('stock_ledger_batches', [
            'ck_stock_ledger_batches_one_direction' => '(`qty_in_milli` > 0 AND `qty_out_milli` = 0) OR (`qty_in_milli` = 0 AND `qty_out_milli` > 0)',
        ]);

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_no', 32)->unique('uq_stock_adjustments_no');
            $table->string('reason', 32);
            $table->text('note')->nullable();
            $table->foreignId('voided_by_adjustment_id')->nullable()->constrained('stock_adjustments');
            $table->foreignId('voids_adjustment_id')->nullable()->constrained('stock_adjustments');
            Columns::postedDocument($table);
            Columns::timestamps($table);

            $table->index('business_date', 'idx_stock_adjustments_date');
            $table->index('reason', 'idx_stock_adjustments_reason');
            $table->index('status', 'idx_stock_adjustments_status');
        });
        C::check('stock_adjustments', [
            'ck_stock_adjustments_reason' => C::in('reason', AdjustmentReason::class),
            'ck_stock_adjustments_status' => C::in('status', DocumentStatus::class),
        ] + C::booleans('is_demo'));
        C::noSelfReference('stock_adjustments', 'voids_adjustment_id', 'An adjustment cannot void itself.');
        C::noSelfReference('stock_adjustments', 'voided_by_adjustment_id', 'An adjustment cannot be voided by itself.');

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->string('direction', 3);
            $table->bigInteger('qty_milli');
            $table->bigInteger('unit_cost_minor')->default(0);
            $table->bigInteger('total_cost_minor')->default(0);
            $table->text('note')->nullable();
            $table->foreignId('batch_id')->nullable()->constrained('product_batches');
            Columns::entryUnit($table);
            Columns::createdAt($table);
        });
        C::check('stock_adjustment_items', [
            'ck_stock_adjustment_items_direction' => C::in('direction', AdjustmentDirection::class),
            'ck_stock_adjustment_items_qty_positive' => '`qty_milli` > 0',
            'ck_stock_adjustment_items_cost_nonneg' => '`total_cost_minor` >= 0',
        ]);
    }

    public function down(): void
    {
        foreach (['stock_adjustment_items', 'stock_adjustments', 'stock_ledger_batches', 'stock_ledger', 'product_batches', 'products', 'categories', 'units'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
