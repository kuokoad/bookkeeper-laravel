<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * A product's cost basis: quantity on hand (milli-units) and the total value
 * of that quantity (pesewas). The running pair (Q, V). Quantity may be
 * negative only when the shop allows selling stock it does not have.
 */
final readonly class StockState
{
    public function __construct(
        public int $qty,
        public int $value,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0);
    }
}
