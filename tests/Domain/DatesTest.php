<?php

declare(strict_types=1);

/*
| Ported from the old tests/domain/business-date.test.ts and
| financial-year.test.ts, case for case.
*/

use App\Domain\BusinessDate;
use App\Domain\Errors\ValidationError;
use App\Domain\FinancialYear;

describe('adding days', function () {
    it('moves within a month, across month and year ends', function () {
        expect(BusinessDate::addDays('2026-08-10', 5))->toBe('2026-08-15')
            ->and(BusinessDate::addDays('2026-08-30', 5))->toBe('2026-09-04')
            ->and(BusinessDate::addDays('2026-12-20', 30))->toBe('2027-01-19')
            ->and(BusinessDate::addDays('2026-01-31', 1))->toBe('2026-02-01')
            ->and(BusinessDate::addDays('2026-04-30', 1))->toBe('2026-05-01');
    });

    it('gets February right in common, leap and century years', function () {
        expect(BusinessDate::addDays('2026-02-28', 1))->toBe('2026-03-01')
            ->and(BusinessDate::addDays('2028-02-28', 1))->toBe('2028-02-29')
            ->and(BusinessDate::addDays('2028-02-29', 1))->toBe('2028-03-01')
            ->and(BusinessDate::addDays('1900-02-28', 1))->toBe('1900-03-01')
            ->and(BusinessDate::addDays('2000-02-28', 1))->toBe('2000-02-29');
    });

    it('moves backwards with a negative number, and nowhere with zero', function () {
        expect(BusinessDate::addDays('2026-03-01', -1))->toBe('2026-02-28')
            ->and(BusinessDate::addDays('2026-01-01', -1))->toBe('2025-12-31')
            ->and(BusinessDate::addDays('2026-08-17', 0))->toBe('2026-08-17');
    });

    it('survives a long span', function () {
        $date = '2026-01-01';
        for ($month = 0; $month < 12; $month++) {
            $date = BusinessDate::addDays($date, 30);
        }
        expect($date)->toBe('2026-12-27');
    });
});

describe('counting days between', function () {
    it('counts forwards, backwards and across a leap day', function () {
        expect(BusinessDate::daysBetween('2026-08-01', '2026-08-31'))->toBe(30)
            ->and(BusinessDate::daysBetween('2026-08-17', '2026-08-17'))->toBe(0)
            ->and(BusinessDate::daysBetween('2026-08-31', '2026-08-01'))->toBe(-30)
            ->and(BusinessDate::daysBetween('2028-02-28', '2028-03-01'))->toBe(2)
            ->and(BusinessDate::daysBetween('2026-02-28', '2026-03-01'))->toBe(1);
    });

    it('is the exact inverse of adding', function () {
        foreach ([0, 1, 7, 30, 45, 365, 400] as $days) {
            expect(BusinessDate::daysBetween('2026-08-17', BusinessDate::addDays('2026-08-17', $days)))->toBe($days);
        }
    });
});

describe('when payment falls due', function () {
    it('is the sale date plus the terms; due on receipt means the same day', function () {
        expect(BusinessDate::dueDateFor('2026-08-17', 30))->toBe('2026-09-16')
            ->and(BusinessDate::dueDateFor('2026-08-17', 7))->toBe('2026-08-24')
            ->and(BusinessDate::dueDateFor('2026-08-17', 0))->toBe('2026-08-17');
    });

    it('refuses nonsense terms', function (int|float $terms) {
        BusinessDate::dueDateFor('2026-08-17', $terms);
    })->throws(ValidationError::class)->with([-1, 1.5, 366]);
});

it('measures how overdue something is', function () {
    expect(BusinessDate::daysOverdue('2026-08-17', '2026-08-17'))->toBe(0)
        ->and(BusinessDate::daysOverdue('2026-08-17', '2026-08-20'))->toBe(3)
        ->and(BusinessDate::daysOverdue('2026-09-16', '2026-08-17'))->toBe(-30);
});

describe('refusing what is not a date', function () {
    it('rejects the wrong shape and days that do not exist', function (string $bad) {
        BusinessDate::addDays($bad, 1);
    })->throws(ValidationError::class)->with(['17/08/2026', '2026-8-17', '', 'today', '2026-08', '2026-02-30', '2026-02-29', '2026-13-01', '2026-04-31']);

    it('accepts the 29th in a leap year', function () {
        expect(BusinessDate::addDays('2028-02-29', 1))->toBe('2028-03-01');
    });

    it('names the field when asserting', function () {
        BusinessDate::assert('2026-02-30', 'sale date');
    })->throws(ValidationError::class, 'The sale date "2026-02-30" is not a real date (YYYY-MM-DD).');
});

describe('financial years', function () {
    it('is the calendar year when it starts in January', function () {
        $year = FinancialYear::of(2025, 1);
        expect([$year->start, $year->end, $year->label])->toBe(['2025-01-01', '2025-12-31', '2025']);
    });

    it('runs to the day before the anniversary when it straddles two years', function () {
        $april = FinancialYear::of(2025, 4);
        expect([$april->start, $april->end, $april->label])->toBe(['2025-04-01', '2026-03-31', '2025/26'])
            ->and(FinancialYear::of(2025, 7)->end)->toBe('2026-06-30')
            ->and(FinancialYear::of(2025, 3)->end)->toBe('2026-02-28')
            ->and(FinancialYear::of(2027, 3)->end)->toBe('2028-02-29')
            ->and(FinancialYear::of(2099, 4)->label)->toBe('2099/00');
    });

    it('knows the length of every February that matters', function () {
        expect(FinancialYear::daysInMonth(2025, 2))->toBe(28)
            ->and(FinancialYear::daysInMonth(2024, 2))->toBe(29)
            ->and(FinancialYear::daysInMonth(1900, 2))->toBe(28)
            ->and(FinancialYear::daysInMonth(2000, 2))->toBe(29);
    });

    it('places dates either side of the start correctly', function () {
        expect(FinancialYear::for('2025-06-15', 1)->label)->toBe('2025')
            ->and(FinancialYear::for('2025-01-01', 1)->label)->toBe('2025')
            ->and(FinancialYear::for('2025-12-31', 1)->label)->toBe('2025')
            ->and(FinancialYear::for('2025-03-31', 4)->label)->toBe('2024/25')
            ->and(FinancialYear::for('2025-04-01', 4)->label)->toBe('2025/26')
            ->and(FinancialYear::for('2026-01-15', 4)->label)->toBe('2025/26');
    });

    it('never leaves a date homeless at a boundary', function () {
        $year = FinancialYear::of(2025, 4);
        foreach ([$year->start, '2025-12-31', '2026-01-01', $year->end] as $date) {
            expect(FinancialYear::for($date, 4)->label)->toBe($year->label)
                ->and($year->contains($date))->toBeTrue();
        }
        expect($year->contains('2025-03-31'))->toBeFalse()
            ->and($year->contains('2026-04-01'))->toBeFalse();
    });

    it('compares with the year immediately before, with no gap or overlap', function () {
        $year = FinancialYear::of(2025, 4);
        $previous = $year->previous(4);
        expect($previous->label)->toBe('2024/25')
            ->and($previous->end)->toBe('2025-03-31')
            ->and(FinancialYear::for($previous->end, 4)->label)->toBe($previous->label)
            ->and($previous->end < $year->start)->toBeTrue();
    });

    it('lists the years a shop has traded, newest first', function () {
        $labels = fn (array $years): array => array_map(fn (FinancialYear $y): string => $y->label, $years);
        expect($labels(FinancialYear::between('2023-05-02', '2025-11-30', 1)))->toBe(['2025', '2024', '2023'])
            ->and($labels(FinancialYear::between('2025-02-01', '2025-11-30', 1)))->toBe(['2025'])
            ->and($labels(FinancialYear::between('2025-04-01', '2026-03-31', 4)))->toBe(['2025/26']);
    });

    it('rejects a month outside 1-12', function (int|float $month) {
        FinancialYear::of(2025, $month);
    })->throws(ValidationError::class)->with([0, 13, -1, 1.5]);

    it('rejects a date that is not a business date', function (string $bad) {
        FinancialYear::for($bad, 1);
    })->throws(ValidationError::class)->with(['15/06/2025', '']);
});
