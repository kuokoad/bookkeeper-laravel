<?php

namespace App\Enums;

/**
 * The side an account normally carries its balance on.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum NormalBalance: string
{
    use HasValues;

    case Debit = 'DEBIT';
    case Credit = 'CREDIT';
}
