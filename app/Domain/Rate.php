<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Errors\ValidationError;

/**
 * Rates are basis points, for the same reason money is pesewas: 7.3% as a
 * float is not exact, and the error would be multiplied by every sale.
 * Owns both directions of the conversion. A port of the old src/domain/rate.ts.
 */
final class Rate
{
    public const SCALE = 10_000;

    public const PERCENT_DECIMALS = 2;

    private const INPUT = '/^(\d{1,3})(?:\.(\d{1,2}))?$/D';

    /** "12.5" → 1250. No % sign, no sign, at most two decimals, at most 100%. */
    public static function parsePercent(string $input): int
    {
        $text = JsCompat::trim($input);
        if ($text === '') {
            throw new ValidationError('Enter a percentage.');
        }
        if (preg_match(self::INPUT, $text, $match) !== 1) {
            throw new ValidationError("\"{$input}\" is not a percentage. Enter it like 12.5, without the % sign.", ['input' => $input]);
        }

        $basisPoints = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', self::PERCENT_DECIMALS, '0');
        if ($basisPoints > self::SCALE) {
            throw new ValidationError("A rate of {$input}% is above 100%.", ['input' => $input]);
        }

        return $basisPoints;
    }

    /** 1250 → "12.5", so the form shows what parsePercent() reads back unchanged. */
    public static function format(int|float $basisPoints): string
    {
        if (is_float($basisPoints)) {
            throw new ValidationError('A rate in basis points must be a whole number.', ['basisPoints' => $basisPoints]);
        }

        $whole = intdiv($basisPoints, 100);
        $fraction = abs($basisPoints % 100);
        if ($fraction === 0) {
            return (string) $whole;
        }

        return $whole.'.'.preg_replace('/0$/', '', str_pad((string) $fraction, 2, '0', STR_PAD_LEFT));
    }
}
