<?php

declare(strict_types=1);

namespace App\Domain\Errors;

final class ValidationError extends DomainError
{
    /** @param  array<string, mixed>  $details */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct('VALIDATION', $message, $message, $details);
    }
}
