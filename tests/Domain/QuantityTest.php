<?php

declare(strict_types=1);

/*
| Ported from the old tests/domain/quantity.test.ts, case for case.
*/

use App\Domain\Errors\MoneyOverflowError;
use App\Domain\Errors\ValidationError;
use App\Domain\Money;
use App\Domain\Quantity;

describe('parse', function () {
    it('parses whole and fractional quantities', function () {
        expect(Quantity::parse('3'))->toBe(3000)
            ->and(Quantity::parse('1.5'))->toBe(1500)
            ->and(Quantity::parse('0.750'))->toBe(750)
            ->and(Quantity::parse('1,200'))->toBe(1_200_000);
    });

    it('pads decimals to milli-units correctly', function () {
        expect(Quantity::parse('1.5'))->toBe(1500)
            ->and(Quantity::parse('1.05'))->toBe(1050)
            ->and(Quantity::parse('1.005'))->toBe(1005);
    });

    it('rejects more than three decimal places', function () {
        Quantity::parse('1.0005');
    })->throws(ValidationError::class);

    it('rejects junk', function (string $bad) {
        Quantity::parse($bad);
    })->throws(ValidationError::class)->with(['', 'abc', '1.2.3', '--1']);

    it('parsePositive rejects zero and negatives', function () {
        expect(fn () => Quantity::parsePositive('0'))->toThrow(ValidationError::class)
            ->and(fn () => Quantity::parsePositive('-1'))->toThrow(ValidationError::class)
            ->and(Quantity::parsePositive('2'))->toBe(2000);
    });
});

it('fromUnits avoids float error', function () {
    expect(Quantity::fromUnits(0.1 + 0.2))->toBe(300)
        ->and(Quantity::fromUnits(3))->toBe(3000);
});

it('of rejects fractional milli-units and unsafe values', function () {
    expect(fn () => Quantity::of(1.5))->toThrow(ValidationError::class)
        ->and(fn () => Quantity::of(NAN))->toThrow(ValidationError::class)
        ->and(fn () => Quantity::of(Money::MAX_SAFE + 2))->toThrow(MoneyOverflowError::class);
});

describe('arithmetic', function () {
    it('adds and subtracts exactly', function () {
        expect(Quantity::add(1500, 1500))->toBe(3000)
            ->and(Quantity::subtract(3000, 1500))->toBe(1500)
            ->and(Quantity::sum([1000, 500, 250]))->toBe(1750)
            ->and(Quantity::sum([]))->toBe(0);
    });

    it('scales proportionally with half-away-from-zero rounding', function () {
        expect(Quantity::scale(1000, 1, 3))->toBe(333)
            ->and(Quantity::scale(1000, 1, 2))->toBe(500)
            ->and(Quantity::scale(5, 1, 2))->toBe(3);
    });
});

describe('extendPrice — the money/quantity bridge', function () {
    it('computes whole-unit line totals', function () {
        expect(Quantity::extendPrice(870, 3000))->toBe(2610);
    });

    it('computes fractional line totals', function () {
        expect(Quantity::extendPrice(1200, 1500))->toBe(1800)
            ->and(Quantity::extendPrice(1000, 750))->toBe(750);
    });

    it('rounds to the nearest pesewa', function () {
        expect(Quantity::extendPrice(100, 333))->toBe(33)
            ->and(Quantity::extendPrice(100, 335))->toBe(34);
    });

    it('is exact for a unit quantity', function () {
        expect(Quantity::extendPrice(12_345, Quantity::ONE))->toBe(12_345);
    });

    it('handles zero', function () {
        expect(Quantity::extendPrice(1000, 0))->toBe(0)
            ->and(Quantity::extendPrice(0, 5000))->toBe(0);
    });

    it('does not overflow on large but realistic line totals', function () {
        // 10,000 units at GHS 50,000.00 each
        expect(Quantity::extendPrice(5_000_000, 10_000_000))->toBe(50_000_000_000);
    });
});

describe('derivedUnitPrice', function () {
    it('inverts extendPrice for exact cases', function () {
        expect(Quantity::derivedUnitPrice(2610, 3000))->toBe(870);
    });

    it('rejects zero quantity', function () {
        Quantity::derivedUnitPrice(100, 0);
    })->throws(ValidationError::class);
});

describe('formatting', function () {
    it('trims trailing zeros', function () {
        expect(Quantity::format(3000))->toBe('3')
            ->and(Quantity::format(1500))->toBe('1.5')
            ->and(Quantity::format(750))->toBe('0.75')
            ->and(Quantity::format(1005))->toBe('1.005')
            ->and(Quantity::format(0))->toBe('0')
            ->and(Quantity::format(-1500))->toBe('-1.5')
            ->and(Quantity::format(1_200_000))->toBe('1,200');
    });

    it('appends a unit', function () {
        expect(Quantity::formatWithUnit(1500, 'kg'))->toBe('1.5 kg')
            ->and(Quantity::formatWithUnit(3000, ''))->toBe('3');
    });

    it('round-trips through the input representation', function () {
        foreach ([0, 750, 1500, 3000, 1_200_000] as $value) {
            expect(Quantity::parse(Quantity::toInputString($value)))->toBe($value);
        }
    });
});

describe('units of measure', function () {
    it('converts an alternate-unit quantity to the base unit exactly', function () {
        expect(Quantity::toBase(2_000, 24_000))->toBe(48_000)
            ->and(Quantity::toBase(500, 50_000))->toBe(25_000);
    });

    it('refuses a conversion that would need rounding into stock', function () {
        // 0.001 of a 0.5 kg tin is 0.0005 kg — not representable, so not guessed.
        Quantity::toBase(1, 500);
    })->throws(ValidationError::class);

    it('refuses a fraction of a unit counted in whole numbers', function () {
        expect(fn () => Quantity::assertUnitDecimals(1_500, 0, 'pcs'))->toThrow(ValidationError::class, 'whole numbers')
            ->and(fn () => Quantity::assertUnitDecimals(1_250, 1, 'kg'))->toThrow(ValidationError::class, '1 decimal place');
        Quantity::assertUnitDecimals(1_250, 2, 'kg');
        Quantity::assertUnitDecimals(2_000, 0, 'pcs');
    });

    it('shows stock in both units, the way Tally does', function () {
        expect(Quantity::formatInBothUnits(40_000, 'pcs', 'Carton', 24_000))->toBe('40 pcs (1 Carton 16 pcs)')
            ->and(Quantity::formatInBothUnits(48_000, 'pcs', 'Carton', 24_000))->toBe('48 pcs (2 Carton)')
            ->and(Quantity::formatInBothUnits(21_000, 'pcs', 'Carton', 24_000))->toBe('21 pcs (0 Carton 21 pcs)')
            ->and(Quantity::formatInBothUnits(-30_000, 'pcs', 'Carton', 24_000))->toBe('-30 pcs (-1 Carton 6 pcs)')
            ->and(Quantity::formatInBothUnits(40_000, 'pcs', null, null))->toBe('40 pcs');
    });

    it('prices a carton line per piece for a return', function () {
        expect(Quantity::perBaseUnitPrice(11_000, 24_000))->toBe(458)
            ->and(Quantity::perBaseUnitPrice(500, null))->toBe(500);
    });

    it('needs the carton size on a carton line', function () {
        expect(Quantity::parseAltEntry(null, null, 'Line 1'))->toBe([])
            ->and(Quantity::parseAltEntry(true, 24_000, 'Line 1'))->toBe(['alt' => true, 'altFactor' => 24_000])
            ->and(fn () => Quantity::parseAltEntry(true, null, 'Line 1'))->toThrow(ValidationError::class, 'does not say its size');
    });
});
