<?php

declare(strict_types=1);

use App\Database\Constraints as C;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The change handed back at the till, so the receipt can print it. Only what
 * was applied to the total is stored as tender (sale_payments), so the sale
 * could not say what the customer handed over. New here: the old app did not
 * keep it, so imported sales, voids and returns keep 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->bigInteger('change_minor')->default(0);
        });
        C::check('sales', ['ck_sales_change_not_negative' => '`change_minor` >= 0']);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `sales` DROP CHECK `ck_sales_change_not_negative`');
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('change_minor');
        });
    }
};
