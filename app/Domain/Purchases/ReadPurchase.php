<?php

declare(strict_types=1);

namespace App\Domain\Purchases;

use App\Domain\Accounting\PeriodLock;
use App\Domain\BusinessDate;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\ValidationError;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Domain\Tax\TaxComponent;
use App\Domain\Tax\TaxComponents;
use App\Domain\Tax\TaxLine;

/**
 * What the new-purchase form would save, read the way the server reads it:
 * a port of the old purchases/new/read-purchase.ts. ONE pass over what was
 * typed decides every figure on the screen and whether Save is offered, using
 * the server's own parsers and domain arithmetic (Money::parse,
 * Quantity::parsePositive, the unit checks resolveLineUnit makes, the tax
 * createPurchase adds, the books lock). A figure is shown only when Save would
 * store exactly that figure; anything else is a dash (null).
 *
 * Only what the screen can know is checked. Save still goes through
 * PurchaseService::createPurchase, which re-checks everything.
 */
final class ReadPurchase
{
    public const INVOICE_NO_MAX = 60;

    public const PURCHASE_NOTE_MAX = 300;

    public const PAYMENT_REFERENCE_MAX = 80;

    /**
     * @param  array{productId: string, qty: string, alt: bool, unitCost: string, discount: string}  $entry
     * @param  array{unit: string, altUnit: ?string, altFactorMilli: ?int, unitDecimals: int, altUnitDecimals: ?int}|null  $product
     * @return array{blank: bool, errors: array<string, string>, total: ?int, free: bool}
     */
    public static function line(array $entry, ?array $product): array
    {
        $blank = $entry['productId'] === '' && trim($entry['qty']) === '' && trim($entry['unitCost']) === '' && trim($entry['discount']) === '';
        if ($blank) {
            return ['blank' => true, 'errors' => [], 'total' => null, 'free' => false];
        }

        $errors = [];
        if ($product === null) {
            $errors['product'] = 'Choose a product.';
        }
        $qty = self::qty($entry, $product);
        if (isset($qty['error'])) {
            $errors['qty'] = $qty['error'];
        }
        $cost = self::amount($entry['unitCost'], 'A unit cost cannot be negative.', 'Enter what each one cost.');
        if (isset($cost['error'])) {
            $errors['cost'] = $cost['error'];
        }
        $discount = self::amount($entry['discount'], 'A discount cannot be negative.');
        if (isset($discount['error'])) {
            $errors['discount'] = $discount['error'];
        }

        $total = null;
        if (isset($qty['qty'], $cost['amount'], $discount['amount'])) {
            try {
                $gross = Quantity::extendPrice($cost['amount'], $qty['qty']);
                if ($discount['amount'] > $gross) {
                    $errors['discount'] = 'The discount cannot be more than the line, '.Money::toDecimalString($gross).'.';
                } else {
                    $total = Money::subtract($gross, $discount['amount']);
                }
            } catch (DomainError $e) {
                $errors['cost'] = $e->userMessage;
            }
        }

        $refused = $errors !== [];

        return ['blank' => false, 'errors' => $errors, 'total' => $refused ? null : $total, 'free' => ! $refused && $total === 0];
    }

    /** @return array{qty?: int, error?: string} the checks resolveLineUnit makes, in the same order */
    private static function qty(array $entry, ?array $product): array
    {
        if (trim($entry['qty']) === '') {
            return ['error' => 'Enter a quantity.'];
        }
        try {
            $qty = Quantity::parsePositive($entry['qty'], 'Quantity');
            if ($product === null) {
                return ['qty' => $qty];
            }
            if (! $entry['alt']) {
                Quantity::assertUnitDecimals($qty, $product['unitDecimals'], $product['unit']);

                return ['qty' => $qty];
            }
            if ($product['altUnit'] === null || $product['altFactorMilli'] === null) {
                return ['error' => 'This product has no alternate unit.'];
            }
            Quantity::assertUnitDecimals($qty, $product['altUnitDecimals'] ?? Quantity::DECIMALS, $product['altUnit']);
            Quantity::assertUnitDecimals(Quantity::toBase($qty, $product['altFactorMilli']), $product['unitDecimals'], $product['unit']);

            return ['qty' => $qty];
        } catch (DomainError $e) {
            return ['error' => $e->userMessage];
        }
    }

    /**
     * An amount box. Empty is zero unless the box is required: zero is a real
     * cost, an empty cost box is not.
     *
     * @return array{amount?: int, error?: string}
     */
    public static function amount(string $text, string $negative, ?string $required = null): array
    {
        if (trim($text) === '') {
            return $required !== null ? ['error' => $required] : ['amount' => 0];
        }
        try {
            $amount = Money::parse($text);

            return $amount < 0 ? ['error' => $negative] : ['amount' => $amount];
        } catch (DomainError $e) {
            return ['error' => $e->userMessage];
        }
    }

    private static function length(string $text, int $max): ?string
    {
        return mb_strlen(trim($text)) > $max ? "Keep this to {$max} characters." : null;
    }

    private static function dateError(string $businessDate, ?string $lockedBefore): ?string
    {
        try {
            // The check Filters::isValidBusinessDate makes: the shape, then a real day.
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $businessDate) !== 1) {
                throw new ValidationError('Enter the delivery date.');
            }
            BusinessDate::assert($businessDate);
        } catch (ValidationError) {
            return 'Enter the delivery date.';
        }
        try {
            PeriodLock::assertOpen($businessDate, $lockedBefore);

            return null;
        } catch (DomainError $e) {
            return $e->userMessage;
        }
    }

    /**
     * @param  list<array>  $rows
     * @param  array<string, array>  $productsById
     * @param  list<TaxComponent>  $taxComponents
     * @return array{lines: list<array>, filled: int, dateError: ?string, invoiceNoError: ?string, referenceError: ?string, subtotal: ?int, invoiceDiscountError: ?string, taxLines: list<TaxLine>, total: ?int, totalError: ?string, paid: ?int, paidError: ?string, outstanding: ?int, acceptable: bool}
     */
    public static function read(array $rows, array $productsById, string $invoiceDiscount, string $payAmount, bool $canPay, array $taxComponents, string $businessDate, ?string $lockedBefore, string $invoiceNo, string $reference): array
    {
        $lines = array_map(fn (array $row): array => self::line($row, $productsById[$row['productId']] ?? null), $rows);
        $sent = array_values(array_filter($lines, fn (array $l): bool => ! $l['blank']));

        $totalError = null;
        $subtotal = null;
        if ($sent !== [] && ! in_array(null, array_column($sent, 'total'), true)) {
            try {
                $subtotal = Money::sum(array_column($sent, 'total'));
            } catch (DomainError $e) {
                $totalError = $e->userMessage;
            }
        }

        $discount = self::amount($invoiceDiscount, 'A discount cannot be negative.');
        $invoiceDiscountError = $discount['error'] ?? null;
        if ($subtotal !== null && isset($discount['amount']) && $discount['amount'] > $subtotal) {
            $invoiceDiscountError = 'The discount cannot be more than the subtotal, '.Money::toDecimalString($subtotal).'.';
        }

        // Tax goes on top of the net, by the same domain function the server charges it with.
        $taxLines = [];
        $total = null;
        if ($subtotal !== null && isset($discount['amount']) && $invoiceDiscountError === null) {
            try {
                $breakdown = TaxComponents::onNet(Money::subtract($subtotal, $discount['amount']), $taxComponents);
                $taxLines = $breakdown->lines;
                $total = $breakdown->gross;
            } catch (DomainError $e) {
                $totalError = $e->userMessage;
            }
        }

        $payment = self::amount($payAmount, 'A payment cannot be negative.');
        $paidError = $payment['error'] ?? null;
        if (isset($payment['amount']) && $payment['amount'] > 0 && ! $canPay) {
            $paidError = 'Add a payment account before recording a payment.';
        } elseif (isset($payment['amount']) && $total !== null && $payment['amount'] > $total) {
            $paidError = 'You cannot pay more than the purchase total.';
        }
        $paid = $paidError === null ? ($payment['amount'] ?? null) : null;
        $dateError = self::dateError($businessDate, $lockedBefore);
        $invoiceNoError = self::length($invoiceNo, self::INVOICE_NO_MAX);
        // No amount, or no account to pay from, sends no payment, and with it no reference to refuse.
        $referenceError = trim($payAmount) === '' || ! $canPay ? null : self::length($reference, self::PAYMENT_REFERENCE_MAX);

        return [
            'lines' => $lines,
            'filled' => count($sent),
            'dateError' => $dateError,
            'invoiceNoError' => $invoiceNoError,
            'referenceError' => $referenceError,
            'subtotal' => $subtotal,
            'invoiceDiscountError' => $invoiceDiscountError,
            'taxLines' => $taxLines,
            'total' => $total,
            'totalError' => $totalError,
            'paid' => $paid,
            'paidError' => $paidError,
            'outstanding' => $total !== null && $paid !== null ? Money::subtract($total, $paid) : null,
            'acceptable' => $total !== null && $paidError === null && $dateError === null && $invoiceNoError === null && $referenceError === null,
        ];
    }

    /** Whether any one box, rather than the purchase as a whole, is refused. */
    public static function fieldRefused(array $reading): bool
    {
        foreach ($reading['lines'] as $line) {
            if ($line['errors'] !== []) {
                return true;
            }
        }

        return array_filter([$reading['dateError'], $reading['invoiceNoError'], $reading['invoiceDiscountError'], $reading['paidError'], $reading['referenceError']], fn ($e) => $e !== null) !== [];
    }
}
