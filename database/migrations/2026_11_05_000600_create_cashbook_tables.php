<?php

use App\Database\Columns;
use App\Database\Constraints as C;
use App\Enums\DocumentStatus;
use App\Enums\OwnerMovementKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expenses, other income, owner capital and drawings, transfers between
 * accounts and reconciliations. Ported from the old cashbook.ts and
 * reconciliation.ts.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['expenses' => 'expense_no', 'incomes' => 'income_no'] as $name => $numberColumn) {
            Schema::create($name, function (Blueprint $table) use ($name, $numberColumn) {
                $table->id();
                $table->string($numberColumn, 32)->unique("uq_{$name}_no");
                $table->foreignId('category_account_id')->constrained('accounts');
                $table->string('description');
                $table->bigInteger('amount_minor');
                $table->foreignId('payment_account_id')->constrained('payment_accounts');
                $table->string('reference')->nullable();
                $table->text('note')->nullable();
                Columns::postedDocument($table);
                Columns::timestamps($table);

                $table->index('business_date', "idx_{$name}_date");
                $table->index('status', "idx_{$name}_status");
            });
            C::check($name, [
                "ck_{$name}_status" => C::in('status', DocumentStatus::class),
                "ck_{$name}_amount_positive" => '`amount_minor` > 0',
                "ck_{$name}_description" => 'CHAR_LENGTH(TRIM(`description`)) > 0',
            ] + C::booleans('is_demo'));
        }

        Schema::create('owner_movements', function (Blueprint $table) {
            $table->id();
            $table->string('movement_no', 32)->unique('uq_owner_movements_no');
            $table->string('kind', 16);
            $table->foreignId('payment_account_id')->constrained('payment_accounts');
            $table->bigInteger('amount_minor');
            $table->string('description');
            Columns::postedDocument($table);
            Columns::timestamps($table);

            $table->index('business_date', 'idx_owner_movements_date');
            $table->index('kind', 'idx_owner_movements_kind');
        });
        C::check('owner_movements', [
            'ck_owner_movements_kind' => C::in('kind', OwnerMovementKind::class),
            'ck_owner_movements_status' => C::in('status', DocumentStatus::class),
            'ck_owner_movements_amount_positive' => '`amount_minor` > 0',
        ] + C::booleans('is_demo'));

        Schema::create('account_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_no', 32)->unique('uq_account_transfers_no');
            $table->foreignId('from_account_id')->constrained('payment_accounts');
            $table->foreignId('to_account_id')->constrained('payment_accounts');
            $table->bigInteger('amount_minor');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            Columns::postedDocument($table);
            Columns::timestamps($table);

            $table->index('business_date', 'idx_account_transfers_date');
        });
        C::check('account_transfers', [
            'ck_account_transfers_status' => C::in('status', DocumentStatus::class),
            'ck_account_transfers_amount_positive' => '`amount_minor` > 0',
            'ck_account_transfers_distinct' => '`from_account_id` <> `to_account_id`',
        ] + C::booleans('is_demo'));

        Schema::create('reconciliations', function (Blueprint $table) {
            $table->id();
            $table->string('reconciliation_no', 32)->unique('uq_reconciliations_no');
            $table->foreignId('payment_account_id')->constrained('payment_accounts');
            $table->bigInteger('expected_minor');
            $table->bigInteger('actual_minor');
            $table->bigInteger('difference_minor');
            $table->text('explanation')->nullable();
            // True when the difference was posted to Cash Over / Short.
            $table->boolean('adjusted')->default(false);
            Columns::postedDocument($table);
            Columns::timestamps($table);

            $table->index(['payment_account_id', 'business_date'], 'idx_reconciliations_account');
            $table->index('business_date', 'idx_reconciliations_date');
            $table->index('status', 'idx_reconciliations_status');
        });
        C::check('reconciliations', [
            'ck_reconciliations_status' => C::in('status', DocumentStatus::class),
            'ck_reconciliations_difference' => '`difference_minor` = `actual_minor` - `expected_minor`',
            'ck_reconciliations_explained' => '`difference_minor` = 0 OR (`explanation` IS NOT NULL AND CHAR_LENGTH(TRIM(`explanation`)) > 0)',
        ] + C::booleans('adjusted', 'is_demo'));
    }

    public function down(): void
    {
        foreach (['reconciliations', 'account_transfers', 'owner_movements', 'incomes', 'expenses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
