<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Errors\MoneyOverflowError;
use App\Domain\Errors\ValidationError;
use Brick\Math\BigInteger;

/**
 * Integer quantity in milli-units: 1.5 kg is 1500. Goods can be sold in
 * fractions without floats. A port of the old src/domain/quantity.ts.
 */
final class Quantity
{
    public const SCALE = 1000;

    public const DECIMALS = 3;

    public const ONE = self::SCALE;

    private const INPUT = '/^(?:\d{1,3}(?:,\d{3})*|\d+)(?:\.(\d+))?$/D';

    /** Validate a number as a quantity in milli-units (the old `qty()`). */
    public static function of(int|float $value): int
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new ValidationError('Quantity is not a finite number.', ['value' => $value]);
            }
            if (floor($value) !== $value) {
                throw new ValidationError("Quantity must be a whole number of milli-units, received {$value}.", ['value' => $value]);
            }
            if (abs($value) > Money::MAX_SAFE) {
                throw new MoneyOverflowError('Quantity exceeds the safe integer range.', ['value' => $value]);
            }

            return (int) $value;
        }

        return self::fromBig(BigInteger::of($value));
    }

    /** From whole units as a float, through three-decimal text so 0.1 + 0.2 is 300. */
    public static function fromUnits(float $units): int
    {
        if (! is_finite($units)) {
            throw new ValidationError('Quantity is not a finite number.', ['units' => $units]);
        }

        // As JavaScript's toFixed(3): the exact binary value, ties to the larger magnitude.
        return self::fromBig(JsCompat::toFixedScaled($units, self::DECIMALS));
    }

    /** Parse a quantity a person typed: "3", "1.5", "1,200". At most three decimals. */
    public static function parse(string $input): int
    {
        $text = JsCompat::trim($input);
        if ($text === '') {
            throw new ValidationError('Enter a quantity.');
        }

        $negative = false;
        if (str_starts_with($text, '-')) {
            $negative = true;
            $text = JsCompat::trim(substr($text, 1));
        }

        if (preg_match(self::INPUT, $text, $match) !== 1) {
            throw new ValidationError("\"{$input}\" is not a valid quantity.", ['input' => $input]);
        }

        $fraction = $match[1] ?? '';
        if (strlen($fraction) > self::DECIMALS) {
            throw new ValidationError(
                'Quantities can have at most '.self::DECIMALS." decimal places, \"{$input}\" has ".strlen($fraction).'.',
                ['input' => $input],
            );
        }

        $whole = str_replace(',', '', explode('.', $text)[0]);
        $combined = BigInteger::of($whole === '' ? '0' : $whole)
            ->multipliedBy(self::SCALE)
            ->plus(BigInteger::of(str_pad($fraction, self::DECIMALS, '0') ?: '0'));

        return self::fromBig($negative ? $combined->negated() : $combined);
    }

    public static function parsePositive(string $input, string $label = 'Quantity'): int
    {
        $value = self::parse($input);
        if ($value <= 0) {
            throw new ValidationError("{$label} must be greater than zero.", ['input' => $input]);
        }

        return $value;
    }

    /**
     * A line in the product's second unit (a carton) must say the carton size
     * the screen showed, so a size changed since cannot slip through.
     *
     * @return array{alt?: true, altFactor?: int}
     */
    public static function parseAltEntry(?bool $alt, ?int $altFactor, string $label): array
    {
        if ($alt !== true) {
            return [];
        }
        if ($altFactor === null) {
            throw new ValidationError("{$label} is in the stock item's second unit but does not say its size. Reload the page and try again.");
        }

        return ['alt' => true, 'altFactor' => self::of($altFactor)];
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
    public static function scale(int $value, int|BigInteger $numerator, int|BigInteger $denominator): int
    {
        return self::fromBig(Money::divRoundHalf(BigInteger::of($value)->multipliedBy($numerator), BigInteger::of($denominator)));
    }

    public static function max(int $a, int $b): int
    {
        return $a >= $b ? $a : $b;
    }

    /** Price × quantity, to the nearest pesewa: 3 × GHS 8.70 = GHS 26.10. */
    public static function extendPrice(int $unitPrice, int $quantity): int
    {
        return Money::mulDiv($unitPrice, $quantity, self::SCALE);
    }

    /** The unit price a line total implies. */
    public static function derivedUnitPrice(int $lineTotal, int $quantity): int
    {
        if ($quantity === 0) {
            throw new ValidationError('Cannot derive a unit price for zero quantity.');
        }

        return Money::mulDiv($lineTotal, self::SCALE, $quantity);
    }

    /** "1,200", "1.5", "0.75": trailing zeros dropped. */
    public static function format(int $value): string
    {
        $digits = str_pad(ltrim((string) $value, '-'), self::DECIMALS + 1, '0', STR_PAD_LEFT);
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', substr($digits, 0, -self::DECIMALS)) ?? '';
        $fraction = rtrim(substr($digits, -self::DECIMALS), '0');

        return ($value < 0 ? '-' : '').$whole.($fraction !== '' ? ".{$fraction}" : '');
    }

    public static function formatWithUnit(int $value, string $unit): string
    {
        return trim(self::format($value).' '.$unit);
    }

    public static function toInputString(int $value): string
    {
        return str_replace(',', '', self::format($value));
    }

    /** Refuse a quantity with more decimals than its unit allows ("pcs" are whole). */
    public static function assertUnitDecimals(int $value, int $decimals, string $unit): void
    {
        if ($decimals < 0 || $decimals > self::DECIMALS) {
            throw new ValidationError("{$unit} cannot allow {$decimals} decimal places.", ['decimals' => $decimals, 'unit' => $unit]);
        }
        $step = 10 ** (self::DECIMALS - $decimals);
        if ($value % $step !== 0) {
            throw new ValidationError(
                $decimals === 0
                    ? "{$unit} is counted in whole numbers, and ".self::format($value).' is not one.'
                    : "{$unit} allows at most {$decimals} decimal place".($decimals === 1 ? '' : 's').', and '.self::format($value).' has more.',
                ['value' => $value, 'decimals' => $decimals, 'unit' => $unit],
            );
        }
    }

    /** A carton quantity in base units: 2 cartons of 24 is 48. Refuses anything that would need rounding into stock. */
    public static function toBase(int $entry, int $factorMilli): int
    {
        [$quotient, $remainder] = BigInteger::of($entry)->multipliedBy($factorMilli)->quotientAndRemainder(self::SCALE);
        if (! $remainder->isZero()) {
            throw new ValidationError(
                self::format($entry).' does not convert to an exact quantity in the base unit.',
                ['entry' => $entry, 'factorMilli' => $factorMilli],
            );
        }

        return self::fromBig($quotient);
    }

    /** "1 Carton 16 pcs" for 40 pcs in cartons of 24, or null without a second unit. */
    public static function formatAlt(int $value, string $unit, ?string $altUnit, ?int $factorMilli): ?string
    {
        if ($altUnit === null || $altUnit === '' || $factorMilli === null || $factorMilli === 0) {
            return null;
        }

        $magnitude = abs($value);
        $whole = (int) floor($magnitude / $factorMilli);
        $rest = $magnitude - $whole * $factorMilli;
        $sign = $value < 0 ? '-' : '';

        return $rest === 0
            ? "{$sign}{$whole} {$altUnit}"
            : "{$sign}{$whole} {$altUnit} ".self::formatWithUnit($rest, $unit);
    }

    /** Stock in both units, the way Tally shows it: "40 pcs (1 Carton 16 pcs)". */
    public static function formatInBothUnits(int $value, string $unit, ?string $altUnit, ?int $factorMilli): string
    {
        $base = self::formatWithUnit($value, $unit);
        $alt = self::formatAlt($value, $unit, $altUnit, $factorMilli);

        return $alt === null ? $base : "{$base} ({$alt})";
    }

    /** A carton price per base unit, for a return: 110.00 a carton of 24 is 4.58 a tin. */
    public static function perBaseUnitPrice(int $price, ?int $entryFactorMilli): int
    {
        return $entryFactorMilli === null ? $price : Money::mulDiv($price, self::SCALE, $entryFactorMilli);
    }

    private static function fromBig(BigInteger $value): int
    {
        if ($value->isGreaterThan(Money::MAX_SAFE) || $value->isLessThan(-Money::MAX_SAFE)) {
            throw new MoneyOverflowError('Quantity exceeds the safe integer range.', ['value' => (string) $value]);
        }

        return $value->toInt();
    }
}
