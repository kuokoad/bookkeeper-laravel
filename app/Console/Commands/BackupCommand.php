<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

/** The old `npm run backup`: a verified backup, the oldest trimmed after it. */
final class BackupCommand extends Command
{
    protected $signature = 'nuna:backup {--keep= : How many verified backups to keep (default NUNA_BACKUP_KEEP, 14)} {--dir= : Where to write it (default NUNA_BACKUP_DIR)}';

    protected $description = 'Back up the database with mysqldump, restore it into a scratch database and prove it sound before it counts';

    public function handle(): int
    {
        $keep = $this->option('keep');
        if ($keep !== null && (preg_match('/^\d+$/', (string) $keep) !== 1 || (int) $keep < 1)) {
            $this->error('--keep must be a whole number of backups to retain, at least 1.');

            return self::FAILURE;
        }

        try {
            $result = BackupService::createBackup($this->option('dir'), $keep === null ? null : (int) $keep);
        } catch (Throwable $e) {
            $this->error('BACKUP FAILED: '.$e->getMessage());
            $this->error('The shop is NOT backed up. Fix this before trusting the data.');

            return self::FAILURE;
        }

        BackupService::recordBackupTaken($result['entries']);
        $this->info("Backup written and verified: {$result['path']}");
        $this->line(sprintf('  %.2f MB · %d journal entries · restored and checked: books balance, stock replays, row counts match', $result['bytes'] / 1_000_000, $result['entries']));
        if ($result['removed'] !== []) {
            $this->line('  Removed '.count($result['removed']).' older backup(s).');
        }
        $this->line('Copy this file off the machine: a backup on the same computer does not survive it being stolen, dropped or its disk failing.');

        return self::SUCCESS;
    }
}
