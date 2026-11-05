<?php

declare(strict_types=1);

/*
| The arithmetic of a delivery, from the old createPurchase: the line refusals
| in their old order, tax on the net, and the discount and levy shares that
| must add back to the pesewa.
*/

use App\Domain\Errors\ValidationError;
use App\Domain\Purchases\PurchaseCalculator;
use App\Domain\Tax\TaxComponent;

it('totals a line and refuses what the old app refused, in its order', function () {
    expect(PurchaseCalculator::lineTotal(300, 2_000, 150))->toBe(450)
        ->and(PurchaseCalculator::lineTotal(0, 2_000))->toBe(0);
    expect(fn () => PurchaseCalculator::lineTotal(-1, 0))->toThrow(ValidationError::class, 'Every line needs a quantity greater than zero.')
        ->and(fn () => PurchaseCalculator::lineTotal(-1, 1_000))->toThrow(ValidationError::class, 'A unit cost cannot be negative.')
        ->and(fn () => PurchaseCalculator::lineTotal(300, 2_000, -1))->toThrow(ValidationError::class, 'A discount cannot be negative.')
        ->and(fn () => PurchaseCalculator::lineTotal(300, 2_000, 601))->toThrow(ValidationError::class, 'A line discount cannot be more than the line total.');
});

it('puts tax on the net after the invoice discount, never more discount than goods', function () {
    $totals = PurchaseCalculator::totals([65_000], 0, [new TaxComponent('VAT', 'VAT', 1_250)]);
    expect([$totals['subtotal'], $totals['net'], $totals['tax'], $totals['total']])->toBe([65_000, 65_000, 8_125, 73_125]);
    expect(fn () => PurchaseCalculator::totals([600], 601, []))->toThrow(ValidationError::class, 'The discount cannot be more than the purchase total.');
});

it('never takes more payment than the total', function () {
    expect(PurchaseCalculator::tender(600, [200]))->toBe(['tendered' => 200, 'outstanding' => 400]);
    expect(fn () => PurchaseCalculator::tender(600, [601]))->toThrow(ValidationError::class, 'You cannot pay more than the purchase total.');
});

it('spreads an awkward discount and the levies to the pesewa', function () {
    foreach ([1, 7, 33, 99, 101, 1_234] as $discount) {
        $costs = PurchaseCalculator::lineCosts([3_333, 6_666, 9_999], $discount, 17);
        expect(array_sum($costs))->toBe(19_998 - $discount + 17, "discount {$discount}");
    }
    expect(PurchaseCalculator::lineCosts([7_500, 2_500], 1_000, 0))->toBe([6_750, 2_250]);
});
