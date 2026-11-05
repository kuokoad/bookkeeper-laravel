<?php

namespace App\Enums;

/**
 * The shop-wide look.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum Look: string
{
    use HasValues;

    case Default = 'default';
    case Ledger = 'ledger';
    case Classic = 'classic';
}
