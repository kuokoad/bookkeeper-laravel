<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The reports sum debits and credits per account over the whole ledger.
 * With the amounts in the index those sums read the index alone instead of
 * one row lookup per line: the all-time trial balance went from about
 * 1,000 ms to 250 ms at a year of trading (nuna:benchmark). Same leading
 * columns, so everything that used the old index still can.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `journal_lines` DROP INDEX `idx_journal_lines_account`, ADD INDEX `idx_journal_lines_account` (`account_id`, `entry_id`, `debit_minor`, `credit_minor`)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `journal_lines` DROP INDEX `idx_journal_lines_account`, ADD INDEX `idx_journal_lines_account` (`account_id`, `entry_id`)');
    }
};
