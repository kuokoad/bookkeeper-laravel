<?php

declare(strict_types=1);

/*
| The new-purchase form's reading of what was typed (the old
| purchases/new/read-purchase.ts, which had no tests of its own): a figure is
| shown only when Save would store exactly that figure, otherwise null.
*/

use App\Domain\Purchases\ReadPurchase;
use App\Domain\Tax\TaxComponent;

const RP_RICE = ['unit' => 'bag', 'altUnit' => 'carton', 'altFactorMilli' => 10_000, 'unitDecimals' => 0, 'altUnitDecimals' => 0];

function rpRow(array $overrides = []): array
{
    return ['productId' => '1', 'qty' => '2', 'alt' => false, 'unitCost' => '50.00', 'discount' => '', ...$overrides];
}

function rpRead(array $rows, string $discount = '', string $pay = '', array $taxes = [], string $date = '2026-08-05', ?string $lockedBefore = null): array
{
    return ReadPurchase::read($rows, ['1' => RP_RICE], $discount, $pay, true, $taxes, $date, $lockedBefore, '', '');
}

it('totals the lines, takes off the discount and adds tax on the net', function () {
    $reading = rpRead([rpRow(), rpRow(['qty' => '1', 'unitCost' => '20.00'])], discount: '20.00', pay: '50.00', taxes: [new TaxComponent('VAT', 'VAT', 1_000)]);

    expect($reading['subtotal'])->toBe(12_000)           // 2 x 50.00 + 1 x 20.00
        ->and($reading['total'])->toBe(11_000)           // (120.00 - 20.00) + 10% VAT
        ->and($reading['paid'])->toBe(5_000)
        ->and($reading['outstanding'])->toBe(6_000)
        ->and($reading['filled'])->toBe(2)
        ->and($reading['acceptable'])->toBeTrue();
});

it('ignores a blank row and shows no total while a line is wrong', function () {
    $blank = rpRow(['productId' => '', 'qty' => '', 'unitCost' => '']);
    expect(rpRead([rpRow(), $blank])['filled'])->toBe(1);

    $reading = rpRead([rpRow(['qty' => '1.5'])]);
    expect($reading['lines'][0]['errors'])->toHaveKey('qty')
        ->and($reading['total'])->toBeNull()
        ->and($reading['acceptable'])->toBeFalse()
        ->and(ReadPurchase::fieldRefused($reading))->toBeTrue();
});

it('refuses a discount above the line or the subtotal, and paying more than the total', function () {
    expect(rpRead([rpRow(['discount' => '200.00'])])['lines'][0]['errors'])->toHaveKey('discount');
    expect(rpRead([rpRow()], discount: '200.00')['invoiceDiscountError'])->toBe('The discount cannot be more than the subtotal, 100.00.');
    expect(rpRead([rpRow()], pay: '150.00')['paidError'])->toBe('You cannot pay more than the purchase total.');
});

it('refuses a missing or locked delivery date', function () {
    expect(rpRead([rpRow()], date: '2026-02-30')['dateError'])->toBe('Enter the delivery date.')
        ->and(rpRead([rpRow()], date: '')['dateError'])->toBe('Enter the delivery date.')
        ->and(rpRead([rpRow()], date: '2026-08-05', lockedBefore: '2026-08-31')['dateError'])->not->toBeNull()
        ->and(rpRead([rpRow()], date: '2026-08-05', lockedBefore: '2026-08-31')['acceptable'])->toBeFalse();
});

it('counts a line in cartons in the product\'s alternate unit', function () {
    $reading = rpRead([rpRow(['qty' => '1', 'alt' => true, 'unitCost' => '450.00'])]);
    expect($reading['lines'][0]['errors'])->toBe([])->and($reading['total'])->toBe(45_000);
});
