<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a tax component is charged on.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum TaxBasis: string
{
    use HasValues;

    case Net = 'NET';
    case NetPlusLevies = 'NET_PLUS_LEVIES';
}
