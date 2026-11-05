<?php

namespace App\Enums;

/**
 * Whether an adjustment line adds or removes stock.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum AdjustmentDirection: string
{
    use HasValues;

    case In = 'IN';
    case Out = 'OUT';
}
