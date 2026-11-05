<?php

declare(strict_types=1);

namespace App\Domain\Errors;

class ConflictError extends DomainError
{
    /** @param  array<string, mixed>  $details */
    public function __construct(string $message, array $details = [], string $errorCode = 'CONFLICT', ?string $userMessage = null)
    {
        parent::__construct($errorCode, $message, $userMessage ?? $message, $details);
    }
}
