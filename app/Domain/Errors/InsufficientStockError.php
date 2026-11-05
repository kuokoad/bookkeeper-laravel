<?php

declare(strict_types=1);

namespace App\Domain\Errors;

final class InsufficientStockError extends DomainError
{
    public function __construct(string $productName, string $available, string $requested)
    {
        parent::__construct(
            'INSUFFICIENT_STOCK',
            "Insufficient stock for {$productName}: available {$available}, requested {$requested}",
            "Not enough stock for {$productName}. Available: {$available}, requested: {$requested}.",
            ['productName' => $productName, 'available' => $available, 'requested' => $requested],
        );
    }
}
