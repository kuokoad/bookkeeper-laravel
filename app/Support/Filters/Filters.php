<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Domain\BusinessDate;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\ValidationError;
use App\Domain\JsCompat;
use App\Domain\Money;
use App\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Filter parsing, in one place: a query string becomes VALIDATED values and
 * nothing here touches the database. Junk becomes null, never an exception
 * and never a raw string passed onwards. A port of the parts of the old
 * lib/filters.ts the product and stock lists use; the rest arrives with the
 * modules that need it.
 */
final class Filters
{
    public const DATE_PRESETS = ['today', 'yesterday', 'week', 'last-week', 'month', 'last-month', 'year', 'all', 'custom'];

    /** The earliest date the shop can hold. Cheaper than a NULL branch everywhere. */
    public const EARLIEST_DATE = '0000-01-01';

    public const DEFAULT_PAGE_SIZE = 50;

    public const MAX_PAGE_SIZE = 200;

    /** The most rows a list service returns in one call: one more than a CSV holds (Csv::EXPORT_LIMIT), so the file can tell it stopped short. */
    public const MAX_LIST_ROWS = 5_001;

    public static function isValidBusinessDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        try {
            BusinessDate::assert($value);

            return true;
        } catch (ValidationError) {
            return false;
        }
    }

    /**
     * A preset as a real range of business days, inclusive at both ends. The
     * week starts on Monday. Anything unrecognised falls back to this month.
     *
     * @return array{range: array{from: string, to: string}, preset: string}
     */
    public static function resolveDateRange(?string $preset, ?string $from, ?string $to, ?string $today = null): array
    {
        $today ??= Clock::today();
        $now = new DateTimeImmutable($today, new DateTimeZone('UTC'));
        $iso = fn (DateTimeImmutable $d): string => $d->format('Y-m-d');
        $monday = $now->modify('-'.((int) $now->format('N') - 1).' days');

        switch ($preset) {
            case 'today':
                return ['range' => ['from' => $today, 'to' => $today], 'preset' => 'today'];
            case 'yesterday':
                $yesterday = BusinessDate::addDays($today, -1);

                return ['range' => ['from' => $yesterday, 'to' => $yesterday], 'preset' => 'yesterday'];
            case 'week':
                return ['range' => ['from' => $iso($monday), 'to' => $today], 'preset' => 'week'];
            case 'last-week':
                return ['range' => ['from' => $iso($monday->modify('-7 days')), 'to' => $iso($monday->modify('-1 day'))], 'preset' => 'last-week'];
            case 'last-month':
                return ['range' => ['from' => $iso($now->modify('first day of last month')), 'to' => $iso($now->modify('last day of last month'))], 'preset' => 'last-month'];
            case 'year':
                return ['range' => ['from' => substr($today, 0, 4).'-01-01', 'to' => $today], 'preset' => 'year'];
            case 'all':
                return ['range' => ['from' => self::EARLIEST_DATE, 'to' => $today], 'preset' => 'all'];
            case 'custom':
                // One end given means "from then onwards" or "up to then".
                $start = $from !== null && self::isValidBusinessDate($from) ? $from : null;
                $end = $to !== null && self::isValidBusinessDate($to) ? $to : null;
                if ($start !== null || $end !== null) {
                    // Ends entered the wrong way round are a slip: swapping shows what they meant.
                    [$low, $high] = $start !== null && $end !== null && $start > $end
                        ? [$end, $start]
                        : [$start ?? self::EARLIEST_DATE, $end ?? $today];

                    return ['range' => ['from' => $low, 'to' => $high], 'preset' => 'custom'];
                }
                break;
        }

        return ['range' => ['from' => substr($today, 0, 7).'-01', 'to' => $today], 'preset' => 'month'];
    }

    /** @param  array{from: string, to: string}  $range  Human description of a range, for a report header. */
    public static function describeDateRange(array $range, string $preset, string $today): string
    {
        return match (true) {
            $preset === 'all' => "Everything up to {$range['to']}",
            $range['from'] === $range['to'] => $range['from'],
            $range['from'] === self::EARLIEST_DATE => "Up to {$range['to']}",
            $range['to'] === $today && $preset !== 'custom' => "{$range['from']} to today",
            default => "{$range['from']} to {$range['to']}",
        };
    }

    /** A positive integer id, or null. Rejects 0, negatives and junk. */
    public static function parseId(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = self::jsNumber($value);

        return $parsed !== null && $parsed > 0 ? $parsed : null;
    }

    /**
     * One of a fixed set, or null. The only way an enum filter reaches SQL.
     *
     * @param  list<string>  $allowed
     */
    public static function parseEnum(?string $value, array $allowed): ?string
    {
        return $value !== null && in_array($value, $allowed, true) ? $value : null;
    }

    public static function parseDate(?string $value): ?string
    {
        return $value !== null && self::isValidBusinessDate($value) ? $value : null;
    }

    /**
     * An amount bound, or null. A negative bound is dropped, not applied:
     * nothing these boxes filter is held negative, so it is a filter nobody meant.
     */
    public static function parseAmount(?string $value): ?int
    {
        if ($value === null || JsCompat::trim($value) === '') {
            return null;
        }
        try {
            $parsed = Money::parse($value);
        } catch (DomainError) {
            return null;
        }

        return $parsed < 0 ? null : $parsed;
    }

    /**
     * Both ends optional, put back in order if reversed.
     *
     * @return array{minAmount?: int, maxAmount?: int}
     */
    public static function parseAmountRange(?string $min, ?string $max): array
    {
        $low = self::parseAmount($min);
        $high = self::parseAmount($max);
        if ($low !== null && $high !== null && $low > $high) {
            return ['minAmount' => $high, 'maxAmount' => $low];
        }

        return array_filter(['minAmount' => $low, 'maxAmount' => $high], fn (?int $v): bool => $v !== null);
    }

    /** A search term, trimmed and capped. Empty and whitespace become null. */
    public static function parseSearch(?string $value, int $maxLength = 100): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = mb_substr(JsCompat::trim($value), 0, $maxLength);

        return $trimmed === '' ? null : $trimmed;
    }

    /** @return array{page: int, pageSize: int, offset: int} */
    public static function parsePage(?string $value, int $pageSize = self::DEFAULT_PAGE_SIZE): array
    {
        $parsed = $value === null ? 1 : self::jsNumber($value);
        $page = $parsed !== null && $parsed >= 1 ? $parsed : 1;
        $size = min(max(1, $pageSize), self::MAX_PAGE_SIZE);

        return ['page' => $page, 'pageSize' => $size, 'offset' => ($page - 1) * $size];
    }

    /** The page a result set can actually show: past the end is the last page. */
    public static function clampPage(int $page, int $total, int $pageSize): int
    {
        return min($page, max(1, (int) ceil($total / $pageSize)));
    }

    /**
     * A sort column and direction, both whitelisted: a sort key reaches an
     * ORDER BY, so it can never be a string off the wire.
     *
     * @param  list<string>  $allowed
     * @return array{sort: string, direction: string}
     */
    public static function parseSort(?string $sort, ?string $direction, array $allowed, string $fallback, string $fallbackDirection = 'desc'): array
    {
        return [
            'sort' => self::parseEnum($sort, $allowed) ?? $fallback,
            'direction' => self::parseEnum($direction, ['asc', 'desc']) ?? $fallbackDirection,
        ];
    }

    /**
     * What JavaScript's Number() makes of the text, when that is a safe
     * integer ("7", " 7 ", "7.0", "1e3", "0x10"); null otherwise. Empty or
     * blank text is 0, as Number() reads it.
     */
    private static function jsNumber(string $value): ?int
    {
        $text = JsCompat::trim($value);
        if ($text === '') {
            return 0;
        }
        if (preg_match('/^0([xob])([0-9a-f]+)$/iD', $text, $m) === 1) {
            $base = ['x' => 16, 'o' => 8, 'b' => 2][strtolower($m[1])];
            if (preg_match($base === 16 ? '/^[0-9a-f]+$/iD' : ($base === 8 ? '/^[0-7]+$/D' : '/^[01]+$/D'), $m[2]) !== 1) {
                return null;
            }
            $number = (float) base_convert($m[2], $base, 10);
        } elseif (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?$/iD', $text) === 1) {
            $number = (float) $text;
        } else {
            return null;
        }

        return floor($number) === $number && abs($number) <= 9_007_199_254_740_991 ? (int) $number : null;
    }
}
