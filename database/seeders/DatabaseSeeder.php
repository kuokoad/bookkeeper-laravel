<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CoreSeeder::class);

        if (config('nuna.seed_demo_data') === true) {
            if (app()->isProduction()) {
                throw new RuntimeException('Refusing to seed demo data in production: it would add invented sales to a real shop.');
            }

            // The old demo seed (src/db/seed/demo*.ts) records sales, purchases and
            // payments through the services, so it is ported once those exist.
            throw new RuntimeException('Demo data cannot be seeded yet: it needs the sales, purchase and cashbook services (Phases 4 to 6). Set SEED_DEMO_DATA=false.');
        }
    }
}
