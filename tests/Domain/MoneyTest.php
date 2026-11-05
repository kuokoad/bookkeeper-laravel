<?php

declare(strict_types=1);

/*
| Ported from the old tests/domain/money.test.ts, case for case.
*/

use App\Domain\Errors\MoneyOverflowError;
use App\Domain\Errors\ValidationError;
use App\Domain\Money;

describe('parse', function () {
    it('parses plain and grouped amounts', function () {
        expect(Money::parse('1250'))->toBe(125_000)
            ->and(Money::parse('1250.50'))->toBe(125_050)
            ->and(Money::parse('1,250.50'))->toBe(125_050)
            ->and(Money::parse('0.05'))->toBe(5)
            ->and(Money::parse('0'))->toBe(0);
    });

    it('pads a single decimal place correctly', function () {
        // The classic bug: "1250.5" must be 1250.50, not 1250.05.
        expect(Money::parse('1250.5'))->toBe(125_050);
    });

    it('strips currency codes and symbols', function () {
        expect(Money::parse('GHS 1,250.50'))->toBe(125_050)
            ->and(Money::parse(' ₵80.00 '))->toBe(8_000);
    });

    it('handles negatives in both notations', function () {
        expect(Money::parse('-50.00'))->toBe(-5_000)
            ->and(Money::parse('(50.00)'))->toBe(-5_000);
    });

    it('rejects more than two decimal places instead of truncating', function () {
        Money::parse('10.005');
    })->throws(ValidationError::class);

    it('rejects junk input', function (string $bad) {
        // "--1" is the important one: a double negative must never become positive.
        Money::parse($bad);
    })->throws(ValidationError::class)->with(['', '   ', 'abc', '1.2.3', '10-', '1,25,0.00', '--1', '(-1)', '-']);
});

describe('fromMajor', function () {
    it('does not inherit binary floating point error', function () {
        // 0.1 + 0.2 === 0.30000000000000004 in IEEE 754.
        expect(Money::fromMajor(0.1 + 0.2))->toBe(30)
            ->and(Money::fromMajor(8.7))->toBe(870)
            ->and(Money::fromMajor(-12.34))->toBe(-1234);
    });

    it('cannot recover precision the caller already lost', function () {
        // The literal 1.005 is 1.00499999999999989 as a double, so 1.00 is the honest result.
        expect(Money::fromMajor(1.005))->toBe(100);
        // The string path refuses the ambiguity outright instead of guessing.
        expect(fn () => Money::parse('1.005'))->toThrow(ValidationError::class);
    });
});

describe('of', function () {
    it('rejects fractional and unsafe values', function () {
        expect(fn () => Money::of(12.5))->toThrow(ValidationError::class)
            ->and(fn () => Money::of(NAN))->toThrow(ValidationError::class)
            ->and(fn () => Money::of(INF))->toThrow(ValidationError::class)
            ->and(fn () => Money::of(Money::MAX_SAFE + 2))->toThrow(MoneyOverflowError::class);
    });
});

describe('arithmetic', function () {
    it('adds, subtracts, negates and sums exactly', function () {
        expect(Money::add(125_000, 5_050))->toBe(130_050)
            ->and(Money::subtract(125_000, 5_050))->toBe(119_950)
            ->and(Money::negate(125_000))->toBe(-125_000)
            ->and(Money::sum([10, 20, 30]))->toBe(60)
            ->and(Money::sum([]))->toBe(0);
    });

    it('accumulates a thousand additions of 0.01 without drift', function () {
        $total = 0;
        for ($i = 0; $i < 1000; $i++) {
            $total = Money::add($total, 1);
        }
        expect($total)->toBe(1000);
    });

    it('throws rather than overflowing', function () {
        Money::multiply(Money::MAX_SAFE - 1, 1000);
    })->throws(MoneyOverflowError::class);

    it('clamps to zero', function () {
        expect(Money::atLeastZero(-500))->toBe(0)
            ->and(Money::atLeastZero(500))->toBe(500);
    });
});

describe('mulDiv rounding', function () {
    it('rounds half away from zero', function () {
        expect(Money::mulDiv(5, 1, 2))->toBe(3)
            ->and(Money::mulDiv(-5, 1, 2))->toBe(-3)
            ->and(Money::mulDiv(7, 1, 2))->toBe(4)
            ->and(Money::mulDiv(4, 1, 2))->toBe(2);
    });

    it('survives products larger than the safe range', function () {
        expect(Money::mulDiv(10_000_000_000, 1_000_000, 1_000_000))->toBe(10_000_000_000);
    });

    it('rejects division by zero', function () {
        Money::mulDiv(100, 1, 0);
    })->throws(ValidationError::class);
});

describe('percentOf', function () {
    it('computes basis-point rates', function () {
        expect(Money::percentOf(125_000, 1250))->toBe(15_625)
            ->and(Money::percentOf(10_000, Money::BASIS_POINTS))->toBe(10_000)
            ->and(Money::percentOf(10_000, 0))->toBe(0);
    });

    it('requires whole basis points', function () {
        Money::percentOf(100, 12.5);
    })->throws(ValidationError::class);
});

describe('allocate', function () {
    it('never loses or invents a pesewa', function () {
        // GHS 1.00 across three lines cannot divide evenly.
        $parts = Money::allocate(100, [1, 1, 1]);
        expect(Money::sum($parts))->toBe(100)->and($parts)->toBe([34, 33, 33]);
    });

    it('weights proportionally', function () {
        expect(Money::allocate(1000, [50, 30, 20]))->toBe([500, 300, 200]);
    });

    it('conserves the total across many awkward splits', function () {
        for ($total = 1; $total <= 200; $total++) {
            foreach ([[1, 1, 1], [7, 11, 13], [1, 0, 5], [3]] as $weights) {
                expect(Money::sum(Money::allocate($total, $weights)))->toBe($total);
            }
        }
    });

    it('conserves negative totals too (used when reversing a transaction)', function () {
        expect(Money::sum(Money::allocate(-100, [1, 1, 1])))->toBe(-100);
    });

    it('splits evenly when all weights are zero', function () {
        expect(Money::sum(Money::allocate(10, [0, 0, 0, 0])))->toBe(10);
    });

    it('rejects empty or negative weights', function () {
        expect(fn () => Money::allocate(100, []))->toThrow(ValidationError::class)
            ->and(fn () => Money::allocate(100, [1, -1]))->toThrow(ValidationError::class);
    });

    it('matches the old floating-point scaling for every realistic weight (PLAN Q4)', function () {
        // The old code computed Math.round(w * 1e6) in doubles. Below 2^53 / 1e6
        // that is exact, so integer scaling gives the same split.
        $limit = intdiv(Money::MAX_SAFE, 1_000_000);
        foreach ([1, 999, 123_456_789, $limit] as $weight) {
            expect((int) round($weight * 1_000_000))->toBe($weight * 1_000_000);
        }
        expect(Money::allocate(1_000_001, [$limit, 1, 7]))->toHaveCount(3);
    });
});

describe('formatting', function () {
    it('formats with grouping and two decimals', function () {
        expect(Money::format(125_000))->toBe('GHS 1,250.00')
            ->and(Money::format(5))->toBe('GHS 0.05')
            ->and(Money::format(0))->toBe('GHS 0.00')
            ->and(Money::format(-125_000))->toBe('GHS -1,250.00')
            ->and(Money::format(123_456_789))->toBe('GHS 1,234,567.89');
    });

    it('supports a different currency without code changes', function () {
        expect(Money::format(125_000, 'NGN'))->toBe('NGN 1,250.00');
    });

    it('round-trips through the input representation', function () {
        foreach ([0, 5, 100, 125_050, -999_999] as $value) {
            expect(Money::parse(Money::toInputString($value)))->toBe($value);
        }
    });

    it('omits grouping when asked', function () {
        expect(Money::toDecimalString(125_000, false))->toBe('1250.00');
    });
});
