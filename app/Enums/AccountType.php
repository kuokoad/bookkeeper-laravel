<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ledger account types.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum AccountType: string
{
    use HasValues;

    case Asset = 'ASSET';
    case Liability = 'LIABILITY';
    case Equity = 'EQUITY';
    case ContraEquity = 'CONTRA_EQUITY';
    case Revenue = 'REVENUE';
    case ContraRevenue = 'CONTRA_REVENUE';
    case Cogs = 'COGS';
    case Expense = 'EXPENSE';
}
