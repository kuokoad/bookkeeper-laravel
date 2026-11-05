<?php

declare(strict_types=1);

/*
| Ported from the old tests/app/adjustment-cost-fields.test.ts: the stock-in
| cost fields on the adjustment form (App\Support\CostFields).
*/

use App\Support\CostFields;

describe('stock-in cost fields on the adjustment form', function () {
    it('works out the total from a unit cost, and that total is what gets posted', function () {
        expect(CostFields::of('unit', '5.00', '10'))->toBe(['unitCost' => '5.00', 'total' => '50.00']);
    });

    it('works out the unit cost from a total', function () {
        expect(CostFields::of('total', '50.00', '10'))->toBe(['unitCost' => '5.00', 'total' => '50.00']);
    });

    it('rounds a fractional quantity to the pesewa the way sale lines do', function () {
        // 2.5 kg at 3.33 = 8.325, half rounds up.
        expect(CostFields::of('unit', '3.33', '2.5')['total'])->toBe('8.33');
    });

    it('shows no derived figure until both the amount and the quantity read', function () {
        expect(CostFields::of('unit', '5.00', ''))->toBe(['unitCost' => '5.00', 'total' => ''])
            ->and(CostFields::of('total', '50', '0'))->toBe(['unitCost' => '', 'total' => '50']);
    });

    it('says why a unit cost cannot be used instead of leaving the total silently blank', function () {
        $tooFine = CostFields::of('unit', '0.125', '40');
        expect($tooFine['total'])->toBe('')
            ->and($tooFine['error'])->toContain('2 decimal places')
            ->and(CostFields::of('unit', '5.0.0', '10'))->toHaveKey('error');
    });

    it('refuses a negative cost in either field rather than posting a negative total', function () {
        foreach (['-5', '(5.00)'] as $typed) {
            $unitLed = CostFields::of('unit', $typed, '10');
            expect($unitLed['total'])->toBe('')
                ->and($unitLed['error'])->toBe('A cost cannot be negative.')
                ->and(CostFields::of('total', $typed, '10')['error'])->toBe('A cost cannot be negative.');
        }
    });

    it('does not call a half-typed quantity a cost error', function () {
        expect(CostFields::of('unit', '5.00', ''))->not->toHaveKey('error');
    });
});
