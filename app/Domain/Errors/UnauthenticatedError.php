<?php

declare(strict_types=1);

namespace App\Domain\Errors;

final class UnauthenticatedError extends DomainError
{
    public function __construct()
    {
        parent::__construct('UNAUTHENTICATED', 'No valid session', 'Please sign in to continue.');
    }
}
