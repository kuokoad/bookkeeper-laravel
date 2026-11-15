<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Database\Tx;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Copies a copy of the old SQLite database into an empty MySQL schema,
 * unchanged: same ids, same amounts, same text. Only the storage changes:
 * millisecond timestamps become UTC DATETIME(3).
 *
 * All or nothing. Some links point forward (a sale's voided_by_sale_id names a
 * later row), so foreign keys are checked by this importer after the load
 * instead of row by row; every CHECK and trigger still applies to every row.
 * Any row MySQL refuses, and any dangling link, is listed and nothing is
 * committed.
 */
final class SqliteImporter
{
    /** @var list<string> */
    private array $problems = [];

    /** @var array<string, int> */
    private array $counts = [];

    /**
     * @return array<string, int> rows imported per table
     *
     * @throws ImportRefused with every problem found
     */
    public function import(string $path): array
    {
        $old = OldDatabase::open($path);
        try {
            return $this->copyAll($old);
        } finally {
            unset($old);
            OldDatabase::tidy($path);
        }
    }

    /** @return array<string, int> */
    private function copyAll(PDO $old): array
    {
        $this->assertTargetEmpty();

        $tables = OldDatabase::tables($old);
        $unknown = array_diff($tables, $this->targetTables());
        if ($unknown !== []) {
            throw new ImportRefused(['The old database has tables this schema does not: '.implode(', ', $unknown)]);
        }

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            Tx::run(function () use ($old, $tables): void {
                // A retry after a deadlock starts again from nothing.
                $this->problems = [];
                $this->counts = [];
                // The migration seeds 'pcs'; the old database has its own row for it.
                DB::table('units')->delete();
                foreach ($tables as $table) {
                    $this->copyTable($old, $table);
                }
                $this->checkForeignKeys();

                if ($this->problems !== []) {
                    throw new ImportRefused($this->problems);
                }
            });
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $this->counts;
    }

    private function assertTargetEmpty(): void
    {
        $occupied = [];
        foreach ($this->targetTables() as $table) {
            $query = DB::table($table);
            if ($table === 'units') {
                $query->where('symbol', '<>', 'pcs');
            }
            if ($query->exists()) {
                $occupied[] = $table;
            }
        }
        if ($occupied !== []) {
            throw new ImportRefused(['The target database is not empty ('.implode(', ', $occupied).'). Import into a freshly migrated database, without the seed: php artisan migrate:fresh']);
        }
    }

    private function copyTable(PDO $old, string $table): void
    {
        $columns = $this->targetColumns($table);
        $rows = $old->query('SELECT * FROM "'.$table.'"');
        $count = 0;

        foreach ($rows as $row) {
            $extra = array_diff(array_keys($row), array_keys($columns));
            if ($extra !== []) {
                $this->problems[] = "{$table}: old columns with no place in the new schema: ".implode(', ', $extra);

                return;
            }

            $values = [];
            foreach ($row as $column => $value) {
                $values[$column] = $columns[$column] === 'datetime' && $value !== null
                    ? $this->fromMilliseconds($table, $column, $value)
                    : $value;
            }

            try {
                DB::table($table)->insert($values);
                $count++;
            } catch (QueryException $e) {
                $id = $row['id'] ?? $row['symbol'] ?? $row['doc_type'] ?? $row['key'] ?? '?';
                $this->problems[] = "{$table} #{$id}: ".($e->getPrevious()?->getMessage() ?? $e->getMessage());
            } catch (RuntimeException $e) {
                $this->problems[] = "{$table} #".($row['id'] ?? '?').': '.$e->getMessage();
            }
        }

        $this->counts[$table] = $count;
    }

    /** Integer milliseconds since the epoch, as the old app's timestampMs columns hold them. */
    private function fromMilliseconds(string $table, string $column, mixed $value): string
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new RuntimeException("{$column} is not a millisecond timestamp: ".var_export($value, true));
        }
        $ms = (int) $value;
        // 2000-01-01 to 2100-01-01: anything else is not a moment this shop traded.
        if ($ms < 946_684_800_000 || $ms > 4_102_444_800_000) {
            throw new RuntimeException("{$column} = {$ms} is outside 2000 to 2100, so it is not a millisecond timestamp.");
        }

        return gmdate('Y-m-d H:i:s', intdiv($ms, 1000)).'.'.str_pad((string) ($ms % 1000), 3, '0', STR_PAD_LEFT);
    }

    /** Every foreign key, checked by query: no child row may point at a missing parent. */
    private function checkForeignKeys(): void
    {
        $keys = DB::select('SELECT TABLE_NAME AS t, COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS rt, REFERENCED_COLUMN_NAME AS rc
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL');

        foreach ($keys as $k) {
            $orphans = DB::select("SELECT child.`{$k->c}` AS v FROM `{$k->t}` AS child
                LEFT JOIN `{$k->rt}` AS parent ON parent.`{$k->rc}` = child.`{$k->c}`
                WHERE child.`{$k->c}` IS NOT NULL AND parent.`{$k->rc}` IS NULL LIMIT 5");
            if ($orphans !== []) {
                $values = implode(', ', array_map(fn (object $o): string => (string) $o->v, $orphans));
                $this->problems[] = "{$k->t}.{$k->c} points at {$k->rt} rows that do not exist: {$values}";
            }
        }
    }

    /** @return list<string> */
    private function targetTables(): array
    {
        $laravel = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions'];

        return array_values(array_diff(
            array_map(fn (object $r): string => $r->t, DB::select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')),
            $laravel,
        ));
    }

    /** @return array<string, string> writable column => data type (generated columns left out) */
    private function targetColumns(string $table): array
    {
        $rows = DB::select("SELECT COLUMN_NAME AS c, DATA_TYPE AS d FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              AND EXTRA NOT LIKE '%VIRTUAL GENERATED%' AND EXTRA NOT LIKE '%STORED GENERATED%'", [$table]);

        $columns = [];
        foreach ($rows as $r) {
            $columns[$r->c] = $r->d;
        }

        return $columns;
    }
}
