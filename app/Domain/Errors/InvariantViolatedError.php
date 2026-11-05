<?php

namespace App\Domain\Errors;

final class InvariantViolatedError extends DomainError
{
    /** @param  array<string, mixed>  $details */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct('INVARIANT_VIOLATED', $message, 'The system detected inconsistent data and stopped to protect your records.', $details);
    }
}
