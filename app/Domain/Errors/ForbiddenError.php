<?php

declare(strict_types=1);

namespace App\Domain\Errors;

/**
 * Most refusals are best left vague; supply a user message where the refusal
 * is an ordinary part of somebody's day (a cashier typing a discount).
 */
final class ForbiddenError extends DomainError
{
    public function __construct(string $action, ?string $userMessage = null)
    {
        parent::__construct('FORBIDDEN', "Not permitted: {$action}", $userMessage ?? 'You do not have permission to do that.', ['action' => $action]);
    }
}
