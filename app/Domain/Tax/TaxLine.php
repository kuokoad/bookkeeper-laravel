<?php

namespace App\Domain\Tax;

/** One component's tax on one document, in minor units (negative on a return). */
final readonly class TaxLine
{
    public function __construct(
        public TaxComponent $component,
        public int $amount,
    ) {}

    public function withAmount(int $amount): self
    {
        return new self($this->component, $amount);
    }
}
