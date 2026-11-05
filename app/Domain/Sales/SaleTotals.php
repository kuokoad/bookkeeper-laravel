<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Tax\TaxLine;

/**
 * A sale's figures as stored: net of tax, so subtotal − discount + tax =
 * total for every sale however the shelves are priced.
 *
 * Each line is ['productId', 'qty', 'unitPrice', 'discount', 'gross',
 * 'lineTotal', 'allocatedInvoiceDiscount', 'netTotal'].
 */
final readonly class SaleTotals
{
    /**
     * @param  list<array<string, int>>  $lines
     * @param  list<TaxLine>  $taxLines
     */
    public function __construct(
        public array $lines,
        public int $subtotal,
        public int $invoiceDiscount,
        public int $totalDiscount,
        public int $netBeforeTax,
        public array $taxLines,
        public int $tax,
        public int $total,
        /** The same three figures with any tax they contain taken out (differs only under inclusive pricing). */
        public int $subtotalExTax,
        public int $invoiceDiscountExTax,
        public int $totalDiscountExTax,
    ) {}
}
