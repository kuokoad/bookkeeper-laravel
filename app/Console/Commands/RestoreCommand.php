<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * The old `npm run db:restore -- <file> --force`. Refuses without --force,
 * and while the Apache service is running; whatever the web server, the app
 * is in maintenance mode while the restore runs (a sale could land mid-restore).
 */
final class RestoreCommand extends Command
{
    protected $signature = 'nuna:restore {file : A backup made by nuna:backup} {--force : Yes, replace every record in the shop}';

    protected $description = 'Replace the database with a verified backup, keeping a verified copy of what it replaces';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! $this->option('force')) {
            $this->warn('This replaces every record in the shop with the contents of that backup.');
            $this->line("  from: {$file}");
            $this->line("  to:   the MySQL database {$database}");
            $this->line('The backup is checked first, and the books being replaced are backed up before anything changes.');
            $this->line('Re-run with --force if that is what you want.');

            return self::FAILURE;
        }

        $service = (string) config('nuna.apache_service');
        if (PHP_OS_FAMILY === 'Windows' && $service !== '' && str_contains(Process::run(['sc', 'query', $service])->output(), 'RUNNING')) {
            $this->error("The web server ({$service}) is running, so a till could record a sale mid-restore. Stop it first: Stop-Service {$service}");

            return self::FAILURE;
        }

        // The service check above only sees Apache run as a Windows service. Under Laragon or
        // `php artisan serve` nothing would stop a till, so every web request is turned away
        // until the restore ends, and the shop opens again only if this command closed it.
        $wasDown = app()->isDownForMaintenance();
        if (! $wasDown) {
            $this->callSilently('down', ['--retry' => 60]);
        }
        try {
            $result = BackupService::restore($file);
        } catch (Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if (! $wasDown) {
                $this->callSilently('up');
            }
        }

        $this->info("Restored {$database} from {$file}");
        $this->line("  {$result['entries']} journal entries, books balanced, stock replays");
        $this->line($result['safetyBackup'] === null ? '  the database was empty before' : "  the books it replaced are in {$result['safetyBackup']}");

        return self::SUCCESS;
    }
}
