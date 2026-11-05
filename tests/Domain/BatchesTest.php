<?php

declare(strict_types=1);

/*
| Ported from the old tests/domain/batch-allocation.test.ts.
*/

use App\Domain\Errors\ValidationError;
use App\Domain\Inventory\Batches;

const TODAY = '2026-08-25';

function batch(int $id, int $qtyUnits, ?string $expiryDate = null): array
{
    return ['id' => $id, 'batchRef' => sprintf('BAT-%05d', $id), 'expiryDate' => $expiryDate, 'qtyMilli' => $qtyUnits * 1000];
}

function took(array $plan): array
{
    return array_map(fn (array $a): array => [$a['batchId'], $a['qtyMilli']], $plan['allocations']);
}

function planned(array $plan): int
{
    return array_sum(array_column($plan['allocations'], 'qtyMilli'));
}

function ids(array $batches): array
{
    return array_column($batches, 'id');
}

describe('what counts as expired', function () {
    it('is good on its own date, expired the day after, and never without a date', function () {
        expect(Batches::isExpired(batch(1, 5, TODAY), TODAY))->toBeFalse()
            ->and(Batches::isExpired(batch(1, 5, '2026-08-24'), TODAY))->toBeTrue()
            ->and(Batches::isExpired(batch(1, 5), TODAY))->toBeFalse();
    });
});

describe('the picking order', function () {
    it('puts dated-and-good first, then undated, then expired', function () {
        expect(ids(Batches::orderForPicking([batch(1, 5, '2026-01-01'), batch(2, 5), batch(3, 5, '2026-09-01'), batch(4, 5, '2026-08-26')], TODAY)))
            ->toBe([4, 3, 2, 1]);
    });

    it('takes the tightest date first, and the oldest undated batch first', function () {
        expect(ids(Batches::orderForPicking([batch(1, 5, '2026-12-31'), batch(2, 5, '2026-08-26'), batch(3, 5, '2026-10-01')], TODAY)))->toBe([2, 3, 1])
            ->and(ids(Batches::orderForPicking([batch(7, 5), batch(2, 5), batch(5, 5)], TODAY)))->toBe([2, 5, 7]);
    });

    it('is total, so the same shelf always gives the same answer', function () {
        $shelf = [batch(3, 5, '2026-09-01'), batch(1, 5, '2026-09-01'), batch(2, 5, '2026-09-01')];
        expect(ids(Batches::orderForPicking($shelf, TODAY)))->toBe([1, 2, 3])
            ->and(ids(Batches::orderForPicking(array_reverse($shelf), TODAY)))->toBe([1, 2, 3]);
    });
});

describe('taking stock out', function () {
    it('draws from one batch when it covers the lot', function () {
        $plan = Batches::allocateFefo([batch(1, 10, '2026-09-01')], 4_000, TODAY);
        expect(took($plan))->toBe([[1, 4_000]])->and([$plan['shortfall'], $plan['expiredNeeded']])->toBe([0, 0]);
    });

    it('spans three batches and still sums to what was asked', function () {
        $plan = Batches::allocateFefo([batch(1, 2, '2026-08-26'), batch(2, 3, '2026-09-01'), batch(3, 10)], 9_000, TODAY);
        expect(took($plan))->toBe([[1, 2_000], [2, 3_000], [3, 4_000]])->and(planned($plan))->toBe(9_000)->and($plan['shortfall'])->toBe(0);
    });

    it('SKIPS expired stock entirely while good stock covers the sale', function () {
        $plan = Batches::allocateFefo([batch(1, 20, '2026-01-01'), batch(2, 30, '2026-09-01')], 5_000, TODAY);
        expect(took($plan))->toBe([[2, 5_000]])
            ->and([$plan['expiredNeeded'], $plan['expiredRefs'], $plan['shortfall']])->toBe([0, [], 0]);
    });

    it('reports what expired stock would be needed, without taking it', function () {
        $plan = Batches::allocateFefo([batch(1, 20, '2026-01-01'), batch(2, 3, '2026-09-01')], 5_000, TODAY);
        expect(took($plan))->toBe([[2, 3_000]])
            ->and([$plan['expiredNeeded'], $plan['expiredRefs'], $plan['shortfall']])->toBe([2_000, ['BAT-00001'], 0]);
    });

    it('reaches into expired stock only when told to, earliest date first', function () {
        $plan = Batches::allocateFefo([batch(1, 2, '2026-02-01'), batch(2, 5, '2026-01-01'), batch(3, 1, '2026-09-01')], 6_000, TODAY, allowExpired: true);
        expect(took($plan))->toBe([[3, 1_000], [2, 5_000]])->and([$plan['expiredNeeded'], $plan['shortfall']])->toBe([5_000, 0]);
    });

    it('reports a genuine shortage separately from an expiry problem, and both together', function () {
        $short = Batches::allocateFefo([batch(1, 2, '2026-09-01')], 5_000, TODAY);
        $both = Batches::allocateFefo([batch(1, 1, '2026-01-01'), batch(2, 1, '2026-09-01')], 5_000, TODAY);
        expect([$short['shortfall'], $short['expiredNeeded']])->toBe([3_000, 0])
            ->and([$both['expiredNeeded'], $both['shortfall']])->toBe([1_000, 3_000]);
    });

    it('does not pick from an empty or negative batch', function () {
        $negative = ['qtyMilli' => -4_000] + batch(2, 0);
        expect(took(Batches::allocateFefo([batch(1, 0, '2026-08-26'), $negative, batch(3, 5, '2026-09-01')], 2_000, TODAY)))->toBe([[3, 2_000]]);
    });

    it('refuses a quantity of nothing', function () {
        Batches::allocateFefo([batch(1, 5)], 0, TODAY);
    })->throws(ValidationError::class);
});

describe('writing off what has turned', function () {
    it('takes the expired stock first, oldest first', function () {
        $plan = Batches::allocateFefo([batch(1, 5, '2026-09-01'), batch(2, 3, '2026-02-01'), batch(3, 3, '2026-01-01')], 6_000, TODAY, allowExpired: true, expiredFirst: true);
        expect(took($plan))->toBe([[3, 3_000], [2, 3_000]])->and($plan['shortfall'])->toBe(0);
    });

    it('falls through to the rest once the expired stock runs out', function () {
        $plan = Batches::allocateFefo([batch(1, 4), batch(2, 2, '2026-01-01')], 5_000, TODAY, allowExpired: true, expiredFirst: true);
        expect(took($plan))->toBe([[2, 2_000], [1, 3_000]])->and($plan['expiredNeeded'])->toBe(2_000);
    });

    it('reports a shortfall rather than inventing stock', function () {
        $plan = Batches::allocateFefo([batch(1, 2, '2026-01-01')], 5_000, TODAY, allowExpired: true, expiredFirst: true);
        expect([planned($plan), $plan['shortfall']])->toBe([2_000, 3_000]);
    });
});

describe('putting stock back where it came from', function () {
    $source = fn (array $qtys): array => array_map(fn (int $i): array => ['batchId' => $i + 1, 'batchRef' => sprintf('BAT-%05d', $i + 1), 'qtyMilli' => $qtys[$i]], array_keys($qtys));

    it('returns the whole movement batch for batch', function () use ($source) {
        expect(array_map(fn ($a) => [$a['batchId'], $a['qtyMilli']], Batches::allocateProportional($source([2_000, 3_000]), 5_000)))->toBe([[1, 2_000], [2, 3_000]]);
    });

    it('splits a partial return in proportion, summing exactly', function () use ($source) {
        expect(array_column(Batches::allocateProportional($source([2_000, 6_000]), 4_000), 'qtyMilli'))->toBe([1_000, 3_000]);
    });

    it('gives the odd unit to the largest remainder rather than losing it', function () use ($source) {
        expect(array_sum(array_column(Batches::allocateProportional($source([1, 1, 1]), 2), 'qtyMilli')))->toBe(2);
    });

    it('is deterministic when remainders tie', function () use ($source) {
        $first = Batches::allocateProportional($source([1_000, 1_000, 1_000]), 1_001);
        expect($first)->toBe(Batches::allocateProportional($source([1_000, 1_000, 1_000]), 1_001))
            ->and(array_sum(array_column($first, 'qtyMilli')))->toBe(1_001);
    });

    it('refuses to put back more than went out, or when nothing went out', function () use ($source) {
        expect(fn () => Batches::allocateProportional($source([2_000]), 3_000))->toThrow(ValidationError::class)
            ->and(fn () => Batches::allocateProportional([], 1_000))->toThrow(ValidationError::class);
    });
});

it('keeps batch quantities summing to the shelf across 500 movements', function () {
    // Deterministic, so a failure reproduces. PHP's 64-bit ints make this LCG
    // exact where the old JavaScript one was not; the property is the same.
    $seed = 20260825;
    $next = function (int $n) use (&$seed): int {
        $seed = ($seed * 1103515245 + 12345) % 2147483648;

        return $seed % $n;
    };
    $batches = [];
    $nextId = 1;
    $shelf = 0;
    for ($step = 0; $step < 500; $step++) {
        if (! ($shelf > 0 && $next(2) === 0)) {
            $qty = ($next(20) + 1) * 1000;
            $batches[] = ['id' => $nextId, 'batchRef' => sprintf('BAT-%05d', $nextId), 'expiryDate' => $next(3) !== 0 ? '2026-0'.(1 + $next(9)).'-15' : null, 'qtyMilli' => $qty];
            $nextId++;
            $shelf += $qty;

            continue;
        }
        $want = min($shelf, ($next(15) + 1) * 1000);
        $plan = Batches::allocateFefo($batches, $want, TODAY, allowExpired: true);
        expect(planned($plan) + $plan['shortfall'])->toBe($want);
        foreach ($plan['allocations'] as $allocation) {
            foreach ($batches as &$target) {
                if ($target['id'] === $allocation['batchId']) {
                    $target['qtyMilli'] -= $allocation['qtyMilli'];
                    expect($target['qtyMilli'])->toBeGreaterThanOrEqual(0);
                }
            }
            unset($target);
        }
        $shelf -= planned($plan);
        expect(array_sum(array_column($batches, 'qtyMilli')))->toBe($shelf, "step {$step}");
    }
});

describe('a batch quantity in a message', function () {
    it('drops trailing zeros and does not group thousands', function () {
        expect(Batches::formatQty(2_500))->toBe('2.5')
            ->and(Batches::formatQty(1_200_000))->toBe('1200')
            ->and(Batches::formatQty(-3_000))->toBe('-3')
            ->and(Batches::formatQty(5))->toBe('0.005')
            ->and(Batches::formatQty(0))->toBe('0');
    });
});
