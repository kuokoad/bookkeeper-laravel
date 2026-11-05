<?php

declare(strict_types=1);

use App\Database\Columns;
use App\Database\Constraints as C;
use App\Enums\AccountType;
use App\Enums\JournalSourceType;
use App\Enums\NormalBalance;
use App\Enums\PaymentAccountKind;
use App\Enums\TaxBasis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chart of accounts, payment accounts, tax components, customers, suppliers
 * and the journal. Ported from the old accounting.ts, cashbook.ts, tax.ts,
 * parties.ts and suppliers.ts.
 *
 * No table here has a balance column. Every balance is SUM(debit) − SUM(credit)
 * over journal_lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique('uq_accounts_code');
            $table->string('name');
            $table->string('type', 16);
            $table->string('normal_balance', 8);
            $table->foreignId('parent_id')->nullable()->constrained('accounts');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            Columns::timestamps($table);

            $table->index('type', 'idx_accounts_type');
        });
        C::check('accounts', [
            'ck_accounts_code_len' => 'CHAR_LENGTH(`code`) BETWEEN 1 AND 20',
            'ck_accounts_type' => C::in('type', AccountType::class),
            'ck_accounts_normal_balance' => C::in('normal_balance', NormalBalance::class),
            // A revenue account with a debit normal balance would make every
            // sign-adjusted report present it backwards.
            'ck_accounts_normal_balance_matches_type' => "(`type` IN ('ASSET','EXPENSE','COGS','CONTRA_EQUITY','CONTRA_REVENUE') AND `normal_balance` = 'DEBIT')"
                ." OR (`type` IN ('LIABILITY','EQUITY','REVENUE') AND `normal_balance` = 'CREDIT')",
        ] + C::booleans('is_system', 'is_active'));
        C::noSelfReference('accounts', 'parent_id', 'An account cannot be its own parent.');

        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 768);
            $table->string('kind', 16);
            $table->string('provider')->nullable();
            $table->string('account_number', 64)->nullable();
            $table->foreignId('gl_account_id')->unique('uq_payment_accounts_gl')->constrained('accounts');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            Columns::isDemo($table);
            Columns::timestamps($table);

            $table->index('is_active', 'idx_payment_accounts_active');
        });
        C::check('payment_accounts', [
            'ck_payment_accounts_kind' => C::in('kind', PaymentAccountKind::class),
            'ck_payment_accounts_name' => 'CHAR_LENGTH(`name`) > 0',
        ] + C::booleans('is_active', 'is_default', 'is_demo'));
        C::uniqueLower('payment_accounts', 'uq_payment_accounts_name', 'name');

        Schema::create('tax_components', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique('uq_tax_components_code');
            $table->string('name');
            $table->bigInteger('rate_bp')->default(0);
            $table->string('basis', 16)->default('NET');
            $table->boolean('is_recoverable')->default(false);
            $table->foreignId('gl_account_id')->constrained('accounts');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            Columns::timestamps($table);

            $table->index('is_active', 'idx_tax_components_active');
        });
        C::check('tax_components', [
            'ck_tax_components_rate' => '`rate_bp` >= 0 AND `rate_bp` <= 100000',
            'ck_tax_components_basis' => C::in('basis', TaxBasis::class),
            'ck_tax_components_code' => 'CHAR_LENGTH(`code`) > 0',
            'ck_tax_components_name' => 'CHAR_LENGTH(`name`) > 0',
        ] + C::booleans('is_recoverable', 'is_active'));

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 1000);
            $table->string('phone', 64)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('credit_limit_minor')->nullable();
            $table->boolean('is_active')->default(true);
            Columns::createdBy($table);
            Columns::isDemo($table);
            Columns::timestamps($table);

            $table->index('is_active', 'idx_customers_active');
            $table->index('phone', 'idx_customers_phone');
        });
        C::prefixIndex('customers', 'idx_customers_name', 'name');
        C::check('customers', [
            'ck_customers_name' => 'CHAR_LENGTH(TRIM(`name`)) > 0',
            'ck_customers_credit_limit' => '`credit_limit_minor` IS NULL OR `credit_limit_minor` >= 0',
        ] + C::booleans('is_active', 'is_demo'));

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 1000);
            $table->string('contact_person')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            Columns::createdBy($table);
            Columns::isDemo($table);
            Columns::timestamps($table);

            $table->index('is_active', 'idx_suppliers_active');
            $table->index('phone', 'idx_suppliers_phone');
        });
        C::prefixIndex('suppliers', 'idx_suppliers_name', 'name');
        C::check('suppliers', ['ck_suppliers_name' => 'CHAR_LENGTH(TRIM(`name`)) > 0'] + C::booleans('is_active', 'is_demo'));

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_no', 32)->unique('uq_journal_entries_no');
            $table->date('entry_date');
            $table->dateTime('occurred_at', 3);
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('memo')->nullable();
            $table->boolean('is_opening')->default(false);
            // Year-end close and its reversal: excluded from the P&L, included in the balance sheet.
            $table->boolean('is_closing')->default(false);
            $table->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries');
            $table->foreignId('reversed_by_entry_id')->nullable()->constrained('journal_entries');
            Columns::createdBy($table);
            Columns::isDemo($table);
            Columns::createdAt($table);

            $table->index('entry_date', 'idx_journal_entries_date');
            $table->index('occurred_at', 'idx_journal_entries_occurred');
            $table->index(['source_type', 'source_id'], 'idx_journal_entries_source');
        });
        C::check('journal_entries', [
            'ck_journal_entries_source_type' => C::in('source_type', JournalSourceType::class),
            // Traceability is a database rule, not a convention.
            'ck_journal_entries_traceable' => "`source_id` IS NOT NULL OR `source_type` IN ('OPENING_BALANCE', 'YEAR_END_CLOSE')",
        ] + C::booleans('is_opening', 'is_closing', 'is_demo'));
        C::noSelfReference('journal_entries', 'reverses_entry_id', 'A journal entry cannot reverse itself.');
        C::noSelfReference('journal_entries', 'reversed_by_entry_id', 'A journal entry cannot be reversed by itself.');

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->integer('line_no');
            $table->foreignId('account_id')->constrained('accounts');
            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);
            $table->foreignId('payment_account_id')->nullable()->constrained('payment_accounts');
            // AR / AP subledger tags.
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');
            $table->text('description')->nullable();
            Columns::createdAt($table);

            $table->unique(['entry_id', 'line_no'], 'uq_journal_lines_entry_line');
            $table->index(['account_id', 'entry_id'], 'idx_journal_lines_account');
        });
        C::check('journal_lines', [
            'ck_journal_lines_debit_nonneg' => '`debit_minor` >= 0',
            'ck_journal_lines_credit_nonneg' => '`credit_minor` >= 0',
            'ck_journal_lines_one_sided' => '(`debit_minor` = 0 AND `credit_minor` > 0) OR (`debit_minor` > 0 AND `credit_minor` = 0)',
        ]);

        Schema::create('year_end_closings', function (Blueprint $table) {
            $table->id();
            $table->integer('start_year');
            $table->date('period_start');
            $table->date('period_end');
            $table->foreignId('journal_entry_id')->constrained('journal_entries');
            $table->bigInteger('profit_minor');
            $table->bigInteger('drawings_minor');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at', 3);
            $table->dateTime('reversed_at', 3)->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_entry_id')->nullable()->constrained('journal_entries');
            // MySQL has no partial index; this stands in for
            // UNIQUE (start_year) WHERE reversed_at IS NULL.
            $table->integer('open_start_year')->nullable()->storedAs('IF(`reversed_at` IS NULL, `start_year`, NULL)');
            Columns::createdAt($table);

            $table->index('start_year', 'idx_year_end_closings_year');
            $table->unique('open_start_year', 'uq_year_end_closings_open');
        });
        C::check('year_end_closings', [
            'ck_year_end_closings_period' => '`period_end` > `period_start`',
            'ck_year_end_closings_reversal' => '(`reversed_at` IS NULL AND `reversal_entry_id` IS NULL) OR (`reversed_at` IS NOT NULL AND `reversal_entry_id` IS NOT NULL)',
        ]);
    }

    public function down(): void
    {
        foreach (['year_end_closings', 'journal_lines', 'journal_entries', 'suppliers', 'customers', 'tax_components', 'payment_accounts', 'accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
