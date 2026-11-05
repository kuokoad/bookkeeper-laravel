<?php

declare(strict_types=1);

namespace App\Domain\Purchases;

use App\Domain\Errors\ValidationError;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Domain\Tax\TaxBreakdown;
use App\Domain\Tax\TaxComponent;
use App\Domain\Tax\TaxComponents;

/**
 * The arithmetic of a delivery, from the old purchase.service.ts createPurchase.
 *
 * A supplier invoice is priced net and the tax is added on top, whichever way
 * the shop prices its own shelves.
 */
final class PurchaseCalculator
{
    /** One line: unit cost × quantity, less its discount, with the old refusals in the old order. */
    public static function lineTotal(int $unitCost, int $qty, int $discount = 0): int
    {
        if ($qty <= 0) {
            throw new ValidationError('Every line needs a quantity greater than zero.');
        }
        if ($unitCost < 0) {
            throw new ValidationError('A unit cost cannot be negative.');
        }
        $gross = Quantity::extendPrice($unitCost, $qty);
        if ($discount < 0) {
            throw new ValidationError('A discount cannot be negative.');
        }
        if ($discount > $gross) {
            throw new ValidationError('A line discount cannot be more than the line total.');
        }

        return Money::subtract($gross, $discount);
    }

    /**
     * Subtotal, the invoice discount, tax on the net and the total.
     *
     * @param  list<int>  $lineTotals
     * @param  list<TaxComponent>  $components
     * @return array{subtotal: int, net: int, breakdown: TaxBreakdown, tax: int, total: int}
     */
    public static function totals(array $lineTotals, int $invoiceDiscount, array $components): array
    {
        $subtotal = Money::sum($lineTotals);
        if ($invoiceDiscount < 0) {
            throw new ValidationError('A discount cannot be negative.');
        }
        if ($invoiceDiscount > $subtotal) {
            throw new ValidationError('The discount cannot be more than the purchase total.');
        }
        $net = Money::subtract($subtotal, $invoiceDiscount);
        $breakdown = TaxComponents::onNet($net, $components);

        return ['subtotal' => $subtotal, 'net' => $net, 'breakdown' => $breakdown, 'tax' => $breakdown->total, 'total' => Money::sum([$net, $breakdown->total])];
    }

    /**
     * What was paid now and what is left owing. Never more than the total.
     *
     * @param  list<int>  $amounts
     * @return array{tendered: int, outstanding: int}
     */
    public static function tender(int $total, array $amounts): array
    {
        $tendered = Money::sum($amounts);
        if ($tendered < 0) {
            throw new ValidationError('A payment cannot be negative.');
        }
        if ($tendered > $total) {
            throw new ValidationError('You cannot pay more than the purchase total. Reduce the amount paid.', ['total' => $total, 'tendered' => $tendered]);
        }

        return ['tendered' => $tendered, 'outstanding' => Money::subtract($total, $tendered)];
    }

    /**
     * What each line's goods really cost: its total, less its share of the
     * invoice discount, plus its share of the tax that cannot be reclaimed.
     * Both shared by largest remainder, so they add back to the pesewa.
     *
     * @param  list<int>  $lineTotals
     * @return list<int>
     */
    public static function lineCosts(array $lineTotals, int $invoiceDiscount, int $nonRecoverableTax): array
    {
        $none = array_fill(0, count($lineTotals), 0);
        $discountShares = $invoiceDiscount === 0 ? $none : Money::allocate($invoiceDiscount, $lineTotals);
        $levyShares = $nonRecoverableTax === 0 ? $none : Money::allocate($nonRecoverableTax, $lineTotals);

        $costs = [];
        foreach ($lineTotals as $index => $lineTotal) {
            $costs[] = Money::sum([Money::subtract($lineTotal, $discountShares[$index]), $levyShares[$index]]);
        }

        return $costs;
    }
}
