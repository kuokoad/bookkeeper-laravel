<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/sale-batches-by-date.test.ts: a sale
| takes stock that was on the shelf on the day it is dated, so the recall
| trail never names a delivery that had not arrived yet.
*/

use App\Domain\Errors\ValidationError;
use App\Services\InventoryService;
use App\Services\SaleService;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->milk = Shop::product($this->actor, 'Evaporated Milk', 300, 500, ['unit' => 'tin']);

    $deliver = fn (int $units, string $date, ?string $expiry): array => Shop::move([
        'productId' => $this->milk,
        'direction' => 'IN',
        'qty' => $units * 1000,
        'totalCost' => 300 * $units,
        'movementType' => 'PURCHASE',
        'sourceType' => 'TEST',
        'businessDate' => $date,
        'occurredAt' => Clock::fromStamp("{$date} 08:00:00"),
        'batch' => ['kind' => 'NEW', 'expiryDate' => $expiry],
    ]);
    // Five tins on 1 August, ten on 20 August; the later delivery expires first.
    $deliver(5, '2026-08-01', '2027-06-30');
    $deliver(10, '2026-08-20', '2027-01-31');

    // The till passes arrivedBy when the chosen date is not today.
    $this->sellOn = fn (string $date, int $units, bool $backdated = true): array => SaleService::createSale([
        'businessDate' => $date,
        'items' => [['productId' => $this->milk, 'qty' => $units * 1000]],
        'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 500 * $units]],
        ...($backdated ? ['arrivedBy' => $date] : []),
    ], $this->actor);

    $this->leftByArrival = fn (): array => DB::table('product_batches')->where('product_id', $this->milk)->orderBy('id')->get()
        ->mapWithKeys(fn (object $b): array => [$b->received_date => intdiv((int) $b->qty_milli, 1000)])->all();
});

describe('a sale dated before a delivery', function () {
    it('takes the stock that was there that day, not the delivery that came later', function () {
        ($this->sellOn)('2026-08-10', 3);
        expect(($this->leftByArrival)())->toBe(['2026-08-01' => 2, '2026-08-20' => 10]);
    });

    it('is refused when that day’s stock cannot cover it, and says why', function () {
        expect(fn () => ($this->sellOn)('2026-08-10', 8))
            ->toThrow(ValidationError::class, 'On 10 Aug 2026 there was only 5 tin of Evaporated Milk in stock; the rest arrived later');
        expect(($this->leftByArrival)())->toBe(['2026-08-01' => 5, '2026-08-20' => 10]);
    });

    it('can use a delivery from the same day', function () {
        ($this->sellOn)('2026-08-20', 3);
        expect(($this->leftByArrival)())->toBe(['2026-08-01' => 5, '2026-08-20' => 7]);
    });

    it('leaves the books and the batch records agreeing', function () {
        ($this->sellOn)('2026-08-10', 3);

        expect(Shop::trialBalanced())->toBeTrue();
        foreach (InventoryService::verifyBatchCoverage() as $row) {
            expect($row['ok'])->toBeTrue("coverage for product {$row['productId']}");
        }
        foreach (InventoryService::verifyProductBatches($this->milk) as $check) {
            expect($check['ok'])->toBeTrue("batch {$check['batchId']}");
        }
    });
});

describe('a sale dated today', function () {
    it('still reaches for the stock that expires first', function () {
        ($this->sellOn)('2026-08-25', 3, false);
        expect(($this->leftByArrival)())->toBe(['2026-08-01' => 5, '2026-08-20' => 7]);
    });
});

describe('a sale nobody re-dated', function () {
    it('is picked as it always was', function () {
        ($this->sellOn)('2026-08-10', 3, false);
        expect(($this->leftByArrival)())->toBe(['2026-08-01' => 5, '2026-08-20' => 7]);
    });
});

describe('a shop that allows negative stock', function () {
    it('records the shortfall on the undated batch rather than refusing', function () {
        Shop::setting('allow_negative_stock', true);
        ($this->sellOn)('2026-08-10', 8);

        $left = ($this->leftByArrival)();
        expect($left['2026-08-01'])->toBe(0)->and($left['2026-08-20'])->toBe(10);
    });
});

describe('stock that predates batches', function () {
    it('counts as on the shelf for any earlier day', function () {
        DB::table('product_batches')->insert([
            'product_id' => $this->milk, 'batch_ref' => 'BAT-OPEN-00099', 'expiry_date' => null, 'received_date' => '2026-09-01',
            'qty_milli' => 2000, 'opening_qty_milli' => 2000, 'source_type' => 'OPENING', 'is_closed' => false, 'is_demo' => false,
        ]);

        ($this->sellOn)('2026-07-15', 1);

        expect((int) DB::table('product_batches')->where('batch_ref', 'BAT-OPEN-00099')->value('qty_milli'))->toBe(1000);
    });
});
