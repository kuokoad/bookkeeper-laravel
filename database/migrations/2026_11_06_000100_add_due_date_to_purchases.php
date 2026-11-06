<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision D8: a purchase may carry the day the supplier wants paying,
 * and Payables ages from it (falling back to the purchase date), as
 * Receivables does with sales.due_date. New here: the old app had no such
 * column, so imported purchases keep NULL. Mirrors sales.due_date: a
 * nullable DATE with its own index and no CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('invoice_no');
            $table->index('due_date', 'idx_purchases_due_date');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex('idx_purchases_due_date');
            $table->dropColumn('due_date');
        });
    }
};
