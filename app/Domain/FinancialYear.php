<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Errors\ValidationError;

/**
 * A financial year that starts on the first of any month. All arithmetic is
 * on 'YYYY-MM-DD' strings: a DateTime carries a zone, and parsing
 * "2025-12-31" west of UTC yields the 30th, which would file a year-end sale
 * in the wrong year. A port of the old src/domain/financial-year.ts.
 */
final readonly class FinancialYear
{
    private const SHAPE = '/^(\d{4})-(\d{2})-(\d{2})$/D';

    public function __construct(
        public string $start,
        public string $end,
        public string $label,
        public int $startYear,
    ) {}

    public static function daysInMonth(int $year, int $month): int
    {
        if ($month === 2) {
            $leap = ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;

            return $leap ? 29 : 28;
        }

        return in_array($month, [4, 6, 9, 11], true) ? 30 : 31;
    }

    /** The year starting on the 1st of $startMonth in $startYear: "2025", or "2025/26" when it straddles. */
    public static function of(int $startYear, int|float $startMonth): self
    {
        $startMonth = self::assertStartMonth($startMonth);
        if ($startYear < 1900 || $startYear > 9999) {
            throw new ValidationError("\"{$startYear}\" is not a year.", ['startYear' => $startYear]);
        }

        $endMonth = $startMonth === 1 ? 12 : $startMonth - 1;
        $endYear = $startMonth === 1 ? $startYear : $startYear + 1;

        return new self(
            sprintf('%d-%02d-01', $startYear, $startMonth),
            sprintf('%d-%02d-%02d', $endYear, $endMonth, self::daysInMonth($endYear, $endMonth)),
            $startMonth === 1 ? (string) $startYear : sprintf('%d/%02d', $startYear, ($startYear + 1) % 100),
            $startYear,
        );
    }

    /** The year a trading date belongs to. */
    public static function for(string $date, int|float $startMonth): self
    {
        $startMonth = self::assertStartMonth($startMonth);
        if (preg_match(self::SHAPE, $date, $m) !== 1) {
            throw new ValidationError("\"{$date}\" is not a business date.", ['date' => $date]);
        }
        [$year, $month] = [(int) $m[1], (int) $m[2]];

        return self::of($month >= $startMonth ? $year : $year - 1, $startMonth);
    }

    public function previous(int $startMonth): self
    {
        return self::of($this->startYear - 1, $startMonth);
    }

    /**
     * Every year from the one holding $earliest to the one holding $latest, newest first.
     *
     * @return list<self>
     */
    public static function between(string $earliest, string $latest, int|float $startMonth): array
    {
        $startMonth = self::assertStartMonth($startMonth);
        $first = self::for($earliest, $startMonth);
        $last = self::for($latest, $startMonth);

        $years = [];
        for ($startYear = $last->startYear; $startYear >= $first->startYear; $startYear--) {
            $years[] = self::of($startYear, $startMonth);
        }

        return $years;
    }

    /** Inclusive at both ends. */
    public function contains(string $date): bool
    {
        return $date >= $this->start && $date <= $this->end;
    }

    private static function assertStartMonth(int|float $startMonth): int
    {
        if (is_float($startMonth) || $startMonth < 1 || $startMonth > 12) {
            throw new ValidationError('The financial year must start in a month from 1 to 12.', ['startMonth' => $startMonth]);
        }

        return $startMonth;
    }
}
