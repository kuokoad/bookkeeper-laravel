<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The cash flow sums the lines on payment accounts. Its only index was the
 * foreign key's (payment_account_id alone), so the opening balance read
 * the whole of journal_lines: one month took longer than the year. With the
 * entry and the amounts in the index it reads the index alone (see
 * nuna:benchmark). The new index serves the foreign key, so the old one
 * goes: MySQL drops it by itself when it made it, but a database loaded from
 * a mysqldump has it as an ordinary KEY, so it is dropped here by name.
 */
return new class extends Migration
{
    private const FK_INDEX = 'journal_lines_payment_account_id_foreign';

    private const COVER = 'idx_journal_lines_payment_account';

    private function has(string $index): bool
    {
        return DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', ['journal_lines', $index])->n > 0;
    }

    public function up(): void
    {
        $dropOld = $this->has(self::FK_INDEX) ? ', DROP INDEX `'.self::FK_INDEX.'`' : '';
        DB::statement('ALTER TABLE `journal_lines` ADD INDEX `'.self::COVER.'` (`payment_account_id`, `entry_id`, `debit_minor`, `credit_minor`)'.$dropOld);
    }

    public function down(): void
    {
        // One statement, so the foreign key always has an index.
        $changes = array_filter([
            $this->has(self::FK_INDEX) ? null : 'ADD INDEX `'.self::FK_INDEX.'` (`payment_account_id`)',
            $this->has(self::COVER) ? 'DROP INDEX `'.self::COVER.'`' : null,
        ]);
        if ($changes !== []) {
            DB::statement('ALTER TABLE `journal_lines` '.implode(', ', $changes));
        }
    }
};
