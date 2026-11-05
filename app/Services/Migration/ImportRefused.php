<?php

declare(strict_types=1);

namespace App\Services\Migration;

use RuntimeException;

/** The import found problems and committed nothing. Every problem is listed. */
final class ImportRefused extends RuntimeException
{
    /** @param  list<string>  $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(count($problems).' problem(s); nothing was imported.');
    }
}
