<?php

declare(strict_types=1);

namespace App\Domain\Errors;

use RuntimeException;

/**
 * Base of the typed domain error hierarchy, ported from the old
 * domain/errors.ts.
 *
 * Domain code throws these; services let them propagate (rolling back the
 * transaction); the HTTP layer turns them into messages. Nothing is ever
 * silently swallowed.
 */
abstract class DomainError extends RuntimeException
{
    /**
     * @param  string  $userMessage  Safe to show a shop owner. Never internals or secrets.
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $userMessage,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
