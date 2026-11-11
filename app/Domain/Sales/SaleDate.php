<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Accounting\PeriodLock;
use App\Domain\BusinessDate;
use App\Domain\Errors\ValidationError;

/**
 * Which day a sale may be recorded on: today, always; an earlier day only for
 * somebody trusted to backdate (sales edit), and never inside the books lock;
 * never a future day. Backdating is the quiet way to move a sale out of the
 * period somebody is looking at, so the decision is made before it is
 * written. A port of the old domain/sales/sale-date.ts.
 */
final class SaleDate
{
    public static function assertAllowed(string $businessDate, string $today, ?string $lockedBefore, bool $mayBackdate): void
    {
        // Before any comparison: they are on text, and the till's date comes from the browser.
        BusinessDate::assert($businessDate, 'sale date');
        if ($businessDate > $today) {
            throw new ValidationError('A sale cannot be dated in the future.');
        }
        if ($businessDate === $today) {
            return;
        }
        if (! $mayBackdate) {
            throw new ValidationError('Only an owner or manager can record a sale on an earlier day.');
        }

        PeriodLock::assertOpen($businessDate, $lockedBefore);
    }
}
