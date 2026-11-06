<?php

declare(strict_types=1);

/*
| The books lock and which day a sale may carry. The old app tested these
| through its services (period-lock.test.ts, sale-date-action.test.ts); the
| rules themselves are checked here.
*/

use App\Domain\Accounting\PeriodLock;
use App\Domain\Errors\PeriodLockedError;
use App\Domain\Errors\ValidationError;
use App\Domain\Sales\SaleDate;

it('closes the lock date itself, and everything before it', function () {
    expect(PeriodLock::isOpen('2026-07-31', '2026-07-31'))->toBeFalse()
        ->and(PeriodLock::isOpen('2026-07-01', '2026-07-31'))->toBeFalse()
        ->and(PeriodLock::isOpen('2026-08-01', '2026-07-31'))->toBeTrue()
        ->and(PeriodLock::isOpen('1999-01-01', null))->toBeTrue();
});

it('tells the shop owner what to do, in their own date format', function () {
    PeriodLock::assertOpen('2026-07-15', '2026-07-31');
})->throws(PeriodLockedError::class);

it('words the refusal for a person', function () {
    $refuse = fn () => PeriodLock::assertOpen('2026-07-15', '2026-07-31');

    expect($refuse)->toThrow(PeriodLockedError::class, 'Period locked: 2026-07-15 is on or before the lock date 2026-07-31')
        ->and($refuse)->toThrow(fn (PeriodLockedError $e) => expect($e->userMessage)->toBe('The books are closed up to 31 Jul 2026. You cannot record anything dated 15 Jul 2026. Use a later date, or ask the owner to move the lock.')
        ->and($e->errorCode)->toBe('CONFLICT'));
});

it('lets year-end close and reopen through with an override', function () {
    PeriodLock::assertOpen('2026-07-15', '2026-07-31', allowOverride: true);
    expect(true)->toBeTrue();
});

it('treats moving the lock back, or clearing it, as reopening', function () {
    expect(PeriodLock::isRelaxation(null, '2026-07-31'))->toBeFalse()
        ->and(PeriodLock::isRelaxation('2026-07-31', '2026-08-31'))->toBeFalse()
        ->and(PeriodLock::isRelaxation('2026-07-31', '2026-07-31'))->toBeFalse()
        ->and(PeriodLock::isRelaxation('2026-07-31', '2026-06-30'))->toBeTrue()
        ->and(PeriodLock::isRelaxation('2026-07-31', null))->toBeTrue();
});

describe('the day a sale is dated', function () {
    it('is always today for anyone', function () {
        SaleDate::assertAllowed('2026-11-05', '2026-11-05', '2026-12-31', false);
        expect(true)->toBeTrue();
    });

    it('is never in the future, even for an owner', function () {
        SaleDate::assertAllowed('2026-11-06', '2026-11-05', null, true);
    })->throws(ValidationError::class, 'A sale cannot be dated in the future.');

    it('needs the right to backdate', function () {
        SaleDate::assertAllowed('2026-11-04', '2026-11-05', null, false);
    })->throws(ValidationError::class, 'Only an owner or manager can record a sale on an earlier day.');

    it('may be earlier for someone trusted, outside the lock', function () {
        SaleDate::assertAllowed('2026-11-01', '2026-11-05', '2026-10-31', true);
        expect(true)->toBeTrue();
    });

    it('may not be earlier into the lock', function () {
        SaleDate::assertAllowed('2026-10-31', '2026-11-05', '2026-10-31', true);
    })->throws(PeriodLockedError::class);
});
