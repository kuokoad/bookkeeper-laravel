<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ValidationError;
use App\Domain\Money;
use App\Enums\AuditAction;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\LedgerService;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Verified backups and guarded restores of the MySQL database (PLAN 6.2),
 * replacing the old db/backup.ts and db/restore.ts, which copied one SQLite
 * file.
 *
 * A backup is `mysqldump --single-transaction` (a consistent snapshot while
 * the shop trades), gzipped. It counts only once it has been restored into a
 * scratch database and found sound there: every entry and the trial balance
 * balance, receivables and payables equal their control accounts, every
 * product's stock replays to its cached figures and the stock ledger equals
 * GL 1200, every unit belongs to a batch, an owner can sign in; and its
 * triggers, insert-only row counts and trial balance match the live books.
 * A copy that fails is deleted and the backup fails loudly. Old copies are
 * trimmed only after a verified one exists.
 *
 * Scratch databases are named `<database>_scratch_<random>` and dropped
 * afterwards; the app's MySQL user needs rights on that pattern (the
 * installer grants them).
 */
final class BackupService
{
    public const FILE_PREFIX = 'nunabooks-';

    public const FILE_SUFFIX = '.sql.gz';

    /** Tables whose rows are never deleted, so the copy's highest id fixes how many the books held at the snapshot. */
    private const INSERT_ONLY_TABLES = ['audit_logs', 'journal_entries', 'journal_lines'];

    private const MISMATCH = 'the copy does not match the books it was taken from: ';

    /**
     * @return array{path: string, bytes: int, entries: int, removed: list<string>}
     */
    public static function createBackup(?string $directory = null, ?int $keep = null, ?Carbon $now = null, string $label = ''): array
    {
        $keep ??= (int) config('nuna.backup.keep', 14);
        $directory ??= (string) config('nuna.backup.dir');
        if ($keep < 1) {
            throw new ValidationError('--keep must be at least 1: a backup command that keeps nothing is a delete command.');
        }
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the backup folder {$directory}.");
        }

        $target = self::targetPath($directory, $now, $label);
        $source = self::connectionConfig();
        // A mismatch can be a sale committing across the snapshot edge; a second dump settles it.
        for ($attempt = 1; ; $attempt++) {
            try {
                self::dump($source, $target);
                $entries = self::withScratchCopy($target, function () use ($source): int {
                    $entries = self::assertSound();
                    self::assertMatches($source['database']);

                    return $entries;
                });
                break;
            } catch (Throwable $e) {
                // A file that failed must not sit in the folder looking like a safety net.
                if (file_exists($target)) {
                    @unlink($target);
                }
                if ($attempt === 1 && str_contains($e->getMessage(), self::MISMATCH)) {
                    continue;
                }
                throw new RuntimeException('Backup verification FAILED and the file was discarded: '.$e->getMessage(), 0, $e);
            }
        }

        // Trim only now, so a failure never leaves fewer backups than there were.
        $all = self::existingBackups($directory);
        $removed = [];
        foreach (array_slice($all, 0, max(0, count($all) - $keep)) as $name) {
            unlink($directory.DIRECTORY_SEPARATOR.$name);
            $removed[] = $name;
        }

        return ['path' => $target, 'bytes' => (int) filesize($target), 'entries' => $entries, 'removed' => $removed];
    }

    /**
     * The audit row the backup status reads. A failure to write it must not
     * fail the backup: the verified file is what matters.
     */
    public static function recordBackupTaken(int $entries, ?Actor $actor = null, string $how = 'from the command line'): void
    {
        try {
            Tx::run(fn () => AuditService::write(AuditAction::Create, 'backup', "Backup taken {$how} ({$entries} journal entries, verified)", null, $actor));
        } catch (Throwable) {
            // Reported by the caller's output; the backup itself stands.
        }
    }

    /**
     * Restores the live database from a backup file. The file is verified in
     * a scratch database before anything is touched; a verified safety
     * backup of the live books is taken next; only then is the live database
     * emptied and loaded, and checked again.
     *
     * @return array{entries: int, safetyBackup: ?string}
     */
    public static function restore(string $file, ?string $safetyDirectory = null): array
    {
        if (! is_file($file)) {
            throw new RuntimeException("There is no backup at {$file}.");
        }

        try {
            [$entries, $triggers] = self::withScratchCopy($file, fn (): array => [self::assertSound(), self::triggers((string) DB::getDatabaseName())]);
        } catch (Throwable $e) {
            throw new RuntimeException('That backup cannot be used, so nothing was changed: '.$e->getMessage(), 0, $e);
        }

        // Restoring is done in a hurry; restoring the wrong file must not lose the shop.
        // An empty database (a new PC after a disk failure) has nothing to keep.
        // Not verified against the live books: those may be the broken thing being replaced.
        $live = self::connectionConfig();
        $hasTables = DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->n > 0;
        $safety = null;
        if ($hasTables) {
            $safetyDirectory ??= (string) config('nuna.backup.dir');
            if (! is_dir($safetyDirectory) && ! mkdir($safetyDirectory, 0775, true) && ! is_dir($safetyDirectory)) {
                throw new RuntimeException("Could not create the backup folder {$safetyDirectory}.");
            }
            $safety = self::targetPath($safetyDirectory, null, '-before-restore');
            self::dump($live, $safety);
        }

        $database = $live['database'];
        DB::statement("DROP DATABASE `{$database}`");
        DB::statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin");
        DB::purge();
        try {
            self::load($live, $file, $database);
            DB::purge();
            self::assertSound();
            if (self::triggers($database) !== $triggers) {
                throw new RuntimeException('the triggers that guard the books did not load as they are in the backup');
            }
        } catch (Throwable $e) {
            throw new RuntimeException('The restore did not complete: '.$e->getMessage().'. '.($safety === null ? 'The database was empty before.' : "The books as they were before are in {$safety}; restore that file."), 0, $e);
        }

        return ['entries' => $entries, 'safetyBackup' => $safety];
    }

    /** Oldest first: the names sort by time as plain text. */
    public static function existingBackups(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }
        $names = array_values(array_filter(scandir($directory) ?: [], fn (string $n): bool => str_starts_with($n, self::FILE_PREFIX) && str_ends_with($n, self::FILE_SUFFIX)));
        sort($names, SORT_STRING);

        return $names;
    }

    /** mysqldump.exe or mysql.exe: NUNA_MYSQL_BIN, else the newest Laragon MySQL, else the PATH. */
    public static function binary(string $tool): string
    {
        $exe = PHP_OS_FAMILY === 'Windows' ? "{$tool}.exe" : $tool;
        $configured = config('nuna.mysql_bin');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '\\/').DIRECTORY_SEPARATOR.$exe;
        }
        $found = glob('C:/laragon/bin/mysql/*/bin/'.$exe) ?: [];
        natsort($found);

        return $found === [] ? $tool : (string) end($found);
    }

    // --- the parts ----------------------------------------------------------

    private static function targetPath(string $directory, ?Carbon $now, string $label): string
    {
        $stamp = ($now ?? Clock::now())->copy()->setTimezone((string) config('nuna.business_timezone'))->format('Y-m-d\TH-i-s');
        $target = $directory.DIRECTORY_SEPARATOR.self::FILE_PREFIX.$stamp.$label.self::FILE_SUFFIX;
        if (file_exists($target)) {
            throw new RuntimeException("A backup already exists at {$target}. Refusing to overwrite it.");
        }

        return $target;
    }

    /** @return list<string> every trigger of a schema, as name, event, table and body */
    private static function triggers(string $schema): array
    {
        return array_map(fn (object $r): string => "{$r->n}|{$r->e}|{$r->t}|{$r->a}", DB::select(
            'SELECT TRIGGER_NAME AS n, EVENT_MANIPULATION AS e, EVENT_OBJECT_TABLE AS t, ACTION_STATEMENT AS a
             FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME',
            [$schema],
        ));
    }

    private static function dump(array $c, string $target): void
    {
        $sql = $target.'.part';
        try {
            self::withOptionsFile($c, true, function (string $options) use ($c, $sql): void {
                $result = Process::timeout(3600)->run([
                    self::binary('mysqldump'), "--defaults-extra-file={$options}",
                    '--single-transaction', '--quick', '--triggers', '--hex-blob', '--no-tablespaces',
                    '--set-gtid-purged=OFF', '--default-character-set=utf8mb4', "--result-file={$sql}", $c['database'],
                ]);
                if (! $result->successful()) {
                    throw new RuntimeException('mysqldump failed: '.trim($result->errorOutput()));
                }
            });
        } catch (Throwable $e) {
            @unlink($sql);
            throw $e;
        }

        // Gzip, and drop each trigger's DEFINER so the copy loads under whichever user restores it.
        $in = fopen($sql, 'rb');
        $out = gzopen($target, 'wb6');
        try {
            while (($line = fgets($in)) !== false) {
                if (str_starts_with($line, '/*!50003 CREATE*/ /*!50017 DEFINER=')) {
                    $line = (string) preg_replace('/^\/\*!50003 CREATE\*\/ \/\*!50017 DEFINER=`[^`]*`@`[^`]*`\*\//', '/*!50003 CREATE*/', $line);
                }
                gzwrite($out, $line);
            }
        } finally {
            fclose($in);
            gzclose($out);
            unlink($sql);
        }
    }

    /** Pipes the gzipped dump into the mysql client. */
    private static function load(array $c, string $file, string $database): void
    {
        self::withOptionsFile($c, false, function (string $options) use ($file, $database): void {
            $input = fopen('compress.zlib://'.$file, 'rb');
            try {
                $result = Process::timeout(3600)->input($input)->run([self::binary('mysql'), "--defaults-extra-file={$options}", '--default-character-set=utf8mb4', $database]);
            } finally {
                fclose($input);
            }
            if (! $result->successful()) {
                throw new RuntimeException('the mysql client could not load the backup: '.trim($result->errorOutput()));
            }
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $check  run with the default connection pointed at the copy
     * @return T
     */
    private static function withScratchCopy(string $file, callable $check): mixed
    {
        $c = self::connectionConfig();
        $scratch = substr($c['database'], 0, 40).'_scratch_'.bin2hex(random_bytes(4));
        DB::statement("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin");
        $previous = DB::getDefaultConnection();
        try {
            self::load($c, $file, $scratch);
            config(['database.connections.nuna_scratch' => array_merge(config("database.connections.{$previous}"), ['database' => $scratch])]);
            DB::purge('nuna_scratch');
            DB::setDefaultConnection('nuna_scratch');

            return $check();
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge('nuna_scratch');
            DB::statement("DROP DATABASE IF EXISTS `{$scratch}`");
        }
    }

    /**
     * The books in the current default connection are usable. Returns the
     * number of journal entries.
     */
    private static function assertSound(): int
    {
        $problems = [];
        $books = LedgerService::checkBooksIntegrity();
        if (! $books['trialBalanced']) {
            $problems[] = 'the books do not balance: debits '.Money::toDecimalString($books['totalDebit']).' vs credits '.Money::toDecimalString($books['totalCredit']);
        }
        if ($books['unbalancedEntries'] !== []) {
            $problems[] = count($books['unbalancedEntries']).' journal entr'.(count($books['unbalancedEntries']) === 1 ? 'y does' : 'ies do').' not balance';
        }
        if (! $books['receivablesMatch']) {
            $problems[] = 'receivables do not equal their control account';
        }
        if (! $books['payablesMatch']) {
            $problems[] = 'payables do not equal their control account';
        }
        $drifted = array_filter(InventoryService::verifyAllStock(), fn (array $p): bool => ! $p['ok']);
        if ($drifted !== []) {
            $problems[] = count($drifted).' product(s) disagree with their stock history';
        }
        $ledgerValue = InventoryService::getInventoryValueFromLedger();
        $glValue = BalancesService::getAccountBalanceByCode(ChartOfAccounts::INVENTORY);
        if ($ledgerValue !== $glValue) {
            $problems[] = 'the stock ledger ('.Money::toDecimalString($ledgerValue).') does not equal GL 1200 ('.Money::toDecimalString($glValue).')';
        }
        $uncovered = array_filter(InventoryService::verifyBatchCoverage(), fn (array $p): bool => ! $p['ok']);
        if ($uncovered !== []) {
            $problems[] = count($uncovered).' product(s) hold stock no batch owns';
        }
        if (! DB::table('users')->where('role', 'OWNER')->where('is_active', true)->exists()) {
            $problems[] = 'no active owner could sign in';
        }
        if ($problems !== []) {
            throw new RuntimeException(implode('; ', $problems));
        }

        return DB::table('journal_entries')->count();
    }

    /**
     * The copy (the default connection) holds what the live database held
     * when the dump began: the same triggers; for the tables nothing ever
     * deletes from, as many rows up to the copy's highest id; and per account
     * the same totals over the entries the copy holds. Other tables lose rows
     * while the shop trades (a quotation line removed, a product deleted), so
     * their counts would read as a false mismatch and are not compared.
     */
    private static function assertMatches(string $liveDatabase): void
    {
        $problems = [];
        if (self::triggers((string) DB::getDatabaseName()) !== self::triggers($liveDatabase)) {
            $problems[] = 'the triggers differ';
        }
        foreach (self::INSERT_ONLY_TABLES as $table) {
            $maxId = (int) DB::table($table)->max('id');
            $inCopy = DB::table($table)->count();
            $inLive = DB::table(DB::raw("`{$liveDatabase}`.`{$table}`"))->where('id', '<=', $maxId)->count();
            if ($inCopy !== $inLive) {
                $problems[] = "{$table}: {$inCopy} row(s) in the copy, {$inLive} in the books";
            }
        }

        $maxEntry = (int) DB::table('journal_entries')->max('id');
        $sums = fn (string $schema): array => array_map(fn (object $r): string => "{$r->code}:{$r->d}:{$r->c}", DB::select(
            "SELECT a.code, SUM(l.debit_minor) AS d, SUM(l.credit_minor) AS c
             FROM `{$schema}`.journal_lines l JOIN `{$schema}`.accounts a ON a.id = l.account_id
             WHERE l.entry_id <= ? GROUP BY a.code ORDER BY a.code",
            [$maxEntry],
        ));
        if ($sums((string) DB::getDatabaseName()) !== $sums($liveDatabase)) {
            $problems[] = 'the trial balance of the copy differs from the books';
        }
        if ($problems !== []) {
            throw new RuntimeException(self::MISMATCH.implode('; ', $problems));
        }
    }

    /** @return array{host: string, port: string, database: string, username: string, password: string} */
    private static function connectionConfig(): array
    {
        $c = config('database.connections.'.DB::getDefaultConnection());

        return [
            'host' => (string) $c['host'], 'port' => (string) $c['port'], 'database' => (string) $c['database'],
            'username' => (string) $c['username'], 'password' => (string) $c['password'],
        ];
    }

    /**
     * Credentials go in a throwaway option file, never on a command line
     * another user can read. The dump may use a separate dump-only MySQL user
     * (NUNA_BACKUP_DB_USERNAME); loading always uses the app's own.
     */
    private static function withOptionsFile(array $c, bool $forDump, callable $work): void
    {
        $dumpUser = $forDump ? config('nuna.backup.db_username') : null;
        $user = (string) ($dumpUser ?? $c['username']);
        $password = (string) ($dumpUser === null ? $c['password'] : config('nuna.backup.db_password'));
        $quote = fn (string $v): string => '"'.addcslashes($v, '\\"').'"';
        $path = tempnam(sys_get_temp_dir(), 'nuna-my-');
        file_put_contents($path, "[client]\nhost={$quote($c['host'])}\nport={$c['port']}\nuser={$quote($user)}\npassword={$quote($password)}\n");
        try {
            $work($path);
        } finally {
            @unlink($path);
        }
    }
}
