<?php

declare(strict_types=1);

namespace App\Domain\Errors;

/** Something dated on or before the books lock. Reported as a conflict, as in the old app. */
final class PeriodLockedError extends ConflictError
{
    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    public function __construct(string $businessDate, string $lockedBefore)
    {
        parent::__construct(
            "Period locked: {$businessDate} is on or before the lock date {$lockedBefore}",
            ['businessDate' => $businessDate, 'lockedBefore' => $lockedBefore],
            'CONFLICT',
            'The books are closed up to '.self::forUser($lockedBefore).'. '
                .'You cannot record anything dated '.self::forUser($businessDate).'. '
                .'Use a later date, or ask the owner to move the lock.',
        );
    }

    private static function forUser(string $date): string
    {
        [$year, $month, $day] = explode('-', $date) + ['', '', ''];

        return $day.' '.(self::MONTHS[(int) $month - 1] ?? $month).' '.$year;
    }
}
