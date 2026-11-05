<?php

declare(strict_types=1);

/*
| Ported from the old tests/domain/rate.test.ts, case for case.
*/

use App\Domain\Errors\ValidationError;
use App\Domain\Rate;

describe('reading a percentage', function () {
    it('converts what a person types into basis points', function () {
        expect(Rate::parsePercent('12.5'))->toBe(1250)
            ->and(Rate::parsePercent('12'))->toBe(1200)
            ->and(Rate::parsePercent('0'))->toBe(0)
            ->and(Rate::parsePercent('100'))->toBe(10_000);
    });

    it('keeps rates that a float would get wrong', function () {
        expect(Rate::parsePercent('7.3'))->toBe(730)
            ->and(Rate::parsePercent('0.01'))->toBe(1)
            ->and(Rate::parsePercent('0.75'))->toBe(75);
    });

    it('ignores surrounding space', function () {
        expect(Rate::parsePercent('  15  '))->toBe(1500);
    });

    it('refuses anything that is not a plain percentage', function (string $input) {
        Rate::parsePercent($input);
    })->throws(ValidationError::class)->with(['', '  ', 'abc', '12%', '1.234', '-5', '+5', '1e2', '1,5']);

    it('refuses a rate above 100%', function () {
        expect(fn () => Rate::parsePercent('100.01'))->toThrow(ValidationError::class, 'above 100')
            ->and(fn () => Rate::parsePercent('150'))->toThrow(ValidationError::class, 'above 100');
    });

    it('refuses a negative rate outright rather than storing one', function () {
        // A negative tax rate would post a debit where a credit belongs.
        Rate::parsePercent('-12.5');
    })->throws(ValidationError::class);
});

describe('showing a percentage', function () {
    it('reads back the way it was entered', function () {
        expect(Rate::format(1250))->toBe('12.5')
            ->and(Rate::format(1200))->toBe('12')
            ->and(Rate::format(0))->toBe('0')
            ->and(Rate::format(75))->toBe('0.75')
            ->and(Rate::format(1))->toBe('0.01')
            ->and(Rate::format(10_000))->toBe('100');
    });

    it('refuses a fractional basis point, which cannot be stored', function () {
        Rate::format(12.5);
    })->throws(ValidationError::class);
});

it('returns exactly what went in, for every rate a shop could set', function () {
    // What the form shows must parse back to the same stored value, or a save
    // that changed nothing would change the tax rate.
    for ($bp = 0; $bp <= 10_000; $bp++) {
        if (Rate::parsePercent(Rate::format($bp)) !== $bp) {
            throw new RuntimeException("{$bp} did not round-trip");
        }
    }
    expect(true)->toBeTrue();
});
