<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * `php artisan db:seed`: the idempotent baseline always; demo data only when
 * SEED_DEMO_DATA=true. A port of the old src/db/seed/index.ts runSeed.
 *
 * Demo data is refused in production, and on any database that already holds
 * real trading (a shop being set up, a copy somebody is checking a figure
 * against) unless --force is given. `migrate:fresh --seed` always passes
 * --force, but it has just emptied the database, so nothing real is there.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CoreSeeder::class);
        $this->command?->info('Baseline seeded: settings, chart of accounts, sequences, payment accounts.');

        if (config('nuna.seed_demo_data') !== true) {
            return;
        }

        // Hard stop before a single demo row can be written to a real shop.
        if (app()->isProduction()) {
            throw new RuntimeException('Refusing to seed demo data in production: it would add invented sales to a real shop.');
        }

        $real = DemoSeeder::countRealRecords();
        $forced = $this->command !== null && $this->command->hasOption('force') && $this->command->option('force') === true;
        if ($real > 0 && ! $forced) {
            throw new RuntimeException(implode(' ', [
                "Refusing to seed demo data: this database already holds {$real} record(s) that are not demo data.",
                'Seeding would add invented sales and suppliers alongside them, and would rename the shop.',
                'Re-run with --force if that is genuinely what you want.',
            ]));
        }

        $this->call(DemoSeeder::class);
        $this->command?->info('Demo data seeded.');
        $this->command?->line('  Owner  ->  '.DemoSeeder::DEMO_OWNER['username'].' / '.DemoSeeder::DEMO_OWNER['password']);
        $this->command?->line('  Staff  ->  '.DemoSeeder::DEMO_STAFF['username'].' / '.DemoSeeder::DEMO_STAFF['password'].' (PIN '.DemoSeeder::DEMO_STAFF['pin'].')');
        $this->command?->warn('  These are DEMO credentials. Never use them on a real shop database.');
    }
}
