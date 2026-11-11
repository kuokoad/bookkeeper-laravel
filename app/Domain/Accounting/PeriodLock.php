<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\BusinessDate;
use App\Domain\Errors\PeriodLockedError;

/**
 * The books lock. Once a period has been reviewed, nothing may be dated into
 * it: a sale backdated into last month would quietly rewrite last month's
 * profit and this month's opening position.
 *
 * Not an accounting close: nothing is zeroed. Corrections stay possible the
 * proper way, by a reversal dated today. A port of the old
 * domain/accounting/period-lock.ts.
 */
final class PeriodLock
{
    /**
     * The lock is inclusive: locking 2026-07-31 closes everything up to and
     * including that day, so the owner can say "the end of July".
     */
    public static function isOpen(string $businessDate, ?string $lockedBefore): bool
    {
        return $lockedBefore === null || $businessDate > $lockedBefore;
    }

    public static function assertOpen(string $businessDate, ?string $lockedBefore, bool $allowOverride = false): void
    {
        // Dates compare as text, so "2026-1-5" would sort after "2026-06-30" and slip into a locked period.
        BusinessDate::assert($businessDate);
        if ($allowOverride || self::isOpen($businessDate, $lockedBefore)) {
            return;
        }

        throw new PeriodLockedError($businessDate, (string) $lockedBefore);
    }

    /** Moving the lock forward is routine; moving it back or clearing it reopens a closed period. */
    public static function isRelaxation(?string $current, ?string $next): bool
    {
        if ($current === null) {
            return false;
        }

        return $next === null || $next < $current;
    }
}
