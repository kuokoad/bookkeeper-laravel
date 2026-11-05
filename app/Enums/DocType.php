<?php

namespace App\Enums;

/**
 * Document types that get a gap-free number from the sequences table.
 * Prefixes and padding are the old sequence.service DEFAULT_SEQUENCES.
 */
enum DocType: string
{
    use HasValues;

    case Receipt = 'RECEIPT';
    case Invoice = 'INVOICE';
    case SaleReturn = 'SALE_RETURN';
    case Purchase = 'PURCHASE';
    case PurchaseReturn = 'PURCHASE_RETURN';
    case Journal = 'JOURNAL';
    case PaymentIn = 'PAYMENT_IN';
    case PaymentOut = 'PAYMENT_OUT';
    case Expense = 'EXPENSE';
    case Income = 'INCOME';
    case Adjustment = 'ADJUSTMENT';
    case Reconciliation = 'RECONCILIATION';
    /** A batch is a document in its own right: a shop points at one during a recall. */
    case Batch = 'BATCH';
    /** A quote needs a number the customer can quote back weeks later. */
    case Quote = 'QUOTE';
    case Transfer = 'TRANSFER';

    public function prefix(): string
    {
        return match ($this) {
            self::Receipt => 'RCP-',
            self::Invoice => 'INV-',
            self::SaleReturn => 'SRT-',
            self::Purchase => 'PUR-',
            self::PurchaseReturn => 'PRT-',
            self::Journal => 'JE-',
            self::PaymentIn => 'RCV-',
            self::PaymentOut => 'PAY-',
            self::Expense => 'EXP-',
            self::Income => 'INC-',
            self::Adjustment => 'ADJ-',
            self::Reconciliation => 'REC-',
            self::Batch => 'BAT-',
            self::Quote => 'QTE-',
            self::Transfer => 'TRF-',
        };
    }

    public function padding(): int
    {
        return $this === self::Journal ? 6 : 5;
    }
}
