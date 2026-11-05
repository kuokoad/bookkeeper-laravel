<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Errors\MoneyOverflowError;
use App\Domain\Errors\ValidationError;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * Integer money. GHS 1,250.00 is 125000: a count of pesewas (minor units).
 * Floating point never represents a stored amount.
 *
 * A port of the old src/domain/money.ts, rounding included: every division
 * rounds half away from zero, through BigInteger so no product can overflow.
 *
 * Every result is kept inside ±(2^53 − 1), the old app's safe range (PLAN Q3),
 * so both apps refuse exactly the same amounts and every imported figure
 * round-trips.
 */
final class Money
{
    public const SCALE = 100;

    public const DECIMALS = 2;

    public const BASIS_POINTS = 10_000;

    /** Number.MAX_SAFE_INTEGER in the old app. */
    public const MAX_SAFE = 9_007_199_254_740_991;

    private const INPUT = '/^(?:\d{1,3}(?:,\d{3})*|\d+)(?:\.(\d+))?$/D';

    /** Validate a number as an amount of minor units (the old `minor()`). */
    public static function of(int|float $value): int
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new ValidationError('Amount is not a finite number.', ['value' => $value]);
            }
            if (floor($value) !== $value) {
                throw new ValidationError("Money must be a whole number of minor units, received {$value}.", ['value' => $value]);
            }
            if (abs($value) > self::MAX_SAFE) {
                throw new MoneyOverflowError('Amount exceeds the safe integer range.', ['value' => $value]);
            }

            return (int) $value;
        }

        return self::fromBig(BigInteger::of($value));
    }

    /**
     * From a major-unit float, by way of its two-decimal text, so 0.1 + 0.2
     * becomes 30 rather than inheriting binary error. It cannot recover
     * precision the caller already lost (1.005 is 1.00499… as a double):
     * that is why typed input goes through parse().
     */
    public static function fromMajor(float $major): int
    {
        if (! is_finite($major)) {
            throw new ValidationError('Amount is not a finite number.', ['major' => $major]);
        }

        // As JavaScript's toFixed(2): the exact binary value, ties to the larger magnitude.
        return self::fromBig(JsCompat::toFixedScaled($major, self::DECIMALS));
    }

    /**
     * Parse an amount a person typed: "1,250.50", "GHS 80", "₵80.00",
     * "-50.00", "(50.00)". More than two decimal places is refused, never
     * truncated.
     */
    public static function parse(string $input): int
    {
        $text = JsCompat::trim($input);
        if ($text === '') {
            throw new ValidationError('Enter an amount.');
        }

        $parenNegative = false;
        if (str_starts_with($text, '(') && str_ends_with($text, ')')) {
            $parenNegative = true;
            $text = JsCompat::trim(substr($text, 1, -1));
        }

        $text = preg_replace('/^[A-Za-z]{2,3}'.JsCompat::WHITESPACE.'*/u', '', $text) ?? $text;
        $text = JsCompat::trim(preg_replace('/^[₵$£€]'.JsCompat::WHITESPACE.'*/u', '', $text) ?? $text);

        $signNegative = false;
        if (str_starts_with($text, '-')) {
            $signNegative = true;
            $text = JsCompat::trim(substr($text, 1));
        }
        if ($parenNegative && $signNegative) {
            throw new ValidationError("\"{$input}\" has a confusing sign — enter it once.", ['input' => $input]);
        }

        if (preg_match(self::INPUT, $text, $match) !== 1) {
            throw new ValidationError("\"{$input}\" is not a valid amount.", ['input' => $input]);
        }

        $fraction = $match[1] ?? '';
        if (strlen($fraction) > self::DECIMALS) {
            throw new ValidationError(
                'Amounts can have at most '.self::DECIMALS." decimal places, \"{$input}\" has ".strlen($fraction).'.',
                ['input' => $input],
            );
        }

        $whole = str_replace(',', '', explode('.', $text)[0]);
        $combined = BigInteger::of($whole === '' ? '0' : $whole)
            ->multipliedBy(self::SCALE)
            ->plus(BigInteger::of(str_pad($fraction, self::DECIMALS, '0') ?: '0'));

        return self::fromBig(($parenNegative || $signNegative) ? $combined->negated() : $combined);
    }

    /** Narrow a BigInteger back to an amount, refusing anything outside the safe range. */
    public static function fromBig(BigInteger $value): int
    {
        if ($value->isGreaterThan(self::MAX_SAFE) || $value->isLessThan(-self::MAX_SAFE)) {
            throw new MoneyOverflowError('Amount exceeds the safe integer range.', ['value' => (string) $value]);
        }

        return $value->toInt();
    }

    /** n / d rounded half away from zero (2.5 → 3, −2.5 → −3). */
    public static function divRoundHalf(BigInteger $numerator, BigInteger $denominator): BigInteger
    {
        if ($denominator->isZero()) {
            throw new ValidationError('Division by zero in a money calculation.');
        }

        $negative = $numerator->isNegative() !== $denominator->isNegative();
        $a = $numerator->abs();
        $b = $denominator->abs();
        [$quotient, $remainder] = $a->quotientAndRemainder($b);
        $rounded = $remainder->multipliedBy(2)->isGreaterThanOrEqualTo($b) ? $quotient->plus(1) : $quotient;

        return $negative ? $rounded->negated() : $rounded;
    }

    public static function add(int $a, int $b): int
    {
        return self::fromBig(BigInteger::of($a)->plus($b));
    }

    public static function subtract(int $a, int $b): int
    {
        return self::fromBig(BigInteger::of($a)->minus($b));
    }

    public static function negate(int $a): int
    {
        return self::fromBig(BigInteger::of($a)->negated());
    }

    public static function absolute(int $a): int
    {
        return $a < 0 ? self::negate($a) : $a;
    }

    /** @param  iterable<int>  $values */
    public static function sum(iterable $values): int
    {
        $total = BigInteger::zero();
        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return self::fromBig($total);
    }

    /** value × numerator / denominator, rounded half away from zero. */
    public static function mulDiv(int $value, int|BigInteger $numerator, int|BigInteger $denominator): int
    {
        return self::fromBig(self::divRoundHalf(
            BigInteger::of($value)->multipliedBy($numerator),
            BigInteger::of($denominator),
        ));
    }

    public static function multiply(int $value, int $factor): int
    {
        return self::fromBig(BigInteger::of($value)->multipliedBy($factor));
    }

    /** A basis-point share: percentOf(125000, 1250) is 12.5% of GHS 1,250.00. */
    public static function percentOf(int $value, int|float $basisPoints): int
    {
        if (is_float($basisPoints)) {
            throw new ValidationError('Rate must be expressed in whole basis points.', ['basisPoints' => $basisPoints]);
        }

        return self::mulDiv($value, $basisPoints, self::BASIS_POINTS);
    }

    /**
     * total ÷ count as the old cashbook summary rounded it, JavaScript's
     * Math.round: halves go up, towards +∞ (2.5 → 3, −2.5 → −2). No rows is 0.
     */
    public static function average(int $total, int $count): int
    {
        if ($count === 0) {
            return 0;
        }

        return self::fromBig(BigInteger::of($total)->dividedBy($count, RoundingMode::HalfCeiling));
    }

    public static function max(int $a, int $b): int
    {
        return $a >= $b ? $a : $b;
    }

    public static function min(int $a, int $b): int
    {
        return $a <= $b ? $a : $b;
    }

    public static function atLeastZero(int $a): int
    {
        return $a > 0 ? $a : 0;
    }

    /**
     * Share a total across weights without losing or inventing a pesewa.
     * Each share is the truncated proportion; the pesewas left over go one at
     * a time to the largest remainders (ties to the earliest), as in the old
     * app.
     *
     * The old code scaled each weight by 1e6 in floating point. Integer
     * weights are scaled exactly here, which gives the same result for every
     * weight below about 9×10⁹ (PLAN Q4).
     *
     * @param  list<int|float>  $weights
     * @return list<int>
     */
    public static function allocate(int $total, array $weights): array
    {
        if ($weights === []) {
            throw new ValidationError('Cannot allocate across zero recipients.');
        }
        foreach ($weights as $weight) {
            if (! is_finite((float) $weight) || $weight < 0) {
                throw new ValidationError('Allocation weights must be non-negative finite numbers.');
            }
        }
        if (array_sum($weights) == 0) {
            return self::allocate($total, array_fill(0, count($weights), 1));
        }

        $totalBig = BigInteger::of($total);
        $scaled = array_map(
            static fn (int|float $weight): BigInteger => is_int($weight)
                ? BigInteger::of($weight)->multipliedBy(1_000_000)
                : BigInteger::of((int) round($weight * 1_000_000)),
            $weights,
        );
        $weightSum = array_reduce($scaled, static fn (BigInteger $carry, BigInteger $w): BigInteger => $carry->plus($w), BigInteger::zero());

        $shares = [];
        $remainders = [];
        $distributed = BigInteger::zero();
        foreach ($scaled as $index => $weight) {
            $product = $totalBig->multipliedBy($weight);
            $share = $product->quotient($weightSum);
            $shares[$index] = $share;
            $remainders[] = ['index' => $index, 'remainder' => $product->minus($share->multipliedBy($weightSum))->abs()];
            $distributed = $distributed->plus($share);
        }

        $leftover = $totalBig->minus($distributed);
        $step = $leftover->isNegative() ? -1 : 1;
        usort($remainders, static fn (array $a, array $b): int => $b['remainder']->compareTo($a['remainder']) ?: $a['index'] <=> $b['index']);

        for ($cursor = 0; ! $leftover->isZero(); $cursor++) {
            $target = $remainders[$cursor % count($remainders)]['index'];
            $shares[$target] = $shares[$target]->plus($step);
            $leftover = $leftover->minus($step);
        }

        return array_map(self::fromBig(...), $shares);
    }

    /** "1,250.00", or "1250.00" without grouping. */
    public static function toDecimalString(int $value, bool $groupThousands = true): string
    {
        $digits = str_pad(ltrim((string) $value, '-'), self::DECIMALS + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -self::DECIMALS);
        $fraction = substr($digits, -self::DECIMALS);
        if ($groupThousands) {
            $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) ?? $whole;
        }

        return ($value < 0 ? '-' : '').$whole.'.'.$fraction;
    }

    /** "GHS 1,250.00". The code labels the figure; no amount stores its currency. */
    public static function format(int $value, string $currencyCode = 'GHS'): string
    {
        return $currencyCode.' '.self::toDecimalString($value);
    }

    /** What a form field shows, and what parse() reads back. */
    public static function toInputString(int $value): string
    {
        return self::toDecimalString($value, false);
    }
}
