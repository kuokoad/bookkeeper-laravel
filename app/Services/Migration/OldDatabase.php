<?php

declare(strict_types=1);

namespace App\Services\Migration;

use PDO;
use RuntimeException;

/**
 * A read-only connection to a COPY of the old app's SQLite database. Opened
 * with SQLITE_OPEN_READONLY, so nothing here can write to it, and inside one
 * read transaction, so every table comes from the same moment.
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
        if (self::isLiveDatabase($path)) {
            throw new RuntimeException("{$path} is the old app's live database. Stop the old app, copy bookkeeper.db somewhere else and import the copy.");
        }
        // A -wal beside it may hold saved rows the main file does not have yet; a -shm means it is open.
        if (is_file($path.'-wal') || is_file($path.'-shm')) {
            throw new RuntimeException("{$path} has a -wal or -shm file beside it, so it may be in use or missing saved rows. Stop the old app, then copy bookkeeper.db while the old app is stopped, and import that copy.");
        }

        $pdo = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);

        // Deferred: the snapshot starts at the first read below and holds until the connection closes.
        $pdo->exec('BEGIN');
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'journal_entries'")->fetchColumn();
        if ($tables === false) {
            throw new RuntimeException("{$path} is not a NunaBooks database: it has no journal_entries table.");
        }

        return $pdo;
    }

    private static function isLiveDatabase(string $path): bool
    {
        $live = realpath((string) config('nuna.old_live_database'));
        $given = realpath($path);
        if ($live === false || $given === false) {
            return false;
        }

        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($live, $given) === 0 : $live === $given;
    }

    /** @return list<string> */
    public static function tables(PDO $old): array
    {
        $names = $old->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_diff($names, self::SKIPPED_TABLES));
    }
}
