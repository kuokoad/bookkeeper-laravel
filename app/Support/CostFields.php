<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Errors\DomainError;
use App\Domain\JsCompat;
use App\Domain\Money;
use App\Domain\Quantity;

/**
 * The unit cost and total value a stock-in line on the adjustment form shows.
 * The field typed into last leads and is shown as typed; the other is worked
 * out from it and the quantity, blank until both read. The total is what the
 * form posts, so a unit-led line posts exactly unit × qty rounded to the
 * pesewa (extendPrice), the same rounding as every sale and purchase line.
 *
 * A port of the old adjustments/new/cost-fields.ts. 'error' says why the typed
 * figure cannot be used.
 */
final class CostFields
{
    /**
     * @param  'unit'|'total'  $source  which of the two fields was typed into last
     * @return array{unitCost: string, total: string, error?: string}
     */
    public static function of(string $source, string $typed, string $qtyText): array
    {
        $shown = fn (string $derived): array => $source === 'unit'
            ? ['unitCost' => $typed, 'total' => $derived]
            : ['unitCost' => $derived, 'total' => $typed];
        if (JsCompat::trim($typed) === '') {
            return $shown('');
        }

        try {
            $cost = Money::parse($typed);
        } catch (DomainError $error) {
            return [...$shown(''), 'error' => $error->userMessage];
        }
        if ($cost < 0) {
            return [...$shown(''), 'error' => 'A cost cannot be negative.'];
        }

        $derived = '';
        try {
            $quantity = Quantity::parse($qtyText);
            $derived = Money::toInputString($source === 'unit' ? Quantity::extendPrice($cost, $quantity) : Quantity::derivedUnitPrice($cost, $quantity));
        } catch (DomainError) {
            // Half-typed or zero quantity: the quantity field answers for itself.
        }

        return $shown($derived);
    }
}
