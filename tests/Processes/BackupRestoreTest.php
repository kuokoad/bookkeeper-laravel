<?php

declare(strict_types=1);

/*
| nuna:backup and nuna:restore end to end through artisan, on real MySQL
| databases of their own (these commit): the old tests/db/backup.test.ts and
| restore.test.ts, and PLAN 6.2's proof that a backup restored into an empty
| database gives identical figures.
*/

use App\Services\BackupService;
use App\Services\InventoryService;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\FinancialService;
use App\Services\Reporting\LedgerService;
use App\Services\Reporting\OperationsService;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/run.php';

define('BACKUP_SOURCE', runDatabase('nunabooks_backup_source'));
define('BACKUP_TARGET', runDatabase('nunabooks_backup_target'));

function nuna(string $database, string ...$arguments): ProcessResult
{
    return Process::env(['DB_DATABASE' => $database, 'APP_ENV' => 'testing', 'SEED_DEMO_DATA' => 'true'])
        ->path(base_path())->timeout(600)->run([PHP_BINARY, 'artisan', ...$arguments]);
}

function useDatabase(string $name, string $database): void
{
    config(["database.connections.{$name}" => array_merge(config('database.connections.mysql'), ['database' => $database])]);
    DB::purge($name);
}

/** What a shop owner would compare, read through the new services on one database. */
function figures(string $connection): array
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($connection);
    try {
        $period = ['from' => '2000-01-01', 'to' => '2100-12-31'];
        $tables = array_map(fn (object $r): string => $r->t, DB::select("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME NOT IN ('sessions', 'cache', 'cache_locks', 'audit_logs') ORDER BY TABLE_NAME"));
        $checksums = [];
        foreach ($tables as $table) {
            $checksums[$table] = DB::selectOne("CHECKSUM TABLE `{$table}`")->Checksum;
        }

        return [
            'trialBalance' => BalancesService::getTrialBalance(),
            'profitAndLoss' => FinancialService::getProfitAndLoss($period),
            'balanceSheet' => FinancialService::getBalanceSheet('2100-12-31'),
            'cashFlow' => FinancialService::getCashFlow($period),
            'receivables' => LedgerService::getReceivablesAgeing('2100-12-31'),
            'payables' => LedgerService::getPayablesAgeing('2100-12-31'),
            'salesByDay' => OperationsService::getSalesByDay($period),
            'stockValuation' => OperationsService::getStockValuation(),
            'stock' => InventoryService::verifyAllStock(),
            'checksums' => $checksums,
            // Every audit row but the backup's own, which is written after the dump.
            'audit' => DB::table('audit_logs')->where('entity_type', '<>', 'backup')->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all(),
        ];
    } finally {
        DB::setDefaultConnection($previous);
    }
}

beforeEach(function () {
    foreach ([BACKUP_SOURCE, BACKUP_TARGET] as $database) {
        DB::statement("DROP DATABASE IF EXISTS `{$database}`");
        DB::statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin");
    }
    $seeded = nuna(BACKUP_SOURCE, 'migrate:fresh', '--seed', '--force');
    expect($seeded->successful())->toBeTrue($seeded->errorOutput().$seeded->output());
    useDatabase('source', BACKUP_SOURCE);
    useDatabase('target', BACKUP_TARGET);
    $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nuna-backup-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
});

afterEach(function () {
    // The databases first: a file that will not delete must not leave a whole set of books behind.
    DB::purge('source');
    DB::purge('target');
    dropRunDatabases(BACKUP_SOURCE, BACKUP_TARGET);
    foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($this->dir);
});

it('backs up, restores into an empty database, and every figure and every table is identical', function () {
    $backup = nuna(BACKUP_SOURCE, 'nuna:backup', "--dir={$this->dir}");
    expect($backup->successful())->toBeTrue($backup->errorOutput().$backup->output())
        ->and($backup->output())->toContain('written and verified');
    [$file] = BackupService::existingBackups($this->dir);

    $restore = nuna(BACKUP_TARGET, 'nuna:restore', $this->dir.DIRECTORY_SEPARATOR.$file, '--force');
    expect($restore->successful())->toBeTrue($restore->errorOutput().$restore->output())
        ->and($restore->output())->toContain('the database was empty before');

    $triggers = fn (string $schema): array => DB::select('SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME', [$schema]);
    expect($triggers(BACKUP_SOURCE))->not->toBe([])
        ->and($triggers(BACKUP_TARGET))->toEqual($triggers(BACKUP_SOURCE));

    $before = figures('source');
    $after = figures('target');
    expect($before['trialBalance']['totalDebit'])->toBeGreaterThan(0)
        ->and($before['salesByDay'])->not->toBe([])
        ->and($after)->toBe($before)
        // The backup is on record for the backup status (written after the dump, so in the source only).
        ->and(DB::connection('source')->table('audit_logs')->where('entity_type', 'backup')->where('action', 'CREATE')->count())->toBe(1);
});

it('brings an older backup up to date after restoring it, so the till can still sell', function () {
    // A backup taken before the newest update: its tables lack what today's code writes.
    $rolledBack = nuna(BACKUP_SOURCE, 'migrate:rollback', '--step=1', '--force');
    expect($rolledBack->successful())->toBeTrue($rolledBack->errorOutput().$rolledBack->output());
    expect(nuna(BACKUP_SOURCE, 'nuna:backup', "--dir={$this->dir}")->successful())->toBeTrue();
    [$file] = BackupService::existingBackups($this->dir);

    $restore = nuna(BACKUP_TARGET, 'nuna:restore', $this->dir.DIRECTORY_SEPARATOR.$file, '--force');
    expect($restore->successful())->toBeTrue($restore->errorOutput().$restore->output())
        ->and($restore->output())->toContain('brought the restored tables up to date');
    $pending = nuna(BACKUP_TARGET, 'migrate:status', '--pending');
    expect($pending->output())->not->toContain('Pending');
});

it('refuses without --force, changing nothing', function () {
    expect(nuna(BACKUP_SOURCE, 'nuna:backup', "--dir={$this->dir}")->successful())->toBeTrue();
    [$file] = BackupService::existingBackups($this->dir);
    $restore = nuna(BACKUP_TARGET, 'nuna:restore', $this->dir.DIRECTORY_SEPARATOR.$file);
    expect($restore->successful())->toBeFalse()
        ->and($restore->output())->toContain('--force')
        ->and(DB::select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [BACKUP_TARGET]))->toBe([]);
});

it('keeps a verified copy of the books it replaces, so a wrong restore is undoable', function () {
    expect(nuna(BACKUP_SOURCE, 'nuna:backup', "--dir={$this->dir}")->successful())->toBeTrue();
    [$file] = BackupService::existingBackups($this->dir);
    // The live books move on after the backup: a sale the restore will take away.
    DB::connection('source')->table('customers')->insert(['name' => 'After The Backup', 'created_at' => now(), 'updated_at' => now()]);

    $safetyDir = $this->dir.DIRECTORY_SEPARATOR.'safety';
    $restore = Process::env(['DB_DATABASE' => BACKUP_SOURCE, 'APP_ENV' => 'testing', 'NUNA_BACKUP_DIR' => $safetyDir])
        ->path(base_path())->timeout(600)->run([PHP_BINARY, 'artisan', 'nuna:restore', $this->dir.DIRECTORY_SEPARATOR.$file, '--force']);
    expect($restore->successful())->toBeTrue($restore->errorOutput().$restore->output());

    $safety = BackupService::existingBackups($safetyDir);
    expect($safety)->toHaveCount(1)
        ->and($safety[0])->toEndWith('-before-restore.sql.gz')
        ->and(DB::connection('source')->table('customers')->where('name', 'After The Backup')->exists())->toBeFalse()
        ->and(gzdecode((string) file_get_contents($safetyDir.DIRECTORY_SEPARATOR.$safety[0])))->toContain('After The Backup');
    array_map('unlink', glob($safetyDir.DIRECTORY_SEPARATOR.'*'));
    rmdir($safetyDir);
});

it('puts the live books back from the safety copy when the load fails part way (D18)', function () {
    expect(nuna(BACKUP_SOURCE, 'nuna:backup', "--dir={$this->dir}")->successful())->toBeTrue();
    [$file] = BackupService::existingBackups($this->dir);
    // A file that loads cleanly into the scratch check, then stops half way into the live database.
    $lines = explode("\n", gzdecode((string) file_get_contents($this->dir.DIRECTORY_SEPARATOR.$file)));
    $tables = array_keys(array_filter($lines, fn (string $l): bool => str_starts_with($l, '-- Table structure for table')));
    $middle = $tables[intdiv(count($tables), 2)];
    array_splice($lines, $middle, 0, [
        "SET @d18 = IF(DATABASE() = '".BACKUP_SOURCE."', 'SELECT * FROM no_such_table_d18', 'DO 0');",
        'PREPARE d18 FROM @d18;', 'EXECUTE d18;', 'DEALLOCATE PREPARE d18;',
    ]);
    $failing = $this->dir.DIRECTORY_SEPARATOR.'fails-mid-load.sql.gz';
    file_put_contents($failing, gzencode(implode("\n", $lines)));
    DB::connection('source')->table('customers')->insert(['name' => 'After The Backup', 'created_at' => now(), 'updated_at' => now()]);
    $before = figures('source');

    $safetyDir = $this->dir.DIRECTORY_SEPARATOR.'safety';
    $restore = Process::env(['DB_DATABASE' => BACKUP_SOURCE, 'APP_ENV' => 'testing', 'NUNA_BACKUP_DIR' => $safetyDir])
        ->path(base_path())->timeout(600)->run([PHP_BINARY, 'artisan', 'nuna:restore', $failing, '--force']);
    $safety = BackupService::existingBackups($safetyDir);
    DB::purge('source');

    expect($restore->successful())->toBeFalse()
        ->and($safety)->toHaveCount(1)
        ->and($restore->output())->toContain('The restore did not complete')
        ->and($restore->output())->toContain('no_such_table_d18')
        ->and($restore->output())->toContain('The live books were put back from')
        ->and($restore->output())->toContain($safety[0])
        ->and($restore->output())->toContain('nothing was lost')
        ->and(DB::connection('source')->table('customers')->where('name', 'After The Backup')->exists())->toBeTrue()
        ->and(figures('source'))->toBe($before);
    array_map('unlink', glob($safetyDir.DIRECTORY_SEPARATOR.'*'));
    rmdir($safetyDir);
});

it('loads only into the database being restored, even when the file says USE another', function () {
    expect(nuna(BACKUP_SOURCE, 'nuna:backup', "--dir={$this->dir}")->successful())->toBeTrue();
    [$file] = BackupService::existingBackups($this->dir);
    // A dump that ends by switching to other live books and writing there.
    $aimed = $this->dir.DIRECTORY_SEPARATOR.'uses-another-database.sql.gz';
    file_put_contents($aimed, gzencode(gzdecode((string) file_get_contents($this->dir.DIRECTORY_SEPARATOR.$file))
        ."\nUSE `".BACKUP_SOURCE."`;\nINSERT INTO customers (name, created_at, updated_at) VALUES ('Planted by the dump', NOW(), NOW());\n"));
    $before = figures('source');

    $restore = nuna(BACKUP_TARGET, 'nuna:restore', $aimed, '--force');
    DB::purge('source');
    expect($restore->successful())->toBeTrue($restore->errorOutput().$restore->output())
        ->and(DB::connection('source')->table('customers')->where('name', 'Planted by the dump')->exists())->toBeFalse()
        ->and(DB::connection('target')->table('customers')->where('name', 'Planted by the dump')->exists())->toBeFalse()
        ->and(figures('source'))->toBe($before);
});

it('restores over live books that are themselves broken, keeping them in an unverified safety copy', function () {
    expect(nuna(BACKUP_SOURCE, 'nuna:backup', "--dir={$this->dir}")->successful())->toBeTrue();
    [$file] = BackupService::existingBackups($this->dir);
    // The live books go wrong after the backup: one line that unbalances its entry.
    $line = DB::connection('source')->table('journal_lines')->where('credit_minor', '>', 0)->orderBy('id')->first();
    DB::connection('source')->table('journal_lines')->insert(['entry_id' => $line->entry_id, 'account_id' => $line->account_id, 'debit_minor' => 0, 'credit_minor' => 5, 'line_no' => 999]);

    $safetyDir = $this->dir.DIRECTORY_SEPARATOR.'safety';
    $restore = Process::env(['DB_DATABASE' => BACKUP_SOURCE, 'APP_ENV' => 'testing', 'NUNA_BACKUP_DIR' => $safetyDir])
        ->path(base_path())->timeout(600)->run([PHP_BINARY, 'artisan', 'nuna:restore', $this->dir.DIRECTORY_SEPARATOR.$file, '--force']);
    expect($restore->successful())->toBeTrue($restore->errorOutput().$restore->output());

    $safety = BackupService::existingBackups($safetyDir);
    DB::purge('source');
    expect($safety)->toHaveCount(1)
        ->and($safety[0])->toEndWith('-before-restore.sql.gz')
        ->and(figures('source')['trialBalance']['balanced'])->toBeTrue();
    array_map('unlink', glob($safetyDir.DIRECTORY_SEPARATOR.'*'));
    rmdir($safetyDir);
});

it('warns in preflight about a scratch database an interrupted backup left behind', function () {
    $leftover = BACKUP_SOURCE.'_scratch_leftover';
    DB::statement("CREATE DATABASE `{$leftover}`");
    try {
        $preflight = nuna(BACKUP_SOURCE, 'nuna:preflight');
        expect($preflight->output())->toContain('No scratch databases left over')
            ->and($preflight->output())->toContain($leftover);
    } finally {
        DB::statement("DROP DATABASE IF EXISTS `{$leftover}`");
    }
});

it('rejects and DELETES a backup whose books do not balance', function () {
    $line = DB::connection('source')->table('journal_lines')->where('credit_minor', '>', 0)->orderBy('id')->first();
    DB::connection('source')->table('journal_lines')->where('id', $line->id)->update(['credit_minor' => $line->credit_minor + 5]);

    $backup = nuna(BACKUP_SOURCE, 'nuna:backup', "--dir={$this->dir}");
    expect($backup->successful())->toBeFalse()
        ->and($backup->output())->toContain('BACKUP FAILED')
        ->and($backup->output())->toContain('do not balance')
        ->and(glob($this->dir.DIRECTORY_SEPARATOR.'*'))->toBe([])
        ->and(DB::select("SHOW DATABASES LIKE '".BACKUP_SOURCE."\\_scratch\\_%'"))->toBe([]);
});

it('copies the live books before it loads anything from the file, so a file that writes to them can be undone', function () {
    expect(nuna(BACKUP_SOURCE, 'nuna:backup', "--dir={$this->dir}")->successful())->toBeTrue();
    [$file] = BackupService::existingBackups($this->dir);
    // A tampered dump: it names the live database outright, which no USE filter stops, then fails its check.
    $aimed = $this->dir.DIRECTORY_SEPARATOR.'names-the-live-database.sql.gz';
    file_put_contents($aimed, gzencode(gzdecode((string) file_get_contents($this->dir.DIRECTORY_SEPARATOR.$file))
        ."\nINSERT INTO `".BACKUP_SOURCE."`.customers (name, created_at, updated_at) VALUES ('Planted by the dump', NOW(), NOW());\nSELECT * FROM no_such_table_13;\n"));

    $safetyDir = $this->dir.DIRECTORY_SEPARATOR.'safety';
    $restore = Process::env(['DB_DATABASE' => BACKUP_SOURCE, 'APP_ENV' => 'testing', 'NUNA_BACKUP_DIR' => $safetyDir])
        ->path(base_path())->timeout(600)->run([PHP_BINARY, 'artisan', 'nuna:restore', $aimed, '--force']);
    $safety = BackupService::existingBackups($safetyDir);

    expect($restore->successful())->toBeFalse()
        ->and($safety)->toHaveCount(1)
        ->and($restore->output())->toContain($safety[0])
        ->and(gzdecode((string) file_get_contents($safetyDir.DIRECTORY_SEPARATOR.$safety[0])))->not->toContain('Planted by the dump');
    array_map('unlink', glob($safetyDir.DIRECTORY_SEPARATOR.'*'));
    rmdir($safetyDir);
});

it('refuses a backup file that is not a usable database, and one whose books do not balance, changing nothing', function () {
    expect(nuna(BACKUP_SOURCE, 'nuna:backup', "--dir={$this->dir}")->successful())->toBeTrue();
    [$file] = BackupService::existingBackups($this->dir);
    $tampered = $this->dir.DIRECTORY_SEPARATOR.'tampered.sql.gz';
    file_put_contents($tampered, gzencode(gzdecode((string) file_get_contents($this->dir.DIRECTORY_SEPARATOR.$file))."\nUPDATE journal_lines SET credit_minor = credit_minor + 5 WHERE credit_minor > 0 LIMIT 1;\n"));
    $junk = $this->dir.DIRECTORY_SEPARATOR.'junk.sql.gz';
    file_put_contents($junk, gzencode('this is not a database'));

    foreach ([$tampered, $junk] as $bad) {
        $restore = nuna(BACKUP_SOURCE, 'nuna:restore', $bad, '--force');
        expect($restore->successful())->toBeFalse()
            ->and($restore->output())->toContain('nothing was changed');
    }
    expect(figures('source')['trialBalance']['balanced'])->toBeTrue()
        ->and(DB::connection('source')->table('journal_entries')->count())->toBeGreaterThan(0);
});

it('keeps the newest backups and removes the rest, leaving other files alone', function () {
    foreach (['2026-01-01T00-00-00', '2026-01-02T00-00-00', '2026-01-03T00-00-00'] as $old) {
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.BackupService::FILE_PREFIX.$old.BackupService::FILE_SUFFIX, 'old');
    }
    file_put_contents($this->dir.DIRECTORY_SEPARATOR.'notes.txt', 'mine');

    $backup = nuna(BACKUP_SOURCE, 'nuna:backup', "--dir={$this->dir}", '--keep=2');
    expect($backup->successful())->toBeTrue($backup->output());
    $left = BackupService::existingBackups($this->dir);
    expect($left)->toHaveCount(2)
        ->and($left[0])->toBe('nunabooks-2026-01-03T00-00-00.sql.gz')
        ->and(file_exists($this->dir.DIRECTORY_SEPARATOR.'notes.txt'))->toBeTrue()
        ->and(nuna(BACKUP_SOURCE, 'nuna:backup', "--dir={$this->dir}", '--keep=0')->successful())->toBeFalse();
});
