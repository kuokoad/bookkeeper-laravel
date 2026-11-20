<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The name of the form a payment was received from, so the same form sent
 * twice (a second press after a lost reply) is one payment, as
 * sales.client_ref does for a cart. New here: the old app had no such
 * guard, so imported payments keep null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->string('client_ref', 64)->nullable()->unique('uq_customer_payments_client_ref');
        });
    }

    public function down(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropUnique('uq_customer_payments_client_ref');
            $table->dropColumn('client_ref');
        });
    }
};
