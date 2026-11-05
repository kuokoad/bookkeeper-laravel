<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Errors\ValidationError;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Domain\Tax\TaxComponent;
use App\Domain\Tax\TaxComponents;

/**
 * Sale arithmetic: line totals, discounts, tax, tender and profit. Pure.
 * A port of the old src/domain/sales/calculate.ts.
 */
final class SaleCalculator
{
    /**
     * @param  list<array{productId: int, qty: int, unitPrice: int, discount?: int}>  $lines
     * @param  list<TaxComponent>  $taxComponents
     */
    public static function calculate(array $lines, int $invoiceDiscount = 0, array $taxComponents = [], bool $taxInclusive = false): SaleTotals
    {
        if ($lines === []) {
            throw new ValidationError('A sale must have at least one item.');
        }

        $withGross = [];
        foreach ($lines as $line) {
            if ($line['qty'] <= 0) {
                throw new ValidationError('Every item needs a quantity greater than zero.');
            }
            if ($line['unitPrice'] < 0) {
                throw new ValidationError('A selling price cannot be negative.');
            }
            $gross = Quantity::extendPrice($line['unitPrice'], $line['qty']);
            $discount = $line['discount'] ?? 0;
            if ($discount < 0) {
                throw new ValidationError('A discount cannot be negative.');
            }
            if ($discount > $gross) {
                throw new ValidationError('A line discount cannot be more than the line total.', ['gross' => $gross, 'discount' => $discount]);
            }
            // Computed values win over anything the caller passed, as the old object spread did.
            $withGross[] = array_merge($line, ['discount' => $discount, 'gross' => $gross, 'lineTotal' => Money::subtract($gross, $discount)]);
        }

        $subtotal = Money::sum(array_column($withGross, 'lineTotal'));
        if ($invoiceDiscount < 0) {
            throw new ValidationError('A discount cannot be negative.');
        }
        if ($invoiceDiscount > $subtotal) {
            throw new ValidationError('The discount cannot be more than the sale total.', ['subtotal' => $subtotal, 'invoiceDiscount' => $invoiceDiscount]);
        }

        // The invoice discount is shared across lines by value, without losing a pesewa.
        $allocated = $invoiceDiscount === 0
            ? array_fill(0, count($withGross), 0)
            : Money::allocate($invoiceDiscount, array_column($withGross, 'lineTotal'));
        $calculated = [];
        foreach ($withGross as $index => $line) {
            $calculated[] = array_merge($line, [
                'allocatedInvoiceDiscount' => $allocated[$index],
                'netTotal' => Money::subtract($line['lineTotal'], $allocated[$index]),
            ]);
        }

        $netBeforeTax = Money::subtract($subtotal, $invoiceDiscount);
        $lineDiscounts = Money::sum(array_column($calculated, 'discount'));
        $totalDiscount = Money::add($lineDiscounts, $invoiceDiscount);

        if ($taxComponents === []) {
            return new SaleTotals($calculated, $subtotal, $invoiceDiscount, $totalDiscount, $netBeforeTax, [], 0, $netBeforeTax, $subtotal, $invoiceDiscount, $totalDiscount);
        }

        if ($taxInclusive) {
            // Tax is inside the shelf price: extract it, and take the tax the
            // discounts contained out of them too, so the ex-tax figures tie.
            $breakdown = TaxComponents::withinGross($netBeforeTax, $taxComponents);
            $taxInInvoiceDiscount = TaxComponents::withinGross($invoiceDiscount, $taxComponents)->total;
            $taxInLineDiscounts = TaxComponents::withinGross($lineDiscounts, $taxComponents)->total;

            return new SaleTotals(
                $calculated, $subtotal, $invoiceDiscount, $totalDiscount, $netBeforeTax,
                $breakdown->lines, $breakdown->total, $netBeforeTax,
                Money::subtract($subtotal, Money::add($breakdown->total, $taxInInvoiceDiscount)),
                Money::subtract($invoiceDiscount, $taxInInvoiceDiscount),
                Money::subtract($totalDiscount, Money::add($taxInInvoiceDiscount, $taxInLineDiscounts)),
            );
        }

        $breakdown = TaxComponents::onNet($netBeforeTax, $taxComponents);

        return new SaleTotals(
            $calculated, $subtotal, $invoiceDiscount, $totalDiscount, $netBeforeTax,
            $breakdown->lines, $breakdown->total, Money::add($netBeforeTax, $breakdown->total),
            $subtotal, $invoiceDiscount, $totalDiscount,
        );
    }

    /**
     * What the tenders come to against a total. Change is handed back and is
     * never revenue: only the total is applied.
     *
     * @param  list<array{paymentAccountId: int, amount: int}>  $tenders
     * @return array{tendered: int, applied: int, change: int, outstanding: int}
     */
    public static function tender(int $total, array $tenders, bool $allowChange = true): array
    {
        foreach ($tenders as $tender) {
            if ($tender['amount'] < 0) {
                throw new ValidationError('A payment amount cannot be negative.');
            }
        }

        $tendered = Money::sum(array_column($tenders, 'amount'));
        if ($tendered <= $total) {
            return ['tendered' => $tendered, 'applied' => $tendered, 'change' => 0, 'outstanding' => Money::subtract($total, $tendered)];
        }
        if (! $allowChange) {
            throw new ValidationError('The amount paid is more than the sale total. Reduce it, or allow change to be given.', ['total' => $total, 'tendered' => $tendered]);
        }

        return ['tendered' => $tendered, 'applied' => $total, 'change' => Money::subtract($tendered, $total), 'outstanding' => 0];
    }

    public static function grossProfit(int $netRevenue, int $cogs): int
    {
        return Money::subtract($netRevenue, $cogs);
    }

    /** Profit on a sale is net of tax: tax is owed to the authority, not earned. */
    public static function saleGrossProfit(int $total, int $tax, int $cogs): int
    {
        return self::grossProfit(Money::subtract($total, $tax), $cogs);
    }

    /**
     * Each line's share of what the sale earned (total less tax), by line
     * value, so line reports tie to net sales on the P&L.
     *
     * @param  list<int>  $lineTotals
     * @return list<int>
     */
    public static function lineEarnings(int $total, int $tax, array $lineTotals): array
    {
        return Money::allocate(Money::subtract($total, $tax), array_map(abs(...), $lineTotals));
    }

    /**
     * Margin in basis points, for display. Null rather than a misleading zero
     * when there is no revenue. Rounded as JavaScript's Math.round did (half
     * towards +∞), so the figure matches the old screens.
     */
    public static function marginBp(int $netRevenue, int $cogs): ?int
    {
        if ($netRevenue === 0) {
            return null;
        }

        return (int) floor(self::grossProfit($netRevenue, $cogs) / $netRevenue * 10_000 + 0.5);
    }

    /** Cash that may go back on a return: never more than leaves the customer in credit. */
    public static function refundableOnReturn(int $returnedValue, int $outstanding): int
    {
        return Money::min($returnedValue, Money::atLeastZero(Money::subtract($returnedValue, $outstanding)));
    }

    /** Credit still available; null means no limit, which differs from a limit of zero. */
    public static function creditHeadroom(?int $creditLimit, int $currentBalance): ?int
    {
        return $creditLimit === null ? null : Money::atLeastZero(Money::subtract($creditLimit, $currentBalance));
    }

    /** Exactly at the limit is allowed. */
    public static function exceedsCreditLimit(?int $creditLimit, int $currentBalance, int $additionalCredit): bool
    {
        return $creditLimit !== null && Money::add($currentBalance, $additionalCredit) > $creditLimit;
    }

    /**
     * The totals as a customer should read them. Under inclusive pricing the
     * printed subtotal is the sum of the lines as printed, the discount is
     * what the customer was told, and the tax sits within the total. Both are
     * recovered by subtraction from exact amounts, never by re-applying a
     * rate, which could leave a printed column out by a pesewa.
     *
     * @param  list<int>  $lineTotals
     * @return array{subtotal: int, discount: int, tax: int, total: int, taxWithinTotal: bool}
     */
    public static function documentTotals(int $subtotal, int $discount, int $tax, int $total, bool $taxInclusive, array $lineTotals): array
    {
        [$subtotal, $discount, $tax, $total] = array_map(Money::of(...), [$subtotal, $discount, $tax, $total]);
        if (! $taxInclusive) {
            return ['subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $total, 'taxWithinTotal' => false];
        }

        $printedSubtotal = Money::sum($lineTotals);

        return ['subtotal' => $printedSubtotal, 'discount' => Money::subtract($printedSubtotal, $total), 'tax' => $tax, 'total' => $total, 'taxWithinTotal' => true];
    }
}
