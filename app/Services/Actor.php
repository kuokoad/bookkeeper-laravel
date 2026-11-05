<?php

declare(strict_types=1);

namespace App\Services;

/** Who is doing something: written to audit rows and created_by columns. */
final readonly class Actor
{
    public function __construct(
        public int $id,
        public string $username,
    ) {}
}
