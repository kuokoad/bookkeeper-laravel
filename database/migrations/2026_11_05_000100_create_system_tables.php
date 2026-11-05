<?php

use App\Database\Columns;
use App\Database\Constraints as C;
use App\Enums\AuditAction;
use App\Enums\BusinessType;
use App\Enums\Look;
use App\Enums\PermissionModule;
use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Users, permissions, sessions, throttles, the audit log, shop settings and
 * document numbering. Ported from the old src/db/schema/users.ts and system.ts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 40);
            $table->string('display_name');
            $table->string('role', 16)->default('STAFF');
            // Laravel hash, or an imported legacy `scrypt$...` string until first sign-in.
            $table->string('password_hash');
            $table->string('pin_hash')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('failed_login_count')->default(0);
            $table->dateTime('locked_until', 3)->nullable();
            $table->dateTime('last_login_at', 3)->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            Columns::timestamps($table);

            $table->index('is_active', 'idx_users_active');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
        C::check('users', [
            'ck_users_username_len' => 'CHAR_LENGTH(`username`) BETWEEN 3 AND 40',
            'ck_users_failed_login_count' => '`failed_login_count` >= 0',
            'ck_users_role' => C::in('role', UserRole::class),
        ] + C::booleans('is_active', 'must_change_password'));
        C::uniqueLower('users', 'uq_users_username', 'username');

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('module', 32);
            $table->boolean('can_view')->default(false);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_void')->default(false);
            Columns::timestamps($table);

            $table->unique(['user_id', 'module'], 'uq_user_permissions_user_module');
        });
        C::check('user_permissions', [
            'ck_user_permissions_module' => C::in('module', PermissionModule::class),
        ] + C::booleans('can_view', 'can_create', 'can_edit', 'can_void'));

        // Laravel's own session store replaces the old hashed-token table (PLAN Q7).
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // Login throttles live in the database so a restart cannot reset them.
        Schema::create('rate_limits', function (Blueprint $table) {
            // 'login:<client address>': the address comes from a header, so allow for a long one.
            $table->string('key', 768)->primary();
            $table->integer('attempts')->default(0);
            $table->dateTime('reset_at', 3);

            $table->index('reset_at', 'idx_rate_limits_reset');
        });
        C::check('rate_limits', ['ck_rate_limits_attempts' => '`attempts` >= 0']);

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // RESTRICT, not SET NULL: an FK action would rewrite audit rows without firing
            // the insert-only triggers. Users are never deleted anyway.
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('username', 40)->nullable();
            $table->string('action', 32);
            $table->string('entity_type', 64);
            $table->string('entity_id', 64)->nullable();
            $table->text('summary');
            $table->longText('metadata')->nullable();
            // Taken from a forwarded-for header when proxy headers are trusted, so not assumed to fit an IP.
            $table->text('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            Columns::createdAt($table);

            $table->index('action', 'idx_audit_logs_action');
            $table->index('created_at', 'idx_audit_logs_created_at');
            $table->index(['entity_type', 'entity_id'], 'idx_audit_logs_entity');
        });
        C::check('audit_logs', [
            'ck_audit_logs_action' => C::in('action', AuditAction::class),
            'ck_audit_logs_summary' => 'CHAR_LENGTH(`summary`) > 0',
        ]);
        C::insertOnly('audit_logs', 'The audit log is insert-only: entries cannot be changed or removed.');

        Schema::create('business_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('business_name')->default('My Shop');
            $table->string('tagline')->nullable()->default('Bookkeeping & stock');
            $table->text('address')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('email')->nullable();
            $table->binary('logo_data')->nullable();
            $table->string('logo_mime', 32)->nullable();
            $table->integer('logo_width')->nullable();
            $table->integer('logo_height')->nullable();
            $table->dateTime('logo_updated_at', 3)->nullable();
            $table->string('currency_code', 4)->default('GHS');
            $table->string('currency_symbol', 8)->default('₵');
            $table->boolean('tax_enabled')->default(false);
            $table->bigInteger('tax_rate_bp')->default(0);
            $table->boolean('tax_inclusive')->default(false);
            $table->string('tax_label', 32)->default('VAT');
            $table->bigInteger('low_stock_threshold_milli')->default(5000);
            $table->boolean('allow_negative_stock')->default(false);
            $table->integer('expiry_warning_days')->default(30);
            $table->boolean('expiry_blocks_sales')->default(true);
            $table->boolean('allow_overpayment')->default(false);
            $table->integer('default_terms_days')->default(30);
            $table->unsignedTinyInteger('financial_year_start_month')->default(1);
            $table->date('books_locked_before')->nullable();
            $table->string('look', 16)->default('default');
            $table->string('business_type', 32)->default('general_retail');
            $table->boolean('feature_expiry_batches')->default(true);
            $table->boolean('feature_quotations')->default(false);
            $table->boolean('has_demo_data')->default(false);
            $table->dateTime('setup_completed_at', 3)->nullable();
            Columns::timestamps($table);
        });
        // A logo is capped at 1 MB; BLOB stops at 64 KB.
        DB::statement('ALTER TABLE `business_settings` MODIFY `logo_data` MEDIUMBLOB NULL');
        C::check('business_settings', [
            'ck_business_settings_singleton' => '`id` = 1',
            'ck_business_settings_tax_rate' => '`tax_rate_bp` >= 0 AND `tax_rate_bp` <= 100000',
            'ck_business_settings_fy_month' => '`financial_year_start_month` BETWEEN 1 AND 12',
            'ck_business_settings_currency' => 'CHAR_LENGTH(`currency_code`) BETWEEN 2 AND 4',
            'ck_business_settings_look' => C::in('look', Look::class),
            'ck_business_settings_business_type' => C::in('business_type', BusinessType::class),
        ] + C::booleans('tax_enabled', 'tax_inclusive', 'allow_negative_stock', 'expiry_blocks_sales', 'allow_overpayment', 'feature_expiry_batches', 'feature_quotations', 'has_demo_data'));

        Schema::create('sequences', function (Blueprint $table) {
            $table->string('doc_type', 32)->primary();
            $table->string('prefix', 16)->default('');
            $table->bigInteger('next_number')->default(1);
            $table->integer('padding')->default(5);
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
        });
        C::check('sequences', [
            'ck_sequences_next_positive' => '`next_number` >= 1',
            'ck_sequences_padding' => '`padding` BETWEEN 0 AND 12',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
        Schema::dropIfExists('business_settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('rate_limits');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('users');
    }
};
