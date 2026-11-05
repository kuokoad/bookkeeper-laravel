<?php

namespace App\Enums;

/**
 * The business transaction a journal entry came from.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum JournalSourceType: string
{
    use HasValues;

    case Sale = 'SALE';
    case SaleReturn = 'SALE_RETURN';
    case Purchase = 'PURCHASE';
    case PurchaseReturn = 'PURCHASE_RETURN';
    case CustomerPayment = 'CUSTOMER_PAYMENT';
    case SupplierPayment = 'SUPPLIER_PAYMENT';
    case Expense = 'EXPENSE';
    case Income = 'INCOME';
    case StockAdjustment = 'STOCK_ADJUSTMENT';
    case Reconciliation = 'RECONCILIATION';
    case OpeningBalance = 'OPENING_BALANCE';
    case Capital = 'CAPITAL';
    case Drawings = 'DRAWINGS';
    case Transfer = 'TRANSFER';
    case Reversal = 'REVERSAL';
    case YearEndClose = 'YEAR_END_CLOSE';
}
