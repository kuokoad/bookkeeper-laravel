<?php

declare(strict_types=1);

/*
| Places where PHP's built-ins would quietly differ from the old JavaScript,
| each found by running both side by side in the Phase 1 review. Every case
| here names the old result.
*/

use App\Domain\BusinessDate;
use App\Domain\Errors\ValidationError;
use App\Domain\FinancialYear;
use App\Domain\JsCompat;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Domain\Rate;
use App\Domain\Sales\SaleCalculator;
use App\Domain\Tax\TaxComponent;
use App\Domain\Tax\TaxComponents;
use App\Enums\TaxBasis;

it('rounds an exact binary tie the way toFixed does, away from zero', function () {
    // 0.125 is exact in binary; sprintf('%.2F') gives "0.12", toFixed(2) gives "0.13".
    expect(Money::fromMajor(0.125))->toBe(13)
        ->and(Money::fromMajor(-0.125))->toBe(-13)
        ->and(Money::fromMajor(2.675))->toBe(267) // 2.67499999… in binary: no tie
        ->and(Quantity::fromUnits(0.0625))->toBe(63)
        ->and(Quantity::fromUnits(-0.0625))->toBe(-63)
        ->and(JsCompat::toFixedScaled(1e21, 2)->__toString())->toBe('100000000000000000000000');
});

it('refuses a date with a trailing newline, as the old pattern did', function () {
    expect(fn () => BusinessDate::assert("2025-01-31\n"))->toThrow(ValidationError::class)
        ->and(fn () => BusinessDate::addDays("2025-01-31\n", 1))->toThrow(ValidationError::class)
        ->and(fn () => FinancialYear::for("2025-01-31\n", 1))->toThrow(ValidationError::class);
});

it('refuses an amount, quantity or rate with a trailing newline inside the pattern', function () {
    // trim() takes surrounding whitespace first, so only an inner newline can reach the pattern.
    expect(fn () => Money::parse("1\n.00"))->toThrow(ValidationError::class)
        ->and(fn () => Quantity::parse("1\n.5"))->toThrow(ValidationError::class)
        ->and(fn () => Rate::parsePercent("12\n.5"))->toThrow(ValidationError::class);
});

it('trims the whitespace JavaScript trims, including a non-breaking space', function () {
    expect(Money::parse("1,250.00\u{00A0}"))->toBe(125_000)
        ->and(Money::parse("GHS\u{00A0}80"))->toBe(8_000)
        ->and(Money::parse("\u{FEFF}₵\u{2003}5"))->toBe(500)
        ->and(Quantity::parse("\u{00A0}1.5"))->toBe(1_500)
        ->and(Rate::parsePercent("12.5\u{3000}"))->toBe(1_250)
        // …and keeps a NUL, which JavaScript's trim leaves alone.
        ->and(fn () => Money::parse("5\0"))->toThrow(ValidationError::class);
});

it('refuses a float where a whole number of minor units is required', function () {
    // Without strict types PHP would truncate 1.5 to 1 and carry on.
    expect(fn () => Money::multiply(100, 1.5))->toThrow(TypeError::class)
        ->and(fn () => Quantity::extendPrice(870, 1500.7))->toThrow(TypeError::class)
        ->and(fn () => Money::add('12', 1))->toThrow(TypeError::class);
});

it('lets the computed line figures win over anything the caller passed', function () {
    $r = SaleCalculator::calculate([['productId' => 1, 'qty' => 1_000, 'unitPrice' => 500, 'lineTotal' => 1, 'gross' => 7, 'netTotal' => 9]]);
    expect([$r->subtotal, $r->lines[0]['gross'], $r->lines[0]['lineTotal'], $r->lines[0]['netTotal']])->toBe([500, 500, 500, 500]);
});

it('shows a negative carton factor the way Math.floor did', function () {
    expect(Quantity::formatAlt(40_000, 'pcs', 'Carton', -24_000))->toBe('-2 Carton -8 pcs');
});

it('refuses a unit with more decimals than a quantity can hold', function () {
    Quantity::assertUnitDecimals(1_000, 4, 'kg');
})->throws(ValidationError::class);

it('mirrors a sale exactly on a return, totals included', function () {
    $ghana = [new TaxComponent('NHIL', 'NHIL', 250), new TaxComponent('GETFUND', 'GETFund', 250), new TaxComponent('VAT', 'VAT', 1_500, TaxBasis::NetPlusLevies, true)];
    for ($value = 1; $value <= 2_000; $value++) {
        $sale = TaxComponents::onNet($value, $ghana);
        $back = TaxComponents::onNet(-$value, $ghana);
        if ($back->total + $sale->total !== 0) {
            throw new RuntimeException("totals do not mirror at {$value}");
        }
    }
    expect(true)->toBeTrue();
});

it('keeps every identity on large awkward negatives (the old awkward-negatives case)', function () {
    $flat = [new TaxComponent('NHIL', 'NHIL', 250), new TaxComponent('GETFUND', 'GETFund', 250), new TaxComponent('VAT', 'VAT', 1_500, TaxBasis::Net, true)];
    foreach ([-1, -3, -7, -33, -99, -101, -333, -1_007, -4_999, -12_345, -99_999, -1_234_567] as $value) {
        $added = TaxComponents::onNet($value, $flat);
        $within = TaxComponents::withinGross($value, $flat);
        expect(Money::sum($added->amounts()))->toBe($added->total)
            ->and($added->gross)->toBe($value + $added->total)
            ->and(Money::sum($within->amounts()))->toBe($within->total)
            ->and($within->net + $within->total)->toBe($value);
    }
});
