<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Errors\ValidationError;
use Brick\Math\BigInteger;

/**
 * Which units leave the shelf, and nothing about what they cost.
 *
 * First-expiry-first-out, with one deliberate exception: expired stock is
 * never taken automatically, and is not even mentioned while good stock
 * covers the sale. A block that fired while fresh stock sat on the shelf
 * would be routed around within a week, and a sale made off-system is worse
 * than one made from an old batch.
 *
 * Batches are arrays: ['id' => int, 'batchRef' => string, 'expiryDate' => ?string, 'qtyMilli' => int].
 * Allocations are arrays: ['batchId' => int, 'batchRef' => string, 'qtyMilli' => int].
 *
 * A port of the old src/domain/inventory/batches.ts.
 */
final class Batches
{
    /**
     * "2.5", "-3", "1200": a batch quantity as messages show it. Unlike
     * Quantity::format there is no thousands separator, as the old batches.ts.
     */
    public static function formatQty(int $milli): string
    {
        $digits = str_pad(ltrim((string) $milli, '-'), 4, '0', STR_PAD_LEFT);
        $fraction = rtrim(substr($digits, -3), '0');

        return ($milli < 0 ? '-' : '').substr($digits, 0, -3).($fraction !== '' ? ".{$fraction}" : '');
    }

    /** A batch is still good on its own date: that is the last good day. */
    public static function isExpired(array $batch, string $today): bool
    {
        return $batch['expiryDate'] !== null && $batch['expiryDate'] < $today;
    }

    /**
     * Dated-and-good first (soonest date first), then undated, then expired
     * (oldest date first). Ties go to the lower id, so the same shelf always
     * gives the same answer.
     *
     * @param  list<array>  $batches
     * @return list<array>
     */
    public static function orderForPicking(array $batches, string $today): array
    {
        $rank = fn (array $batch): int => self::isExpired($batch, $today) ? 2 : ($batch['expiryDate'] === null ? 1 : 0);
        usort($batches, function (array $a, array $b) use ($rank): int {
            $byRank = $rank($a) <=> $rank($b);
            if ($byRank !== 0) {
                return $byRank;
            }
            if ($a['expiryDate'] !== null && $b['expiryDate'] !== null && $a['expiryDate'] !== $b['expiryDate']) {
                return $a['expiryDate'] < $b['expiryDate'] ? -1 : 1;
            }

            return $a['id'] <=> $b['id'];
        });

        return $batches;
    }

    /**
     * Plan which batches a quantity comes out of.
     *
     * Returns ['allocations' => list, 'shortfall' => int, 'expiredNeeded' => int, 'expiredRefs' => list<string>].
     * A shortfall is a genuine shortage (InsufficientStock); expiredNeeded is
     * expired stock the sale would need, a decision for somebody senior.
     *
     * With $expiredFirst (a write-off of goods that have turned) the order is
     * inverted: picking by soonest date would remove the GOOD stock and leave
     * the expired crate on the shelf, still counted and still blocking sales.
     *
     * @param  list<array>  $batches
     */
    public static function allocateFefo(array $batches, int $qty, string $today, bool $allowExpired = false, bool $expiredFirst = false): array
    {
        if ($qty <= 0) {
            throw new ValidationError('Picking requires a quantity greater than zero.', ['qty' => $qty]);
        }

        $ordered = array_values(array_filter(self::orderForPicking($batches, $today), fn (array $b): bool => $b['qtyMilli'] > 0));
        $good = array_values(array_filter($ordered, fn (array $b): bool => ! self::isExpired($b, $today)));
        $expired = array_values(array_filter($ordered, fn (array $b): bool => self::isExpired($b, $today)));
        $expiredRefs = array_map(fn (array $b): string => $b['batchRef'], $expired);

        $allocations = [];
        $remaining = $qty;
        $drawFrom = function (array $from) use (&$allocations, &$remaining): void {
            foreach ($from as $batch) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min($batch['qtyMilli'], $remaining);
                $allocations[] = ['batchId' => $batch['id'], 'batchRef' => $batch['batchRef'], 'qtyMilli' => $take];
                $remaining -= $take;
            }
        };

        if ($expiredFirst) {
            $drawFrom($expired);
            $drawFrom($good);
            $expiredIds = array_column($expired, 'id');
            $tookExpired = array_sum(array_map(
                fn (array $a): int => in_array($a['batchId'], $expiredIds, true) ? $a['qtyMilli'] : 0,
                $allocations,
            ));

            return ['allocations' => $allocations, 'shortfall' => max(0, $remaining), 'expiredNeeded' => $tookExpired, 'expiredRefs' => $expiredRefs];
        }

        $drawFrom($good);
        if ($remaining <= 0) {
            return ['allocations' => $allocations, 'shortfall' => 0, 'expiredNeeded' => 0, 'expiredRefs' => []];
        }

        $fromExpired = min(array_sum(array_column($expired, 'qtyMilli')), $remaining);
        if (! $allowExpired) {
            return ['allocations' => $allocations, 'shortfall' => $remaining - $fromExpired, 'expiredNeeded' => $fromExpired, 'expiredRefs' => $expiredRefs];
        }

        $drawFrom($expired);

        return ['allocations' => $allocations, 'shortfall' => max(0, $remaining), 'expiredNeeded' => $fromExpired, 'expiredRefs' => $expiredRefs];
    }

    /**
     * Put a quantity back across the batches it originally came out of, in
     * proportion, summing exactly. The odd units go to the largest remainders,
     * ties to the earliest source line.
     *
     * @param  list<array>  $source  allocations of the original movement
     * @return list<array>
     */
    public static function allocateProportional(array $source, int $qty): array
    {
        if ($source === []) {
            throw new ValidationError('Cannot put stock back across zero batches.');
        }
        if ($qty <= 0) {
            throw new ValidationError('Returning requires a quantity greater than zero.', ['qty' => $qty]);
        }
        $total = array_sum(array_column($source, 'qtyMilli'));
        if ($total <= 0) {
            throw new ValidationError('The original movement took nothing to put back.', ['total' => $total]);
        }
        if ($qty > $total) {
            throw new ValidationError("Cannot put back more than went out: {$qty} against {$total}.", ['qty' => $qty, 'total' => $total]);
        }

        $shares = [];
        $remainders = [];
        $distributed = 0;
        foreach ($source as $index => $item) {
            [$share, $remainder] = BigInteger::of($qty)->multipliedBy($item['qtyMilli'])->quotientAndRemainder($total);
            $shares[$index] = $share->toInt();
            $remainders[] = ['index' => $index, 'remainder' => $remainder];
            $distributed += $shares[$index];
        }

        $leftover = $qty - $distributed;
        usort($remainders, fn (array $a, array $b): int => $b['remainder']->compareTo($a['remainder']) ?: $a['index'] <=> $b['index']);
        foreach ($remainders as ['index' => $index]) {
            if ($leftover <= 0) {
                break;
            }
            $shares[$index]++;
            $leftover--;
        }

        $result = [];
        foreach ($source as $index => $item) {
            if ($shares[$index] > 0) {
                $result[] = ['batchId' => $item['batchId'], 'batchRef' => $item['batchRef'], 'qtyMilli' => $shares[$index]];
            }
        }

        return $result;
    }
}
