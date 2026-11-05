<?php

declare(strict_types=1);

/*
| The start of every worker script here: boot Laravel, then make an uncaught
| exception fail the process. Laravel's own handler reports it and lets PHP
| exit 0, so a crashed worker would read to its test as one that succeeded.
*/

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(function (Throwable $e): never {
    fwrite(STDERR, $e::class.': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
});
