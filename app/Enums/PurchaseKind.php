<?php

namespace App\Enums;

/**
 * A purchase, or the mirror document of a return or void.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum PurchaseKind: string
{
    use HasValues;

    case Purchase = 'PURCHASE';
    case Return = 'RETURN';
    case Void = 'VOID';
}
