<?php

declare(strict_types=1);

namespace App\Reports\Csv;

use App\Domain\Money;
use App\Domain\Quantity;

/**
 * CSV text. Money is a plain decimal with no symbol and no grouping, so a
 * spreadsheet sums the column without cleaning it. A port of the old
 * lib/csv.ts.
 */
final class Csv
{
    /** How many rows one download may contain. Beyond this, narrow the filter. */
    public const EXPORT_LIMIT = 5000;

    /**
     * One field. Text that a spreadsheet would run as a formula (=, +, -, @,
     * tab, CR first) gets a leading apostrophe; genuine numbers ("-704.85") do not.
     */
    public static function escapeField(string|int|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }
        $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        if (preg_match('/^[=+\-@\t\r]/', $text) === 1 && preg_match('/^-?\d+(\.\d+)?$/D', $text) !== 1) {
            $text = "'{$text}";
        }
        if (preg_match('/[",\n\r]/', $text) === 1) {
            return '"'.str_replace('"', '""', $text).'"';
        }

        return $text;
    }

    /**
     * CRLF and a BOM, so Excel on Windows opens it correctly, accents included.
     *
     * @param  list<string>  $headers
     * @param  list<list<string|int|bool|null>>  $rows
     */
    public static function toCsv(array $headers, array $rows): string
    {
        $lines = [implode(',', array_map(self::escapeField(...), $headers))];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(self::escapeField(...), $row));
        }

        return "\u{FEFF}".implode("\r\n", $lines)."\r\n";
    }

    public static function money(int $value): string
    {
        return Money::toDecimalString($value, false);
    }

    public static function qty(int $value): string
    {
        return str_replace(',', '', Quantity::format($value));
    }

    /** The same stock in the alternate unit ("1 Carton 16 pcs"), blank with none. */
    public static function altQty(int $value, string $unit, ?string $altUnit, ?int $factorMilli): string
    {
        $alt = Quantity::formatAlt($value, $unit, $altUnit, $factorMilli);

        return $alt === null ? '' : str_replace(',', '', $alt);
    }

    /** A safe, descriptive filename: bookkeeper_<report>_<from>[_<to>].csv */
    public static function filename(string $report, ?string $from = null, ?string $to = null): string
    {
        $parts = ['bookkeeper', strtolower(preg_replace('/[^a-z0-9]+/i', '-', $report) ?? $report)];
        if ($from !== null && $from !== '') {
            $parts[] = $from;
        }
        if ($to !== null && $to !== '' && $to !== $from) {
            $parts[] = $to;
        }

        return implode('_', $parts).'.csv';
    }

    /**
     * One string per query-string key. The old routes read the FIRST value of
     * a repeated key (searchParams.get); PHP has already kept only the LAST
     * one by the time this runs (?a=1&a=2 arrives as a=2), so a repeated key
     * takes the last value (README differences). Only an explicit array
     * (?a[]=1&a[]=2) is reduced here, to its first element.
     *
     * @return array<string, string>
     */
    public static function firstValues(array $query): array
    {
        $params = [];
        foreach ($query as $key => $value) {
            $value = is_array($value) ? ($value[0] ?? null) : $value;
            if (is_string($value)) {
                $params[(string) $key] = $value;
            }
        }

        return $params;
    }
}
