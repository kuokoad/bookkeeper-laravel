<?php

declare(strict_types=1);

use App\Database\Constraints as C;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Goods put back (a return, a void, an edit) whose first units cancel the oversold units the
 * document itself took, at what it charged for them, before the rest meet the shelf. Kept on the
 * row so a replay of the ledger lands where the movement did (fifth audit follow-up). New here:
 * the old app never cleared a negative shelf at a different cost, so imported rows keep 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->bigInteger('cancels_qty_milli')->default(0);
            $table->bigInteger('cancels_cost_minor')->default(0);
        });
        C::check('stock_ledger', [
            'ck_stock_ledger_cancels_within_in' => '`cancels_qty_milli` = 0 OR `cancels_qty_milli` BETWEEN 1 AND `qty_in_milli`',
            'ck_stock_ledger_cancels_cost_within' => '`cancels_cost_minor` = 0 OR `cancels_cost_minor` BETWEEN 1 AND `total_cost_minor`',
        ]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `stock_ledger` DROP CHECK `ck_stock_ledger_cancels_within_in`');
        DB::statement('ALTER TABLE `stock_ledger` DROP CHECK `ck_stock_ledger_cancels_cost_within`');
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->dropColumn(['cancels_qty_milli', 'cancels_cost_minor']);
        });
    }
};
