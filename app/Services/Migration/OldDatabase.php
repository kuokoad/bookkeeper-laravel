<?php

declare(strict_types=1);

namespace App\Services\Migration;

use PDO;
use RuntimeException;

/**
 * A read-only connection to a COPY of the old app's SQLite database. Opened
 * with SQLITE_OPEN_READONLY, so nothing here can write to it.
 */
final class OldDatabase
{
    /** Old tables that are not carried over: sessions are Laravel's now (PLAN Q7). */
    public const SKIPPED_TABLES = ['sessions', '__drizzle_migrations'];

    public static function open(string $path): PDO
    {
        if (! is_file($path)) {
            throw new RuntimeException("No database file at {$path}.");
        }

        $pdo = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'journal_entries'")->fetchColumn();
        if ($tables === false) {
            throw new RuntimeException("{$path} is not a NunaBooks database: it has no journal_entries table.");
        }

        return $pdo;
    }

    /** @return list<string> */
    public static function tables(PDO $old): array
    {
        $names = $old->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_diff($names, self::SKIPPED_TABLES));
    }
}
