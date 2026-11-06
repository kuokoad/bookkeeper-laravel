<?php

declare(strict_types=1);

/*
| Ported from the old tests/domain/costing.test.ts and
| costing-properties.test.ts.
*/

use App\Domain\Errors\InsufficientStockError;
use App\Domain\Errors\ValidationError;
use App\Domain\Inventory\Costing;
use App\Domain\Inventory\StockState;
use App\Domain\Quantity;

function units(int|float $n): int
{
    return Quantity::fromUnits((float) $n);
}

function stock(int|float $qtyUnits, int $valueMinor): StockState
{
    return new StockState(units($qtyUnits), $valueMinor);
}

it('re-averages on each purchase and consumes at the average', function () {
    // Buy 10 @ GHS 5.00 -> avg 5.00
    $s = Costing::stockIn(StockState::empty(), units(10), 5_000)->state;
    expect([$s->qty, $s->value, Costing::averageUnitCost($s)])->toBe([10_000, 5_000, 500]);

    // Buy 10 @ GHS 7.00 -> avg 6.00
    $s = Costing::stockIn($s, units(10), 7_000)->state;
    expect([$s->qty, $s->value, Costing::averageUnitCost($s)])->toBe([20_000, 12_000, 600]);

    // Sell 5 -> COGS 30.00, avg unchanged
    $out = Costing::stockOut($s, units(5));
    expect([$out->totalCost, $out->unitCost, $out->state->qty, $out->state->value, Costing::averageUnitCost($out->state)])
        ->toBe([3_000, 600, 15_000, 9_000, 600]);
});

describe('value conservation', function () {
    it('releases exactly the remaining value when the last unit goes', function () {
        // GHS 10.00 over 3 units does not divide evenly.
        $s = Costing::stockIn(StockState::empty(), units(3), 1_000)->state;
        $first = Costing::stockOut($s, units(1));
        $second = Costing::stockOut($first->state, units(1));
        $third = Costing::stockOut($second->state, units(1));

        expect($first->totalCost + $second->totalCost + $third->totalCost)->toBe(1_000)
            ->and([$third->state->qty, $third->state->value])->toBe([0, 0]);
    });

    it('never strands value when selling everything at once', function () {
        $out = Costing::stockOut(Costing::stockIn(StockState::empty(), units(7), 1_001)->state, units(7));
        expect($out->totalCost)->toBe(1_001)->and($out->state)->toEqual(new StockState(0, 0));
    });

    it('conserves value across many awkward in/out sequences', function () {
        foreach ([[3, 1_000, 1], [7, 999, 3], [11, 12_345, 7], [1, 1, 1], [100, 33_333, 99]] as [$in, $cost, $outQty]) {
            $out = Costing::stockOut(Costing::stockIn(StockState::empty(), units($in), $cost)->state, units($outQty));
            $left = $in - $outQty;
            $rest = $left > 0 ? Costing::stockOut($out->state, units($left)) : null;

            expect($out->totalCost + ($rest?->totalCost ?? 0))->toBe($cost)
                ->and(($rest ?? $out)->state)->toEqual(new StockState(0, 0));
        }
    });

    it('handles fractional quantities without drift', function () {
        // 2.5 kg costing GHS 30.00 -> GHS 12.00/kg
        $s = Costing::stockIn(StockState::empty(), Quantity::parse('2.5'), 3_000)->state;
        $out = Costing::stockOut($s, Quantity::parse('1.5'));
        expect(Costing::averageUnitCost($s))->toBe(1_200)
            ->and([$out->totalCost, $out->state->qty, $out->state->value])->toBe([1_800, 1_000, 1_200]);
    });

    it('keeps a long sequence exactly reconciled', function () {
        $s = StockState::empty();
        $in = 0;
        $out = 0;
        for ($i = 1; $i <= 60; $i++) {
            $m = Costing::stockIn($s, units($i % 7 + 1), $i * 137);
            $in += $m->totalCost;
            $s = $m->state;
            if ($s->qty > 0) {
                $m = Costing::stockOut($s, units(1));
                $out += $m->totalCost;
                $s = $m->state;
            }
        }
        expect($in - $out)->toBe($s->value);
    });
});

describe('stock availability rules', function () {
    it('refuses to sell more than is on hand by default', function () {
        Costing::stockOut(stock(5, 5_000), units(6), productName: 'Milo 400g');
    })->throws(InsufficientStockError::class);

    it('reports available and requested amounts in readable units', function () {
        $s = Costing::stockIn(StockState::empty(), Quantity::parse('2.5'), 1_000)->state;
        try {
            Costing::stockOut($s, Quantity::parse('4'), productName: 'Rice');
            $this->fail('should have thrown');
        } catch (InsufficientStockError $e) {
            expect($e->userMessage)->toBe('Not enough stock for Rice. Available: 2.5, requested: 4.');
        }
    });

    it('refuses zero and negative quantities, and a negative cost', function () {
        expect(fn () => Costing::stockOut(stock(5, 500), 0))->toThrow(ValidationError::class)
            ->and(fn () => Costing::stockIn(StockState::empty(), 0, 100))->toThrow(ValidationError::class)
            ->and(fn () => Costing::stockIn(StockState::empty(), units(-1), 100))->toThrow(ValidationError::class)
            ->and(fn () => Costing::stockIn(StockState::empty(), units(1), -1))->toThrow(ValidationError::class);
    });

    it('sells from an empty product only when negative stock is enabled', function () {
        expect(fn () => Costing::stockOut(StockState::empty(), units(1)))->toThrow(InsufficientStockError::class);

        $out = Costing::stockOut(StockState::empty(), units(2), fallbackUnitCost: 500, allowNegative: true);
        expect([$out->state->qty, $out->totalCost, $out->state->value])->toBe([-2_000, 1_000, -1_000]);
    });

    it('consumes real value first, then the fallback rate for the excess', function () {
        // 2 on hand worth GHS 12.00; sell 5 with fallback 5.00: 12.00 + 3 × 5.00.
        $out = Costing::stockOut(stock(2, 1_200), units(5), fallbackUnitCost: 500, allowNegative: true);
        expect([$out->totalCost, $out->state->qty, $out->state->value])->toBe([2_700, -3_000, -1_500]);
    });

    it('brings a negative position back to zero cleanly, leaving no stranded value', function () {
        expect(Costing::stockIn(new StockState(units(-3), -1_500), units(3), 1_500)->state)->toEqual(new StockState(0, 0));

        $back = Costing::stockIn(new StockState(units(-2), -1_000), units(2), 1_400);
        expect($back->state)->toEqual(new StockState(0, 0))->and($back->residual)->toBe(400);
    });
});

describe('returns', function () {
    it('restores exactly the value the sale removed', function () {
        $s = Costing::stockIn(StockState::empty(), units(3), 1_000)->state;
        $sale = Costing::stockOut($s, units(1));
        expect(Costing::stockIn($sale->state, units(1), $sale->totalCost)->state)->toEqual($s);
    });

    it('restores the original cost even after the average has moved', function () {
        $s = Costing::stockIn(StockState::empty(), units(10), 5_000)->state;
        $sale = Costing::stockOut($s, units(2));
        $s = Costing::stockIn($sale->state, units(10), 15_000)->state;

        expect(Costing::stockIn($s, units(2), $sale->totalCost)->state->value - $s->value)->toBe($sale->totalCost);
    });
});

describe('replay proves the cached balance', function () {
    it('reproduces the same state as sequential application', function () {
        $s = StockState::empty();
        $ledger = [];
        foreach ([[10, 5_000], [10, 7_000]] as [$q, $c]) {
            $m = Costing::stockIn($s, units($q), $c);
            $ledger[] = ['qtyIn' => units($q), 'qtyOut' => 0, 'totalCost' => $m->totalCost];
            $s = $m->state;
        }
        $m = Costing::stockOut($s, units(5));
        $ledger[] = ['qtyIn' => 0, 'qtyOut' => units(5), 'totalCost' => $m->totalCost];

        expect(Costing::replay($ledger))->toEqual($m->state)
            ->and(Costing::replay([]))->toEqual(StockState::empty());
    });
});

it('flags low and out-of-stock positions', function () {
    expect(Costing::isLowStock(units(3), units(5), units(10)))->toBeTrue()
        ->and(Costing::isLowStock(units(6), units(5), units(10)))->toBeFalse()
        ->and(Costing::isLowStock(units(8), null, units(10)))->toBeTrue()
        ->and(Costing::isLowStock(units(12), null, units(10)))->toBeFalse()
        ->and(Costing::isLowStock(units(5), units(5), units(10)))->toBeTrue()
        ->and(Costing::isOutOfStock(0))->toBeTrue()
        ->and(Costing::isOutOfStock(units(-2)))->toBeTrue()
        ->and(Costing::isOutOfStock(units(1)))->toBeFalse();
});

it('shows a display-only average, keeping the exact value', function () {
    $s = Costing::stockIn(StockState::empty(), units(3), 1_000)->state;
    expect(Costing::averageUnitCost(StockState::empty()))->toBe(0)
        ->and(Costing::averageUnitCost($s))->toBe(333)
        ->and($s->value)->toBe(1_000);
});

/*
| Properties that must hold for ANY sequence of movements. The generator is
| seeded (xorshift32, as in the old test), so a failure names its seed and
| reproduces.
*/

function xorshift(int $seed): Closure
{
    $state = $seed & 0xFFFFFFFF;

    return function () use (&$state): float {
        $state ^= ($state << 13) & 0xFFFFFFFF;
        $state ^= $state >> 17;
        $state ^= ($state << 5) & 0xFFFFFFFF;

        return $state / 0x1_0000_0000;
    };
}

/** @return list<array{kind: string, qty?: int, cost?: int}> */
function generateSteps(Closure $random, int $length): array
{
    $steps = [];
    for ($i = 0; $i < $length; $i++) {
        $roll = $random();
        $qty = max(1, (int) floor($random() * 8_000));
        $cost = (int) floor($random() * 50_000);
        $steps[] = match (true) {
            $roll < 0.45 => ['kind' => 'IN', 'qty' => $qty, 'cost' => $cost],
            $roll < 0.7 => ['kind' => 'OUT', 'qty' => $qty],
            $roll < 0.85 => ['kind' => 'OUT_AT_COST', 'qty' => $qty, 'cost' => $cost],
            $roll < 0.93 => ['kind' => 'CLEAR'],
            default => ['kind' => 'CLEAR_AT_COST', 'cost' => $cost],
        };
    }

    return $steps;
}

function runSteps(array $steps, bool $allowNegative): array
{
    $state = StockState::empty();
    $ledger = [];
    $totalIn = $totalOut = $totalResidual = $applied = 0;

    foreach ($steps as $step) {
        $qty = in_array($step['kind'], ['CLEAR', 'CLEAR_AT_COST'], true) ? $state->qty : $step['qty'];
        if ($qty <= 0) {
            continue;
        }
        try {
            $m = match ($step['kind']) {
                'IN' => Costing::stockIn($state, $qty, $step['cost']),
                'OUT', 'CLEAR' => Costing::stockOut($state, $qty, 500, $allowNegative),
                default => Costing::stockOutAtCost($state, $qty, $step['cost'], $allowNegative),
            };
        } catch (InsufficientStockError) {
            // Refusing to sell what is not there is correct; anything else must surface.
            continue;
        }
        $applied++;
        $state = $m->state;
        $totalResidual += $m->residual;
        if ($step['kind'] === 'IN') {
            $totalIn += $m->totalCost;
            $ledger[] = ['qtyIn' => $qty, 'qtyOut' => 0, 'totalCost' => $m->totalCost];
        } else {
            $totalOut += $m->totalCost;
            $ledger[] = ['qtyIn' => 0, 'qtyOut' => $qty, 'totalCost' => $m->totalCost];
        }
    }

    return compact('state', 'ledger', 'totalIn', 'totalOut', 'totalResidual', 'applied');
}

it('holds every costing property over 200 seeded sequences', function () {
    $applied = $residuals = $emptied = 0;
    foreach (range(0, 199) as $i) {
        $seed = $i * 7919 + 13;
        $steps = generateSteps(xorshift($seed), 30);
        foreach ([false, true] as $allowNegative) {
            $r = runSteps($steps, $allowNegative);
            $label = "seed {$seed}, allowNegative=".var_export($allowNegative, true);

            // An empty shelf is never worth anything.
            if ($r['state']->qty === 0) {
                expect($r['state']->value)->toBe(0, $label);
                $emptied++;
            }
            // Nothing is created and nothing evaporates.
            expect($r['state']->value)->toBe($r['totalIn'] - $r['totalOut'] - $r['totalResidual'], $label);
            // The cached pair survives a replay of its own movements.
            expect(Costing::replay($r['ledger']))->toEqual($r['state'], $label);
            if (! $allowNegative) {
                expect($r['state']->qty)->toBeGreaterThanOrEqual(0, $label);
            } else {
                $applied += $r['applied'];
                $residuals += $r['totalResidual'] !== 0 ? 1 : 0;
            }
        }
    }

    // A guard on the guards: the engine was exercised, including the residual and empty-shelf branches.
    expect($applied)->toBeGreaterThan(200 * 20)
        ->and($residuals)->toBeGreaterThan(0)
        ->and($emptied)->toBeGreaterThan(0);
});
