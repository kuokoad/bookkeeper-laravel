<?php

declare(strict_types=1);

namespace App\Domain;

use Brick\Math\BigInteger;

/**
 * The two places where the old app's JavaScript behaviour decides a figure
 * and PHP's built-ins would quietly differ.
 */
final class JsCompat
{
    /**
     * JavaScript's whitespace (what String.prototype.trim and \s strip):
     * ASCII spaces and controls plus NBSP, the Unicode space separators, the
     * line and paragraph separators and the BOM. PHP's trim() strips fewer
     * (no NBSP) and one more (NUL).
     */
    public const WHITESPACE = '[\t\n\x{0B}\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    public static function trim(string $text): string
    {
        return preg_replace('/^'.self::WHITESPACE.'+|'.self::WHITESPACE.'+$/uD', '', $text) ?? $text;
    }

    /**
     * String.prototype.localeCompare for codes made of [A-Z0-9_]: ICU puts
     * "_" before digits and digits before letters, where strcmp puts "_"
     * last (COVID_19 sorts before COVIDX). Outside that alphabet it is only
     * an approximation.
     */
    public static function compareCodes(string $a, string $b): int
    {
        return strcmp(strtr($a, '_', '!'), strtr($b, '_', '!'));
    }

    /**
     * Math.round: half towards +∞ (Math.round(-2.5) is -2; PHP's round()
     * gives -3). Used where the old reports rounded a float ratio.
     */
    public static function round(float $x): int
    {
        $floor = floor($x);

        return (int) ($x - $floor >= 0.5 ? $floor + 1 : $floor);
    }

    /**
     * Number.prototype.toFixed(digits), as the scaled integer: the n for
     * which n / 10^digits is closest to the EXACT binary value of $x, the
     * larger n on a tie, sign applied to the magnitude. sprintf() rounds ties
     * to even instead (0.125 → "0.12" where toFixed gives "0.13").
     */
    public static function toFixedScaled(float $x, int $digits): BigInteger
    {
        if ($x == 0.0) {
            return BigInteger::zero();
        }

        // The double, exactly: mantissa × 2^exponent.
        $bits = unpack('J', pack('E', abs($x)))[1];
        $exponentBits = ($bits >> 52) & 0x7FF;
        $fraction = $bits & 0xFFFFFFFFFFFFF;
        [$mantissa, $exponent] = $exponentBits === 0
            ? [$fraction, -1074]
            : [$fraction | (1 << 52), $exponentBits - 1075];

        $numerator = BigInteger::of($mantissa)->multipliedBy(BigInteger::ten()->power($digits));
        $denominator = BigInteger::one();
        if ($exponent >= 0) {
            $numerator = $numerator->multipliedBy(BigInteger::of(2)->power($exponent));
        } else {
            $denominator = BigInteger::of(2)->power(-$exponent);
        }

        // Half up on the magnitude: floor((2·num + den) / (2·den)).
        $n = $numerator->multipliedBy(2)->plus($denominator)->quotient($denominator->multipliedBy(2));

        return $x < 0 ? $n->negated() : $n;
    }
}
