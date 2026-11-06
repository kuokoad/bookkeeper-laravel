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

    public static function stockIn(StockState $current, int $qty, int $totalCost): Movement
    {
        if ($qty <= 0) {
            throw new ValidationError('Stock in requires a quantity greater than zero.', ['qty' => $qty]);
        }
        if ($totalCost < 0) {
            throw new ValidationError('Stock cannot be received at a negative cost.', ['totalCost' => $totalCost]);
        }

        $nextQty = Quantity::add($current->qty, $qty);
        $nextValue = Money::add($current->value, $totalCost);
        $residual = 0;
        // Receiving stock that clears a negative position at a different price.
        if ($nextQty === 0 && $nextValue !== 0) {
            $residual = $nextValue;
            $nextValue = 0;
        }

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
            throw new InsufficientStockError($productName ?? 'this stock item', self::formatMilli($current->qty), self::formatMilli($qty));
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

    /** A return comes back at what it originally cost, not today's average. */
    public static function returnIn(StockState $current, int $qty, int $originalTotalCost): Movement
    {
        return self::stockIn($current, $qty, $originalTotalCost);
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
            throw new InsufficientStockError($productName ?? 'this stock item', self::formatMilli($current->qty), self::formatMilli($qty));
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

    public static function writeOff(StockState $current, int $qty, ?int $fallbackUnitCost = null, bool $allowNegative = false, ?string $productName = null): Movement
    {
        return self::stockOut($current, $qty, $fallbackUnitCost, $allowNegative, $productName);
    }

    /**
     * Recompute the pair from the stock ledger's movements, row 1 onwards,
     * to prove the cached balance.
     *
     * @param  iterable<array{qtyIn: int, qtyOut: int, totalCost: int}>  $movements
     */
    public static function replay(iterable $movements): StockState
    {
        $qty = 0;
        $value = 0;
        foreach ($movements as $movement) {
            if ($movement['qtyIn'] > 0) {
                $qty = Quantity::add($qty, $movement['qtyIn']);
                $value = Money::add($value, $movement['totalCost']);
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

    /** "2.5": the quantity as the error message shows it, without grouping. */
    private static function formatMilli(int $value): string
    {
        $digits = str_pad(ltrim((string) $value, '-'), 4, '0', STR_PAD_LEFT);
        $fraction = rtrim(substr($digits, -3), '0');

        return ($value < 0 ? '-' : '').substr($digits, 0, -3).($fraction !== '' ? ".{$fraction}" : '');
    }
}
