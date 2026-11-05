<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\BusinessDate;
use App\Services\Migration\Reconciler;
use Illuminate\Console\Command;

final class ReconcileCommand extends Command
{
    protected $signature = 'nuna:reconcile {path : The COPY of the old database that was imported} {--as-at= : Compare as at this date (YYYY-MM-DD)}';

    protected $description = 'Compare the old app\'s figures with these books. Zero differences is the only pass.';

    public function handle(Reconciler $reconciler): int
    {
        $asAt = $this->option('as-at');
        if ($asAt !== null) {
            BusinessDate::assert($asAt, 'as-at date');
        }

        $result = $reconciler->reconcile($this->argument('path'), $asAt);
        foreach ($result['checked'] as $what => $n) {
            $this->line("Checked {$n} {$what}");
        }

        if ($result['differences'] !== []) {
            $this->error(count($result['differences']).' difference(s):');
            foreach ($result['differences'] as $difference) {
                $this->line('  '.$difference);
            }

            return self::FAILURE;
        }

        $this->info('Zero differences.');

        return self::SUCCESS;
    }
}
