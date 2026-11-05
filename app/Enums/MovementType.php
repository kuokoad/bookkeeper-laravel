<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kinds of stock movement in the stock ledger.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum MovementType: string
{
    use HasValues;

    case OpeningStock = 'OPENING_STOCK';
    case Purchase = 'PURCHASE';
    case PurchaseReturn = 'PURCHASE_RETURN';
    case Sale = 'SALE';
    case SaleReturn = 'SALE_RETURN';
    case AdjustmentIn = 'ADJUSTMENT_IN';
    case AdjustmentOut = 'ADJUSTMENT_OUT';
}
