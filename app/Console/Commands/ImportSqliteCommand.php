<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Migration\ImportRefused;
use App\Services\Migration\SqliteImporter;
use Illuminate\Console\Command;

final class ImportSqliteCommand extends Command
{
    protected $signature = 'nuna:import-sqlite {path : A COPY of the old app\'s bookkeeper.db}';

    protected $description = 'Copy the old NunaBooks SQLite database into this (freshly migrated, unseeded) MySQL database, unchanged';

    public function handle(SqliteImporter $importer): int
    {
        try {
            $counts = $importer->import($this->argument('path'));
        } catch (ImportRefused $refused) {
            $this->error($refused->getMessage());
            foreach ($refused->problems as $problem) {
                $this->line('  '.$problem);
            }

            return self::FAILURE;
        }

        $this->table(['Table', 'Rows'], collect($counts)->map(fn (int $n, string $t): array => [$t, $n])->values()->all());
        $this->info('Imported '.array_sum($counts).' rows. Next: php artisan nuna:reconcile '.$this->argument('path'));

        return self::SUCCESS;
    }
}
