<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/** The result of moving stock in or out. */
final readonly class Movement
{
    public function __construct(
        /** The running pair after the movement: written to the ledger row. */
        public StockState $state,
        /** Exact value that moved. Positive both ways (as cost of goods sold going out). */
        public int $totalCost,
        /** Rounded per-unit figure, for display only. Never use it to recompute cost. */
        public int $unitCost,
        /**
         * Value the shelf could not keep, over and above totalCost. Zero
         * quantity has to mean zero value; when a movement empties the shelf
         * at a known cost that differs from the value sitting there, the
         * difference is reported here for the caller to post, never dropped.
         * Positive means inventory gave up more than totalCost accounts for.
         */
        public int $residual,
    ) {}
}
