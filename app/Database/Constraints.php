<?php

declare(strict_types=1);

namespace App\Database;

use BackedEnum;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Database rules that Laravel's schema builder does not express: CHECK
 * constraints, functional unique indexes and the triggers MySQL needs where a
 * CHECK is not allowed.
 *
 * The rules live in the database, not only in PHP, so that a future code path,
 * a hand-written SQL fix or an import cannot store a row the old app would
 * have refused.
 */
final class Constraints
{
    /** Values that may appear inside a CHECK literal. They come from enums, never input. */
    private const SAFE_VALUE = '/^[A-Za-z0-9_]+$/';

    /** Replaced with the table name in constraint names passed to check(). */
    private const TABLE_PLACEHOLDER = '{table}';

    /**
     * Add named CHECK constraints to a table in one ALTER.
     *
     * @param  array<string, string>  $checks  constraint name => SQL expression
     */
    public static function check(string $table, array $checks): void
    {
        if ($checks === []) {
            return;
        }

        $clauses = [];
        foreach ($checks as $name => $expression) {
            $name = str_replace(self::TABLE_PLACEHOLDER, $table, $name);
            self::assertIdentifier($name);
            $clauses[] = "ADD CONSTRAINT `{$name}` CHECK ({$expression})";
        }

        DB::statement('ALTER TABLE `'.$table.'` '.implode(', ', $clauses));
    }

    /**
     * `col IN ('a','b')` built from a backed enum, so the list cannot drift
     * from the PHP type (the old app did the same from its TypeScript tuples).
     *
     * @param  class-string<BackedEnum>  $enum
     */
    public static function in(string $column, string $enum): string
    {
        $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases());

        return self::inList($column, $values);
    }

    /** @param  list<string>  $values */
    public static function inList(string $column, array $values): string
    {
        if ($values === []) {
            throw new InvalidArgumentException('A CHECK IN list needs at least one value.');
        }

        foreach ($values as $value) {
            if (preg_match(self::SAFE_VALUE, $value) !== 1) {
                throw new InvalidArgumentException("Unsafe value in a CHECK constraint: {$value}");
            }
        }

        return "`{$column}` IN ('".implode("', '", $values)."')";
    }

    /**
     * Booleans are TINYINT(1); without these the column would accept 7.
     * Merge the result into the table's check() call so it is one ALTER.
     *
     * @return array<string, string>
     */
    public static function booleans(string ...$columns): array
    {
        $checks = [];
        foreach ($columns as $column) {
            $checks['ck_'.self::TABLE_PLACEHOLDER."_{$column}_bool"] = "`{$column}` IN (0, 1)";
        }

        return $checks;
    }

    /**
     * Case-insensitive uniqueness, as the old `UNIQUE (lower(col))` indexes.
     * The collation is binary, so a plain UNIQUE would let "Rice" and "rice"
     * both in.
     *
     * MySQL will not index LOWER() of a column wider than 512 characters (it
     * types the expression as TEXT), so the lower-cased value is a stored,
     * invisible generated column `<col>_lower` with the unique index on it.
     * INVISIBLE keeps it out of SELECT *; nothing writes to it.
     */
    public static function uniqueLower(string $table, string $index, string $column): void
    {
        self::assertIdentifier($index);
        $type = DB::selectOne(
            'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        )->t;
        DB::statement(
            "ALTER TABLE `{$table}` ADD COLUMN `{$column}_lower` {$type} GENERATED ALWAYS AS (LOWER(`{$column}`)) STORED INVISIBLE, "
            ."ADD UNIQUE INDEX `{$index}` (`{$column}_lower`)"
        );
    }

    /**
     * A non-unique index on the first 191 characters of a wide text column.
     * MySQL caps a key at 3,072 bytes; a name search only needs the prefix.
     */
    public static function prefixIndex(string $table, string $index, string $column): void
    {
        self::assertIdentifier($index);
        DB::statement("CREATE INDEX `{$index}` ON `{$table}` (`{$column}`(191))");
    }

    /**
     * A row may not point at itself. MySQL refuses a CHECK that mentions an
     * AUTO_INCREMENT column, so this is a pair of triggers. On insert NEW.id
     * is 0 unless an id is given explicitly (the importer does), so one
     * condition covers both cases.
     */
    public static function noSelfReference(string $table, string $column, string $message): void
    {
        foreach (['INSERT' => 'bi', 'UPDATE' => 'bu'] as $event => $suffix) {
            $name = "trg_{$table}_{$column}_{$suffix}";
            self::assertIdentifier($name);
            DB::unprepared(
                "CREATE TRIGGER `{$name}` BEFORE {$event} ON `{$table}` FOR EACH ROW BEGIN "
                ."IF NEW.`{$column}` IS NOT NULL AND NEW.`{$column}` = NEW.`id` THEN "
                ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = ".DB::getPdo()->quote($message).'; '
                .'END IF; END'
            );
        }
    }

    /**
     * Refuse every UPDATE and DELETE on a table. Used for the audit log, which
     * the old app kept insert-only by having no code path that changes it.
     */
    public static function insertOnly(string $table, string $message): void
    {
        foreach (['UPDATE' => 'bu', 'DELETE' => 'bd'] as $event => $suffix) {
            $name = "trg_{$table}_insert_only_{$suffix}";
            DB::unprepared(
                "CREATE TRIGGER `{$name}` BEFORE {$event} ON `{$table}` FOR EACH ROW BEGIN "
                ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = ".DB::getPdo()->quote($message).'; END'
            );
        }
    }

    private static function assertIdentifier(string $name): void
    {
        if (preg_match('/^[a-z0-9_]{1,64}$/', $name) !== 1) {
            throw new InvalidArgumentException("Not a usable constraint name: {$name}");
        }
    }
}
