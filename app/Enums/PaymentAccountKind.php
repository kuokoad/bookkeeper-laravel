<?php

namespace App\Enums;

/**
 * Kinds of money account. No mobile network is hard-coded.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum PaymentAccountKind: string
{
    use HasValues;

    case Cash = 'CASH';
    case MobileMoney = 'MOBILE_MONEY';
    case Bank = 'BANK';
    case Other = 'OTHER';
}
