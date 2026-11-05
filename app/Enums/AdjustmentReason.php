<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why stock was adjusted.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum AdjustmentReason: string
{
    use HasValues;

    case OpeningStock = 'OPENING_STOCK';
    case Damaged = 'DAMAGED';
    case Lost = 'LOST';
    case Expired = 'EXPIRED';
    case Found = 'FOUND';
    case CountCorrection = 'COUNT_CORRECTION';
    case InternalUse = 'INTERNAL_USE';
    case Other = 'OTHER';
}
