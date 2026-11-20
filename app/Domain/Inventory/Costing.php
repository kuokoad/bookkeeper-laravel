<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Errors\InsufficientStockError;
use App\Domain\Errors\ValidationError;
use App\Domain\Money;
use App\Domain\Quantity;

/**
 * Weighted-average inventory costing. No per-unit average cost is ever
 * stored: a product's cost basis is the running pair (Q, V).
 *
 *   Stock in  q units for total cost c:   Q' = Q + q      V' = V + c
 *   Stock out q units:                    cogs = round(V × q / Q)
 *                                         Q'   = Q − q    V' = V − cogs
 *
 * Value is allocated out of the running total, never recomputed, so V is
 * conserved to the pesewa. Selling the last unit sends any remainder to COGS.
 *
 * Pure functions. A port of the old src/domain/inventory/costing.ts.
 */
final class Costing
{
    /** Per-unit average, for display. Zero when there is nothing on hand. */
    public static function averageUnitCost(StockState $state): int
    {
        return $state->qty === 0 ? 0 : Money::mulDiv($state->value, Quantity::SCALE, $state->qty);
    }

    /**
     * Goods put back can name their first $cancelsQty units, worth $cancelsCost, as the document's
     * own oversold units: those cancel the negative at what was charged for them, and only the rest
     * meet the shelf (fifth audit follow-up). Without them a return of 5 tins, 3 oversold at 1.50 and
     * 2 found at 1.00, left the 2 at the average 1.30.
     */
    public static function stockIn(StockState $current, int $qty, int $totalCost, int $cancelsQty = 0, int $cancelsCost = 0): Movement
    {
        if ($qty <= 0) {
            throw new ValidationError('Stock in requires a quantity greater than zero.', ['qty' => $qty]);
        }
        if ($totalCost < 0) {
            throw new ValidationError('Stock cannot be received at a negative cost.', ['totalCost' => $totalCost]);
        }
        if ($cancelsQty < 0 || $cancelsQty > $qty || $cancelsCost < 0 || $cancelsCost > $totalCost) {
            throw new ValidationError('The units cancelling an oversold position must be part of the stock coming in.', ['cancelsQty' => $cancelsQty, 'cancelsCost' => $cancelsCost]);
        }

        $nextQty = Quantity::add($current->qty, $qty);
        $nextValue = self::valueAfterPutBack($current->qty, $current->value, $qty, $totalCost, $cancelsQty, $cancelsCost);
        // Receiving stock that clears or crosses a negative position at a different price: the
        // oversold units' true cost goes to the residual, and what stays on the shelf is at this cost.
        $residual = Money::subtract(Money::add($current->value, $totalCost), $nextValue);

        return new Movement(self::coherent($nextQty, $nextValue), $totalCost, Money::mulDiv($totalCost, Quantity::SCALE, $qty), $residual);
    }

    /**
     * Stock out at the running average. With negative stock allowed, the
     * real value on hand is consumed first and the excess is costed at the
     * fallback unit cost.
     */
    public static function stockOut(StockState $current, int $qty, ?int $fallbackUnitCost = null, bool $allowNegative = false, ?string $productName = null): Movement
    {
        if ($qty <= 0) {
            throw new ValidationError('Stock out requires a quantity greater than zero.', ['qty' => $qty]);
        }
        if (! $allowNegative && $qty > $current->qty) {
            throw new InsufficientStockError($productName ?? 'this stock item', Batches::formatQty($current->qty), Batches::formatQty($qty));
        }

        if ($current->qty > 0 && $qty <= $current->qty) {
            $cogs = Money::mulDiv($current->value, $qty, $current->qty);
            $nextQty = Quantity::subtract($current->qty, $qty);
            $nextValue = Money::subtract($current->value, $cogs);
            if ($nextQty === 0 && $nextValue !== 0) {
                $cogs = Money::add($cogs, $nextValue);
                $nextValue = 0;
            }

            return new Movement(self::coherent($nextQty, $nextValue), $cogs, Money::mulDiv($cogs, Quantity::SCALE, $qty), 0);
        }

        $covered = $current->qty > 0 ? $current->qty : 0;
        $excess = Quantity::subtract($qty, $covered);
        $coveredCost = $current->qty > 0 ? $current->value : 0;
        $excessCost = Quantity::extendPrice($fallbackUnitCost ?? 0, $excess);
        $cogs = Money::add($coveredCost, $excessCost);
        $nextQty = Quantity::subtract($current->qty, $qty);
        $nextValue = Money::subtract($current->qty > 0 ? 0 : $current->value, $excessCost);
        if ($nextQty === 0 && $nextValue !== 0) {
            $cogs = Money::add($cogs, $nextValue);
            $nextValue = 0;
        }

        return new Movement(self::coherent($nextQty, $nextValue), $cogs, Money::mulDiv($cogs, Quantity::SCALE, $qty), 0);
    }

    /**
     * Stock out at a known cost (voiding a purchase, a supplier return). If it
     * empties the shelf, whatever value is left over is reported as residual.
     */
    public static function stockOutAtCost(StockState $current, int $qty, int $totalCost, bool $allowNegative = false, ?string $productName = null): Movement
    {
        if ($qty <= 0) {
            throw new ValidationError('Stock out requires a quantity greater than zero.', ['qty' => $qty]);
        }
        if ($totalCost < 0) {
            throw new ValidationError('Stock cannot be returned at a negative cost.', ['totalCost' => $totalCost]);
        }
        if (! $allowNegative && $qty > $current->qty) {
            throw new InsufficientStockError($productName ?? 'this stock item', Batches::formatQty($current->qty), Batches::formatQty($qty));
        }

        $nextQty = Quantity::subtract($current->qty, $qty);
        $remaining = Money::subtract($current->value, $totalCost);

        return new Movement(
            self::coherent($nextQty, $nextQty === 0 ? 0 : $remaining),
            $totalCost,
            Money::mulDiv($totalCost, Quantity::SCALE, $qty),
            $nextQty === 0 ? $remaining : 0,
        );
    }

    /**
     * Recompute the pair from the stock ledger's movements, row 1 onwards,
     * to prove the cached balance.
     *
     * @param  iterable<array{qtyIn: int, qtyOut: int, totalCost: int, cancelsQty?: int, cancelsCost?: int}>  $movements
     */
    /**
     * V after receiving q for c. Normally V + c. From below zero, the oversold units are
     * cleared at this delivery's cost: landing on zero leaves 0, and crossing it leaves only
     * what is left of this delivery, at its own cost (both apps once kept the fallback-cost gap
     * in V, so one unit could read 7.50 for goods bought at 3.00; fifth audit, finding 12).
     */
    /** valueAfterIn for the cancelling units, then for the rest from where they left the shelf. */
    private static function valueAfterPutBack(int $qty, int $value, int $inQty, int $inCost, int $cancelsQty, int $cancelsCost): int
    {
        if ($cancelsQty === 0) {
            return self::valueAfterIn($qty, $value, $inQty, $inCost);
        }
        $value = self::valueAfterIn($qty, $value, $cancelsQty, $cancelsCost);
        $qty = Quantity::add($qty, $cancelsQty);

        return $cancelsQty === $inQty ? $value : self::valueAfterIn($qty, $value, Quantity::subtract($inQty, $cancelsQty), Money::subtract($inCost, $cancelsCost));
    }

    private static function valueAfterIn(int $qty, int $value, int $inQty, int $inCost): int
    {
        $nextQty = Quantity::add($qty, $inQty);
        if ($qty < 0 && $nextQty > 0) {
            return Money::mulDiv($inCost, $nextQty, $inQty);
        }

        return $nextQty === 0 ? 0 : Money::add($value, $inCost);
    }

    public static function replay(iterable $movements): StockState
    {
        $qty = 0;
        $value = 0;
        foreach ($movements as $movement) {
            if ($movement['qtyIn'] > 0) {
                $value = self::valueAfterPutBack($qty, $value, $movement['qtyIn'], $movement['totalCost'], $movement['cancelsQty'] ?? 0, $movement['cancelsCost'] ?? 0);
                $qty = Quantity::add($qty, $movement['qtyIn']);
            } elseif ($movement['qtyOut'] > 0) {
                $qty = Quantity::subtract($qty, $movement['qtyOut']);
                $value = Money::subtract($value, $movement['totalCost']);
            }
            if ($qty === 0) {
                $value = 0;
            }
        }

        return new StockState($qty, $value);
    }

    /** At or below the product's own minimum, or the shop's default when it has none. */
    public static function isLowStock(int $qtyOnHand, ?int $minStock, int $fallbackMin): bool
    {
        return $qtyOnHand <= ($minStock ?? $fallbackMin);
    }

    public static function isOutOfStock(int $qtyOnHand): bool
    {
        return $qtyOnHand <= 0;
    }

    /** Invariant: zero quantity must mean zero value. */
    private static function coherent(int $qty, int $value): StockState
    {
        if ($qty === 0 && $value !== 0) {
            throw new ValidationError("Inventory state is incoherent: zero quantity but value {$value}.");
        }

        return new StockState($qty, $value);
    }
}
