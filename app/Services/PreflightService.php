<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Money;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The checks to pass before a real shop trusts this with its money: a port
 * of the old db/preflight.ts for this install. Read by a developer at a
 * terminal (nuna:preflight) and by the owner on the Health screen (Phase 9),
 * so money is written as money. `fail` blocks going live; `warn` is the
 * owner's judgement call. Reads only.
 */
final class PreflightService
{
    /** The inputs in vite.config.js: what @vite() asks the manifest for. */
    public const ENTRY_POINTS = ['resources/css/app.css', 'resources/js/app.js'];

    /** @return list<array{name: string, status: 'pass'|'warn'|'fail', detail: string}> */
    public static function run(): array
    {
        $checks = [];
        $add = function (string $name, string $status, string $detail) use (&$checks): void {
            $checks[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
        };

        // --- configuration ----------------------------------------------------

        $env = (string) config('app.env');
        if ($env !== 'production') {
            $add('Running in production mode', 'warn', "APP_ENV is \"{$env}\". Set APP_ENV=production before real trading, or error details meant for a developer may reach the screen.");
        } elseif (config('app.debug') === true) {
            $add('Running in production mode', 'warn', 'APP_DEBUG is true. Set it to false, or error details meant for a developer may reach the screen.');
        } else {
            $add('Running in production mode', 'pass', 'APP_ENV=production, APP_DEBUG=false');
        }

        $key = (string) config('app.key');
        $add('App key is real', $key === '' ? 'fail' : 'pass', $key === '' ? 'APP_KEY is empty. Run `php artisan key:generate`.' : 'APP_KEY is set');

        $add(
            'Demo seeding is off',
            config('nuna.seed_demo_data') === true ? 'fail' : 'pass',
            config('nuna.seed_demo_data') === true ? 'SEED_DEMO_DATA is true. Set it to false so demo records can never be written to the real books.' : 'SEED_DEMO_DATA=false',
        );

        $secure = (bool) config('session.secure');
        $add('Cookies match how it is served', $secure ? 'pass' : 'warn', $secure
            ? 'SESSION_SECURE_COOKIE=true: the session cookie is only sent over HTTPS.'
            : "SESSION_SECURE_COOKIE=false. Correct for plain HTTP on the shop's own network; set it to true if you put the app behind HTTPS.");

        $trust = config('nuna.trust_proxy_headers') === true;
        $add('Forwarded headers are not blindly trusted', $trust ? 'warn' : 'pass', $trust
            ? 'NUNA_TRUST_PROXY_HEADERS=true. Only correct if a reverse proxy in front of this app OVERWRITES X-Forwarded-For. If callers can set it themselves, the sign-in rate limit can be sidestepped.'
            : 'NUNA_TRUST_PROXY_HEADERS=false: sign-in attempts are counted against one shared bucket rather than a header the caller controls.');

        $missing = self::missingAssets();
        $add('Screen styles and scripts are built', $missing === [] ? 'pass' : 'fail', $missing === []
            ? 'public/build/manifest.json lists '.implode(' and ', self::ENTRY_POINTS).', and every file it names is there'
            : 'Missing from public/build: '.implode(', ', $missing).'. Every screen would load unstyled. Run `npm run build` on the dev PC; the built files ship with each release.');

        $dump = BackupService::binary('mysqldump');
        $found = is_file($dump) || (str_ends_with($dump, 'mysqldump') && self::onPath('mysqldump'));
        $add('Backups can be taken', $found ? 'pass' : 'fail', $found ? $dump : 'mysqldump was not found. Set NUNA_MYSQL_BIN to the folder holding mysqldump.exe and mysql.exe.');

        // --- the database itself ---------------------------------------------

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $add('Database exists', 'fail', 'Could not connect to the database: '.$e->getMessage());

            return $checks;
        }
        $add('Database exists', 'pass', (string) DB::getDatabaseName());

        $binlog = DB::selectOne('SELECT @@log_bin AS b, @@log_bin_trust_function_creators AS t');
        $blocked = (int) $binlog->b === 1 && (int) $binlog->t === 0;
        $add('Migrations can create triggers', $blocked ? 'warn' : 'pass', $blocked
            ? 'The MySQL binary log is on and log_bin_trust_function_creators is off, so the app user may be refused CREATE TRIGGER by a migration or a restore. Set log_bin_trust_function_creators=1 in my.ini.'
            : 'the binary log does not stand in the way of CREATE TRIGGER');

        // A crash mid-backup leaves its scratch copy behind: harmless, but a whole second set of books on disk.
        $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], substr((string) DB::getDatabaseName(), 0, 40)).'\\_scratch\\_%';
        $leftovers = array_map(fn (object $r): string => (string) $r->n, DB::select('SELECT SCHEMA_NAME AS n FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ? ORDER BY SCHEMA_NAME', [$pattern]));
        $add('No scratch databases left over', $leftovers === [] ? 'pass' : 'warn', $leftovers === []
            ? 'no backup scratch copies remain'
            : count($leftovers).' left over from an interrupted backup or restore: '.implode(', ', $leftovers).'. Drop them once no backup is running.');

        $pending = self::pendingMigrations();
        if ($pending === null) {
            $add('Migrations applied', 'fail', 'No migration history. Run `php artisan migrate`.');

            return $checks;
        }
        $add('Migrations applied', $pending === [] ? 'pass' : 'fail', $pending === []
            ? count(app('migrator')->getRepository()->getRan()).' migration(s) recorded'
            : count($pending).' migration(s) not applied. Run `php artisan migrate --force`.');
        if ($pending !== []) {
            return $checks;
        }

        $orphans = self::brokenReferences();
        $add('No broken references', $orphans === [] ? 'pass' : 'fail', $orphans === [] ? 'every foreign key points at a row that exists' : implode('; ', $orphans));

        $totals = DB::table('journal_lines')->selectRaw('COALESCE(SUM(journal_lines.debit_minor), 0) AS d, COALESCE(SUM(journal_lines.credit_minor), 0) AS c')->first();
        [$debits, $credits] = [(int) $totals->d, (int) $totals->c];
        $add('The books balance', $debits === $credits ? 'pass' : 'fail', $debits === $credits
            ? 'debits and credits both '.Money::toDecimalString($debits)
            : 'debits '.Money::toDecimalString($debits).' vs credits '.Money::toDecimalString($credits).' — a difference of '.Money::toDecimalString(abs($debits - $credits)).'. Do NOT trade on these books.');

        // The full replay lives here, where somebody asked for a thorough answer and can wait.
        $stock = InventoryService::verifyAllStock();
        $drifted = array_filter($stock, fn (array $p): bool => ! $p['ok']);
        $movements = DB::table('stock_ledger')->count();
        $add('Stock matches its movement history', $drifted === [] ? 'pass' : 'fail', $drifted === []
            ? count($stock)." product(s) replayed from {$movements} movement(s)"
            : count($drifted).' product(s) disagree with their own ledger: '.implode(', ', array_column($drifted, 'productName')).'. Do NOT trust the stock valuation.');

        $coverage = InventoryService::verifyBatchCoverage();
        $uncovered = array_filter($coverage, fn (array $p): bool => ! $p['ok']);
        $add('Every unit of stock belongs to a batch', $uncovered === [] ? 'pass' : 'fail', $uncovered === []
            ? count($coverage).' product(s) fully covered by their batches'
            : count($uncovered).' product(s) hold stock no batch owns: '.implode(', ', array_column($uncovered, 'productName')).'. Expiry warnings may be missing.');

        $demoRows = 0;
        foreach (['products', 'sales', 'purchases', 'customers', 'suppliers'] as $table) {
            $demoRows += DB::table($table)->where("{$table}.is_demo", true)->count();
        }
        $add('No demo records in the books', $demoRows === 0 ? 'pass' : 'fail', $demoRows === 0
            ? 'nothing tagged as demo'
            : "{$demoRows} demo record(s) present. Start from a clean database for a real shop.");

        $owners = DB::table('users')->where('role', 'OWNER')->where('is_active', true)->count();
        $add('An owner can sign in', $owners > 0 ? 'pass' : 'fail', $owners > 0 ? "{$owners} active owner(s)" : 'No active owner. Nobody could manage the shop.');

        // The username alone proves nothing; has_demo_data (set by the demo seeder) settles it.
        $seededDemo = (bool) DB::table('business_settings')->where('id', 1)->value('has_demo_data');
        $demoUsers = DB::table('users')->whereIn('username', ['owner', 'ama'])->count();
        if ($seededDemo && $demoUsers > 0) {
            $add('No demo accounts', 'fail', "{$demoUsers} seeded demo account(s) can still sign in, and their passwords are published in the README.");
        } elseif ($demoUsers > 0) {
            $add('No demo accounts', 'warn', "{$demoUsers} account(s) are named \"owner\" or \"ama\". This database was not demo-seeded, so these look like your own — but those names appear with passwords in the README, so make sure the passwords differ.");
        } elseif ($seededDemo) {
            $add('No demo accounts', 'warn', 'This database was seeded with demo data at some point. The demo sign-ins are gone, but check the records too.');
        } else {
            $add('No demo accounts', 'pass', 'never demo-seeded, and no published demo usernames');
        }

        $backup = BackupStatusService::get(Clock::now());
        $add('A backup has been taken', $backup['state'] === 'never' ? 'warn' : 'pass', BackupStatusService::describe($backup));

        return $checks;
    }

    /**
     * The migration files not yet run, or null when there is no migration
     * history at all. Also read by the error page (bootstrap/app.php).
     *
     * @return list<string>|null
     */
    public static function pendingMigrations(): ?array
    {
        if (! Schema::hasTable('migrations')) {
            return null;
        }
        $migrator = app('migrator');

        return array_values(array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan()));
    }

    /**
     * Each entry point the manifest lacks, then each file it names that is not on disk.
     *
     * @return list<string>
     */
    public static function missingAssets(): array
    {
        $build = public_path('build');
        $manifest = is_file($build.'/manifest.json') ? json_decode((string) file_get_contents($build.'/manifest.json'), true) : null;
        if (! is_array($manifest)) {
            return ['manifest.json'];
        }

        $missing = array_values(array_diff(self::ENTRY_POINTS, array_keys($manifest)));
        foreach ($manifest as $chunk) {
            foreach ([$chunk['file'] ?? null, ...($chunk['css'] ?? [])] as $file) {
                if (! is_string($file) || ! is_file($build.'/'.$file)) {
                    $missing[] = (string) $file;
                }
            }
        }

        return $missing;
    }

    /** @return list<string> */
    private static function brokenReferences(): array
    {
        $problems = [];
        $keys = DB::select('SELECT TABLE_NAME AS t, COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS rt, REFERENCED_COLUMN_NAME AS rc
            FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL');
        foreach ($keys as $k) {
            $n = (int) DB::selectOne("SELECT COUNT(*) AS n FROM `{$k->t}` AS child LEFT JOIN `{$k->rt}` AS parent ON parent.`{$k->rc}` = child.`{$k->c}`
                WHERE child.`{$k->c}` IS NOT NULL AND parent.`{$k->rc}` IS NULL")->n;
            if ($n > 0) {
                $problems[] = "{$n} {$k->t}.{$k->c} row(s) point at missing {$k->rt}";
            }
        }

        return $problems;
    }

    private static function onPath(string $tool): bool
    {
        $finder = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';

        return Process::run([$finder, $tool])->successful();
    }
}
