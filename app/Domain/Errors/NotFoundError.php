<?php

declare(strict_types=1);

namespace App\Domain\Errors;

final class NotFoundError extends DomainError
{
    public function __construct(string $entity, string|int $id)
    {
        parent::__construct(
            'NOT_FOUND',
            "{$entity} not found: {$id}",
            'That '.strtolower($entity).' could not be found.',
            ['entity' => $entity, 'id' => $id],
        );
    }
}
