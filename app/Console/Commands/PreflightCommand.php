<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PreflightService;
use Illuminate\Console\Command;

/** The old `npm run preflight`: production readiness, exit 1 on any failure. */
final class PreflightCommand extends Command
{
    protected $signature = 'nuna:preflight';

    protected $description = 'Check this install is ready for real trading: configuration, migrations, books, stock, owners, demo data';

    public function handle(): int
    {
        $checks = PreflightService::run();
        $symbol = ['pass' => '  ok  ', 'warn' => ' warn ', 'fail' => ' FAIL '];

        $this->line('');
        $this->line('Production readiness');
        $this->line('');
        foreach ($checks as $check) {
            $this->line("[{$symbol[$check['status']]}] {$check['name']}");
            $this->line("           {$check['detail']}");
        }

        $failures = count(array_filter($checks, fn (array $c): bool => $c['status'] === 'fail'));
        $warnings = count(array_filter($checks, fn (array $c): bool => $c['status'] === 'warn'));
        $this->line('');
        if ($failures > 0) {
            $this->error("{$failures} check(s) FAILED. Do not use this for real trading yet.");

            return self::FAILURE;
        }
        $this->info($warnings > 0 ? "Ready, with {$warnings} thing(s) to decide on above." : 'Ready for production.');

        return self::SUCCESS;
    }
}
