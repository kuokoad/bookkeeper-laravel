<?php

namespace App\Domain\Tax;

use App\Domain\Errors\ValidationError;
use App\Domain\Money;
use App\Enums\TaxBasis;
use Brick\Math\BigInteger;

/**
 * Ghana charges more than one tax on the same sale: NHIL, the GETFund levy
 * and VAT. They are separate obligations, remitted on one return but
 * accounted for separately, and a VAT invoice has to show each one. They are
 * held as a configurable list, because the rates change with the national
 * budget more often than software gets rewritten.
 *
 * Each component says what it is charged on, and they apply in order:
 *
 *  - NET              the goods value, before any tax;
 *  - NET_PLUS_LEVIES  the goods value plus every component ahead of it.
 *
 * On GHS 100.00 with NHIL 2.5%, GETFund 2.5% and VAT 15% all on the net, the
 * customer pays 120.00 (20%). With VAT on NET_PLUS_LEVIES, the GRA's own
 * computation, VAT is 15% of 105.00 and the customer pays 120.75 (20.75%).
 *
 * Each component is rounded on its own base and the total is the sum of the
 * parts: NHIL is 2.5% of a stated base and nothing else. Sharing out a
 * combined total instead would hand the authority 14 pesewas of VAT on
 * GHS 1.01 where 15 are due.
 *
 * A port of the old src/domain/tax/components.ts.
 */
final class TaxComponents
{
    private const BASIS_POINTS = 10_000;

    /**
     * The all-in rate in basis points, rounded half up: 2000 for the three
     * Ghana taxes on the net, 2075 with VAT on the net plus levies.
     *
     * @param  list<TaxComponent>  $components
     */
    public static function totalRateBp(array $components): int
    {
        self::assertUsable($components);
        [$num, $den] = self::taxPerNet($components);
        if ($num->isZero()) {
            return 0;
        }

        // (num × 10000 + den / 2) / den in truncating integer division, exactly
        // as the old BigInt code. num and den are positive here.
        return $num->multipliedBy(self::BASIS_POINTS)
            ->plus($den->quotient(2))
            ->quotient($den)
            ->toInt();
    }

    /**
     * Tax added on top of a net amount (prices exclusive of tax).
     *
     * @param  list<TaxComponent>  $components
     */
    public static function onNet(int $net, array $components): TaxBreakdown
    {
        self::assertUsable($components);
        $lines = self::linesOn($net, $components);
        $total = self::sumLines($lines);

        return new TaxBreakdown($lines, $total, $net, Money::add($net, $total));
    }

    /**
     * Tax taken out of a shelf price that already includes it. The customer
     * always pays exactly the label price: net + total = gross. When no exact
     * decomposition exists, the net keeps what the exact division gave it,
     * each line stays its own rate on its own base, and the odd pesewa comes
     * off the largest line, where it moves the rate least.
     *
     * @param  list<TaxComponent>  $components
     */
    public static function withinGross(int $gross, array $components): TaxBreakdown
    {
        self::assertUsable($components);
        [$num, $den] = self::taxPerNet($components);
        if ($num->isZero()) {
            return new TaxBreakdown(self::linesOn($gross, $components), 0, $gross, $gross);
        }

        $net = Money::mulDiv($gross, $den, $den->plus($num));
        $lines = self::linesOn($net, $components);
        $residual = Money::subtract(Money::subtract($gross, $net), self::sumLines($lines));
        $settled = $residual === 0 ? $lines : self::absorb($lines, $residual);

        return new TaxBreakdown($settled, self::sumLines($settled), $net, $gross);
    }

    /**
     * The share of each charged line that goes back with a return of
     * $returnedNet out of $originalNet. Shares are worked from magnitudes and
     * the sign travels with the total, so negative documents work too.
     *
     * @param  list<TaxLine>  $charged
     * @return list<TaxLine>
     */
    public static function shareOf(array $charged, int $returnedNet, int $originalNet): array
    {
        if ($originalNet === 0 || $returnedNet === 0) {
            return array_map(static fn (TaxLine $line): TaxLine => $line->withAmount(0), $charged);
        }

        $total = Money::mulDiv(self::sumLines($charged), $returnedNet, $originalNet);
        $shares = Money::allocate($total, array_map(static fn (TaxLine $line): int => abs($line->amount), $charged));

        return array_map(static fn (TaxLine $line, int $share): TaxLine => $line->withAmount($share), $charged, $shares);
    }

    /**
     * Each component on its own base, rounded half away from zero.
     *
     * @param  list<TaxComponent>  $components
     * @return list<TaxLine>
     */
    private static function linesOn(int $net, array $components): array
    {
        $lines = [];
        $running = 0;
        foreach ($components as $component) {
            $base = $component->basis === TaxBasis::NetPlusLevies ? Money::add($net, $running) : $net;
            $amount = $component->rateBp === 0 ? 0 : Money::mulDiv($base, $component->rateBp, self::BASIS_POINTS);
            $running = Money::add($running, $amount);
            $lines[] = new TaxLine($component, $amount);
        }

        return $lines;
    }

    /**
     * Put a residual pesewa (or a few) on the largest lines first, ties to
     * the earliest, one pesewa at a time.
     *
     * @param  list<TaxLine>  $lines
     * @return list<TaxLine>
     */
    private static function absorb(array $lines, int $residual): array
    {
        $order = array_keys($lines);
        usort($order, static fn (int $a, int $b): int => abs($lines[$b]->amount) <=> abs($lines[$a]->amount) ?: $a <=> $b);

        $amounts = array_map(static fn (TaxLine $line): int => $line->amount, $lines);
        $step = $residual > 0 ? 1 : -1;
        for ($i = 0; $residual !== 0; $i++) {
            $target = $order[$i % count($order)];
            $amounts[$target] = Money::add($amounts[$target], $step);
            $residual -= $step;
        }

        return array_map(static fn (TaxLine $line, int $amount): TaxLine => $line->withAmount($amount), $lines, $amounts);
    }

    /**
     * Tax per unit of net as an exact fraction num/den.
     *
     * @param  list<TaxComponent>  $components
     * @return array{BigInteger, BigInteger}
     */
    private static function taxPerNet(array $components): array
    {
        $num = BigInteger::zero();
        $den = BigInteger::one();
        foreach ($components as $component) {
            $base = $component->basis === TaxBasis::NetPlusLevies ? $den->plus($num) : $den;
            $num = $num->multipliedBy(self::BASIS_POINTS)->plus(BigInteger::of($component->rateBp)->multipliedBy($base));
            $den = $den->multipliedBy(self::BASIS_POINTS);
            $divisor = $num->gcd($den);
            if ($divisor->isGreaterThan(1)) {
                $num = $num->quotient($divisor);
                $den = $den->quotient($divisor);
            }
        }

        return [$num, $den];
    }

    /** @param  list<TaxLine>  $lines */
    private static function sumLines(array $lines): int
    {
        return Money::sum(array_map(static fn (TaxLine $line): int => $line->amount, $lines));
    }

    /** @param  list<TaxComponent>  $components */
    private static function assertUsable(array $components): void
    {
        foreach ($components as $component) {
            if ($component->rateBp < 0) {
                throw new ValidationError("The {$component->name} rate cannot be negative.", ['component' => $component->code]);
            }
        }
    }
}
