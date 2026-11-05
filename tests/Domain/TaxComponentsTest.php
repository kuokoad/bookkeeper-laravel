<?php

declare(strict_types=1);

/*
| Ported from the old tests/domain/tax-components.test.ts, case for case,
| including the sweeps over every pesewa from -5,000 to 5,000.
*/

use App\Domain\Errors\ValidationError;
use App\Domain\Money;
use App\Domain\Tax\TaxComponent;
use App\Domain\Tax\TaxComponents;
use App\Domain\Tax\TaxLine;
use App\Enums\TaxBasis;

/** What a Ghanaian shop charges: two levies and VAT, on the net. */
function ghana(): array
{
    return [
        new TaxComponent('NHIL', 'NHIL', 250, TaxBasis::Net),
        new TaxComponent('GETFUND', 'GETFund', 250, TaxBasis::Net),
        new TaxComponent('VAT', 'VAT', 1_500, TaxBasis::Net, true),
    ];
}

/** The GRA's own computation: VAT on the net plus the levies. */
function ghanaGra(): array
{
    return [
        new TaxComponent('NHIL', 'NHIL', 250, TaxBasis::Net),
        new TaxComponent('GETFUND', 'GETFund', 250, TaxBasis::Net),
        new TaxComponent('VAT', 'VAT', 1_500, TaxBasis::NetPlusLevies, true),
    ];
}

function oddRates(): array
{
    return [
        new TaxComponent('A', 'A', 333, TaxBasis::Net),
        new TaxComponent('B', 'B', 667, TaxBasis::NetPlusLevies),
        new TaxComponent('C', 'C', 1, TaxBasis::NetPlusLevies, true),
    ];
}

/** @return array<string, list<TaxComponent>> */
function rateSets(): array
{
    return [
        'ghana flat' => ghana(),
        'ghana as the GRA computes it' => ghanaGra(),
        'a single tax' => [new TaxComponent('T', 'T', 1_750, TaxBasis::Net, true)],
        'rates that do not divide' => oddRates(),
    ];
}

/** Amounts that do not divide cleanly by any of the three rates. */
const AWKWARD = [1, 3, 7, 33, 99, 101, 333, 1_007, 4_999, 12_345, 99_999, 1_234_567];

/** @param  list<TaxLine>  $lines */
function codesAndAmounts(array $lines): array
{
    return array_map(fn (TaxLine $l): array => [$l->component->code, $l->amount], $lines);
}

function lineSum(array $lines): int
{
    return Money::sum(array_map(fn (TaxLine $l): int => $l->amount, $lines));
}

describe('charging tax on top of the price', function () {
    it('splits GHS 100.00 the way the receipt should read', function () {
        $result = TaxComponents::onNet(10_000, ghana());

        expect(codesAndAmounts($result->lines))->toBe([['NHIL', 250], ['GETFUND', 250], ['VAT', 1_500]])
            ->and($result->total)->toBe(2_000)
            ->and($result->gross)->toBe(12_000);
    });

    it('is 20% altogether', function () {
        expect(TaxComponents::totalRateBp(ghana()))->toBe(2_000);
    });

    it('charges nothing when there are no components', function () {
        $result = TaxComponents::onNet(10_000, []);
        expect($result->total)->toBe(0)->and($result->gross)->toBe(10_000);
    });
});

it('works back out of a GHS 120.00 shelf price', function () {
    $result = TaxComponents::withinGross(12_000, ghana());

    expect([$result->net, $result->total, $result->gross])->toBe([10_000, 2_000, 12_000])
        ->and($result->amounts())->toBe([250, 250, 1_500]);
});

describe('the parts always add back to the whole', function () {
    it('when tax is added on, and when it is inside the price', function () {
        foreach ([ghana(), oddRates()] as $components) {
            foreach (AWKWARD as $value) {
                $added = TaxComponents::onNet($value, $components);
                expect(lineSum($added->lines))->toBe($added->total)
                    ->and($added->gross)->toBe($value + $added->total);

                $within = TaxComponents::withinGross($value, $components);
                expect(lineSum($within->lines))->toBe($within->total)
                    ->and($within->net + $within->total)->toBe($value);
            }
        }
    });
});

describe('giving tax back on a return', function () {
    beforeEach(function () {
        $this->charged = TaxComponents::onNet(40_000, ghana())->lines;
    });

    it('hands back a quarter when a quarter goes back', function () {
        $share = TaxComponents::shareOf($this->charged, 10_000, 40_000);
        expect(array_map(fn ($l) => $l->amount, $share))->toBe([250, 250, 1_500])
            ->and(lineSum($share))->toBe(2_000);
    });

    it('hands back everything when everything goes back, and nothing for nothing', function () {
        expect(array_map(fn ($l) => $l->amount, TaxComponents::shareOf($this->charged, 40_000, 40_000)))
            ->toBe(array_map(fn ($l) => $l->amount, $this->charged))
            ->and(lineSum(TaxComponents::shareOf($this->charged, 0, 40_000)))->toBe(0);
    });

    it('still adds up on an awkward fraction', function () {
        foreach ([1, 7, 99, 1_003, 13_337, 39_999] as $returned) {
            $share = TaxComponents::shareOf($this->charged, $returned, 40_000);
            // Computed independently of the function under test.
            expect(lineSum($share))->toBe(Money::mulDiv(lineSum($this->charged), $returned, 40_000));
            foreach ($share as $index => $line) {
                expect($line->amount)->toBeLessThanOrEqual($this->charged[$index]->amount);
            }
        }
    });
});

describe("the GRA's own computation, with VAT on top of the levies", function () {
    it('charges VAT on the value plus the levies', function () {
        $result = TaxComponents::onNet(10_000, ghanaGra());

        // 15% of 105.00, not of 100.00.
        expect(codesAndAmounts($result->lines))->toBe([['NHIL', 250], ['GETFUND', 250], ['VAT', 1_575]])
            ->and($result->total)->toBe(2_075)
            ->and($result->gross)->toBe(12_075);
    });

    it('reports the all-in rate, not the sum of the percentages', function () {
        expect(TaxComponents::totalRateBp(ghana()))->toBe(2_000)
            ->and(TaxComponents::totalRateBp(ghanaGra()))->toBe(2_075);
    });

    it('works back out of a tax-inclusive shelf price', function () {
        $result = TaxComponents::withinGross(12_075, ghanaGra());
        expect($result->net)->toBe(10_000)
            ->and($result->amounts())->toBe([250, 250, 1_575])
            ->and($result->total)->toBe(2_075);
    });

    it('keeps every identity on awkward amounts, both directions', function () {
        foreach ([...AWKWARD, ...array_map(fn ($v) => -$v, AWKWARD)] as $value) {
            $added = TaxComponents::onNet($value, ghanaGra());
            expect(lineSum($added->lines))->toBe($added->total)->and($added->gross)->toBe($value + $added->total);

            $within = TaxComponents::withinGross($value, ghanaGra());
            expect(lineSum($within->lines))->toBe($within->total)->and($within->net + $within->total)->toBe($value);
        }
    });

    it('a price too small to split three ways still adds up', function () {
        // The single pesewa of tax goes to the first line rather than disappearing.
        $result = TaxComponents::withinGross(3, ghanaGra());
        expect([$result->net, $result->total])->toBe([2, 1])
            ->and($result->amounts())->toBe([1, 0, 0]);
    });

    it('takes a price apart that has no exact decomposition', function () {
        // GHS 3.33 is not reachable; the odd pesewa comes off VAT, the largest line.
        $result = TaxComponents::withinGross(333, ghanaGra());
        expect($result->net)->toBe(276)
            ->and($result->amounts())->toBe([7, 7, 43])
            ->and($result->total)->toBe(57)
            ->and($result->net + $result->total)->toBe(333)
            ->and($result->lines[0]->amount)->toBe(Money::mulDiv(276, 250, 10_000));
    });
});

describe('rounding each component on its own base', function () {
    it('gives the authority what is due on each base', function () {
        $result = TaxComponents::onNet(101, ghana());
        expect(codesAndAmounts($result->lines))->toBe([['NHIL', 3], ['GETFUND', 3], ['VAT', 15]])
            ->and($result->total)->toBe(21)
            ->and($result->gross)->toBe(122);
    });
});

describe('the identities hold across every price, not just chosen ones', function () {
    it('adding tax on', function (array $components) {
        for ($value = -5_000; $value <= 5_000; $value++) {
            $result = TaxComponents::onNet($value, $components);
            $running = 0;
            foreach ($result->lines as $line) {
                $base = $line->component->basis === TaxBasis::NetPlusLevies ? $value + $running : $value;
                if ($line->amount !== Money::mulDiv($base, $line->component->rateBp, 10_000)) {
                    throw new RuntimeException("{$line->component->code} on {$value}");
                }
                $running += $line->amount;
            }
            if (lineSum($result->lines) !== $result->total || $result->gross !== $value + $result->total || $result->net !== $value) {
                throw new RuntimeException("identity broken on net {$value}");
            }
        }
        expect(true)->toBeTrue();
    })->with(fn () => array_map(fn (array $components): array => [$components], rateSets()));

    it('extracting tax', function (array $components) {
        for ($value = -5_000; $value <= 5_000; $value++) {
            $result = TaxComponents::withinGross($value, $components);
            if ($result->net + $result->total !== $value || lineSum($result->lines) !== $result->total || $result->gross !== $value) {
                throw new RuntimeException("identity broken on gross {$value}");
            }
            $running = 0;
            foreach ($result->lines as $line) {
                $base = $line->component->basis === TaxBasis::NetPlusLevies ? $result->net + $running : $result->net;
                if (abs($line->amount - Money::mulDiv($base, $line->component->rateBp, 10_000)) > 1) {
                    throw new RuntimeException("{$line->component->code} more than a pesewa off on gross {$value}");
                }
                $running += $line->amount;
            }
        }
        expect(true)->toBeTrue();
    })->with(fn () => array_map(fn (array $components): array => [$components], rateSets()));

    it('a return is the exact mirror of the sale it reverses', function () {
        foreach (rateSets() as $label => $components) {
            for ($value = 1; $value <= 2_000; $value++) {
                $sale = TaxComponents::onNet($value, $components);
                $back = TaxComponents::onNet(-$value, $components);
                foreach ($back->lines as $index => $line) {
                    if ($line->amount + $sale->lines[$index]->amount !== 0) {
                        throw new RuntimeException("{$label} {$value}");
                    }
                }
            }
        }
        expect(true)->toBeTrue();
    });
});

describe('a return, a credit note or a void', function () {
    it('charges tax back, mirroring the sale exactly', function () {
        $back = TaxComponents::onNet(-10_000, ghana());
        expect($back->amounts())->toBe([-250, -250, -1_500])
            ->and($back->total)->toBe(-2_000)
            ->and($back->gross)->toBe(-12_000);
    });

    it('extracts tax from a negative shelf price', function () {
        $result = TaxComponents::withinGross(-12_000, ghana());
        expect([$result->net, $result->total])->toBe([-10_000, -2_000])
            ->and($result->amounts())->toBe([-250, -250, -1_500]);
    });

    it('gives back a share of what a negative document charged', function () {
        $share = TaxComponents::shareOf(TaxComponents::onNet(-40_000, ghana())->lines, -10_000, -40_000);
        expect(array_map(fn ($l) => $l->amount, $share))->toBe([-250, -250, -1_500]);
    });
});

describe('refusing nonsense', function () {
    it('rejects a negative rate', function () {
        TaxComponents::onNet(10_000, [new TaxComponent('X', 'X', -100)]);
    })->throws(ValidationError::class, 'cannot be negative');

    it('cannot even hold a fractional rate', function () {
        // The old app checked at run time; here the type refuses it.
        new TaxComponent('X', 'X', 12.5);
    })->throws(TypeError::class);
});

it('rounds an all-in rate that is not a whole basis point, half up', function () {
    // 1 bp, then 1 bp compounded on it: 2.0001 bp → 2.
    expect(TaxComponents::totalRateBp([new TaxComponent('A', 'A', 1), new TaxComponent('B', 'B', 1, TaxBasis::NetPlusLevies)]))->toBe(2);
    // 50%, then 1 bp on the net plus that: 5001.5 bp → 5002 (truncation would give 5001).
    expect(TaxComponents::totalRateBp([new TaxComponent('A', 'A', 5_000), new TaxComponent('B', 'B', 1, TaxBasis::NetPlusLevies)]))->toBe(5_002);
});
