<?php

namespace App\Enums;

/**
 * Money the owner puts in or takes out.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum OwnerMovementKind: string
{
    use HasValues;

    case Capital = 'CAPITAL';
    case Drawings = 'DRAWINGS';
}
