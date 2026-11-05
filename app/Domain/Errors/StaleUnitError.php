<?php

declare(strict_types=1);

namespace App\Domain\Errors;

/**
 * A carton line was entered at a carton size that has since changed. A
 * conflict to every screen that only shows the message; the till uses what it
 * carries to correct the line in place instead of making the cashier ring
 * the cart up again.
 */
final class StaleUnitError extends ConflictError
{
    /**
     * @param  array{productId: int, productName: string, unit: string, altUnit: string, altFactorMilli: int, altSellingPriceMinor: int, shownFactorMilli: int}  $current
     */
    public function __construct(string $message, public readonly array $current)
    {
        parent::__construct($message, $current);
    }
}
