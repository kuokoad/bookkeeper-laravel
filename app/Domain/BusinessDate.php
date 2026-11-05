<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Errors\ValidationError;

/**
 * Trading dates are 'YYYY-MM-DD' strings, never timestamps: a date with a
 * time zone attached can slip a day, and a sale at 23:47 on the closing day
 * belongs to that day. Arithmetic is on whole days with no clock and no zone.
 *
 * A port of the old src/domain/business-date.ts (civil-day numbering, so no
 * DateTime is involved at all).
 */
final class BusinessDate
{
    private const SHAPE = '/^(\d{4})-(\d{2})-(\d{2})$/D';

    public static function assert(string $date, string $label = 'date'): void
    {
        try {
            self::parse($date);
        } catch (ValidationError) {
            throw new ValidationError("The {$label} \"{$date}\" is not a real date (YYYY-MM-DD).", ['date' => $date]);
        }
    }

    public static function addDays(string $date, int|float $days): string
    {
        if (is_float($days)) {
            throw new ValidationError('Days must be a whole number.', ['days' => $days]);
        }

        return self::format(self::fromDayNumber(self::toDayNumber(self::parse($date)) + $days));
    }

    /** Whole days from the earlier date to the later; negative if they are the other way round. */
    public static function daysBetween(string $earlier, string $later): int
    {
        return self::toDayNumber(self::parse($later)) - self::toDayNumber(self::parse($earlier));
    }

    /** The day an invoice falls due: the sale date plus 0 to 365 days of terms. */
    public static function dueDateFor(string $businessDate, int|float $termsDays): string
    {
        if (is_float($termsDays) || $termsDays < 0 || $termsDays > 365) {
            throw new ValidationError('Payment terms must be between 0 and 365 days.', ['termsDays' => $termsDays]);
        }

        return self::addDays($businessDate, $termsDays);
    }

    /** Zero on the due date itself: due today is not yet overdue. */
    public static function daysOverdue(string $dueDate, string $asAt): int
    {
        return self::daysBetween($dueDate, $asAt);
    }

    /** @return array{int, int, int} year, month, day */
    private static function parse(string $date): array
    {
        if (preg_match(self::SHAPE, $date, $m) !== 1) {
            throw new ValidationError("\"{$date}\" is not a business date.", ['date' => $date]);
        }
        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($month < 1 || $month > 12) {
            throw new ValidationError("\"{$date}\" has no such month.", ['date' => $date]);
        }
        if ($day < 1 || $day > FinancialYear::daysInMonth($year, $month)) {
            throw new ValidationError("\"{$date}\" is not a real day.", ['date' => $date]);
        }

        return [$year, $month, $day];
    }

    /** @param  array{int, int, int}  $parts */
    private static function format(array $parts): string
    {
        return sprintf('%d-%02d-%02d', ...$parts);
    }

    /**
     * Days since 0000-03-01 (Howard Hinnant's days_from_civil).
     *
     * @param  array{int, int, int}  $parts
     */
    private static function toDayNumber(array $parts): int
    {
        [$year, $month, $day] = $parts;
        $y = $month <= 2 ? $year - 1 : $year;
        $era = self::floorDiv($y, 400);
        $yearOfEra = $y - $era * 400;
        $monthShifted = $month > 2 ? $month - 3 : $month + 9;
        $dayOfYear = intdiv(153 * $monthShifted + 2, 5) + $day - 1;
        $dayOfEra = $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear;

        return $era * 146_097 + $dayOfEra;
    }

    /** @return array{int, int, int} */
    private static function fromDayNumber(int $dayNumber): array
    {
        $era = self::floorDiv($dayNumber, 146_097);
        $dayOfEra = $dayNumber - $era * 146_097;
        $yearOfEra = intdiv($dayOfEra - intdiv($dayOfEra, 1460) + intdiv($dayOfEra, 36_524) - intdiv($dayOfEra, 146_096), 365);
        $year = $yearOfEra + $era * 400;
        $dayOfYear = $dayOfEra - (365 * $yearOfEra + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100));
        $monthShifted = intdiv(5 * $dayOfYear + 2, 153);
        $day = $dayOfYear - intdiv(153 * $monthShifted + 2, 5) + 1;
        $month = $monthShifted < 10 ? $monthShifted + 3 : $monthShifted - 9;

        return [$month <= 2 ? $year + 1 : $year, $month, $day];
    }

    /** Math.floor division, which intdiv is not for negative numbers. */
    private static function floorDiv(int $a, int $b): int
    {
        return (int) floor($a / $b);
    }
}
