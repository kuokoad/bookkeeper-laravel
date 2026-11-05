<?php

declare(strict_types=1);

namespace App\Domain\Errors;

/** A money or quantity computation left the safe integer range. Never ignore this. */
final class MoneyOverflowError extends DomainError
{
    /** @param  array<string, mixed>  $details */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct('MONEY_OVERFLOW', $message, 'That amount is too large for the system to handle safely.', $details);
    }
}
