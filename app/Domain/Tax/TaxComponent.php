<?php

namespace App\Domain\Tax;

use App\Enums\TaxBasis;

/**
 * One tax a shop charges, such as NHIL, GETFund or VAT. Ported from the old
 * domain/tax/components.ts `TaxComponent`.
 */
final readonly class TaxComponent
{
    public function __construct(
        /** Stable identifier: 'VAT', 'NHIL', 'GETFUND'. */
        public string $code,
        /** What appears on the receipt. */
        public string $name,
        /** Rate in basis points. 250 = 2.5%, 1500 = 15%. */
        public int $rateBp,
        public TaxBasis $basis = TaxBasis::Net,
        public bool $isRecoverable = false,
    ) {}
}
