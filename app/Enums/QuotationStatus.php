<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Life of a quotation.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum QuotationStatus: string
{
    use HasValues;

    case Open = 'OPEN';
    case Converted = 'CONVERTED';
    case Cancelled = 'CANCELLED';
}
