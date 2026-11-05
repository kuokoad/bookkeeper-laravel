<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A sale, or the mirror document of a return or void.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum SaleKind: string
{
    use HasValues;

    case Sale = 'SALE';
    case Return = 'RETURN';
    case Void = 'VOID';
}
