<?php

namespace App\Domain\Tax;

/**
 * Tax on an amount, line by line. The total is defined as the sum of the
 * lines, so the two can never disagree, and net + total is always gross.
 */
final readonly class TaxBreakdown
{
    /** @param  list<TaxLine>  $lines */
    public function __construct(
        public array $lines,
        public int $total,
        public int $net,
        public int $gross,
    ) {}

    /** @return list<int> */
    public function amounts(): array
    {
        return array_map(static fn (TaxLine $line): int => $line->amount, $this->lines);
    }
}
