<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision D34: an owner may edit a saved document. The edit reverses
 * the old stock movements and journal entry and posts the new ones under the
 * same number; edited_at marks the document "Edited", and what it was before
 * each edit is kept in the audit log. New here: the old app had no edit, so
 * imported documents keep NULL.
 */
return new class extends Migration
{
    private const TABLES = ['stock_adjustments', 'expenses', 'incomes', 'purchases', 'sales'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dateTime('edited_at', 3)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('edited_at');
            });
        }
    }
};
