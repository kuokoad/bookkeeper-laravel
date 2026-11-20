<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/sale-expiry.test.ts: expired stock is
| skipped in silence while good stock covers the sale, blocks only when nothing
| else is left, and one person with the right can approve it, on the record.
*/

use App\Domain\Errors\ExpiredStockError;
use App\Services\InventoryService;
use App\Services\ReturnsService;
use App\Services\SaleService;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const EXPIRY_TODAY = '2026-08-25';
const EXPIRY_GONE = '2026-08-01';
const EXPIRY_SOON = '2026-09-10';
const EXPIRY_LATER = '2027-01-31';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->cash = Shop::paymentAccount('CASH');
    $this->make = fn (string $name = 'Evaporated Milk'): int => Shop::product($this->actor, $name, 300, 500, ['unit' => 'tin']);
    $this->deliver = fn (int $productId, int $units, ?string $expiry): int => Shop::move([
        'productId' => $productId,
        'direction' => 'IN',
        'qty' => $units * 1000,
        'totalCost' => 300 * $units,
        'movementType' => 'PURCHASE',
        'sourceType' => 'TEST',
        'businessDate' => EXPIRY_TODAY,
        'occurredAt' => Clock::fromStamp(EXPIRY_TODAY.' 08:00:00'),
        'batch' => ['kind' => 'NEW', 'expiryDate' => $expiry],
    ])['batchAllocations'][0]['batchId'];
    $this->sell = fn (int $productId, int $units, bool $allowExpired = false): array => SaleService::createSale([
        'businessDate' => EXPIRY_TODAY,
        'items' => [['productId' => $productId, 'qty' => $units * 1000]],
        'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 500 * $units]],
        ...($allowExpired ? ['allowExpiredStock' => true, 'overrideReason' => 'Customer was told and still wanted it'] : []),
    ], $this->actor);
    $this->held = fn (int $productId): array => DB::table('product_batches')->where('product_id', $productId)->orderBy('id')
        ->pluck('qty_milli', 'id')->map(fn ($q): int => (int) $q)->all();
    $this->overrides = fn () => DB::table('audit_logs')->where('entity_type', 'expiry_override')->get();
    $this->expectHealthy = function (string $label): void {
        foreach (InventoryService::verifyBatchCoverage() as $row) {
            expect($row['ok'])->toBeTrue("{$label}: coverage for product {$row['productId']}");
        }
        foreach (DB::table('products')->pluck('id') as $id) {
            expect(InventoryService::verifyProductStock((int) $id)['ok'])->toBeTrue("{$label}: stock p{$id}");
            foreach (InventoryService::verifyProductBatches((int) $id) as $check) {
                expect($check['ok'])->toBeTrue("{$label}: batch {$check['batchId']}");
            }
        }
        expect(Shop::trialBalanced())->toBeTrue("{$label}: trial balance");
    };
});

describe('an old crate at the back of the shelf', function () {
    it('is not mentioned while there is good stock to sell', function () {
        $milk = ($this->make)();
        $expired = ($this->deliver)($milk, 20, EXPIRY_GONE);
        $good = ($this->deliver)($milk, 30, EXPIRY_SOON);

        ($this->sell)($milk, 5);

        $held = ($this->held)($milk);
        expect($held[$expired])->toBe(20_000)->and($held[$good])->toBe(25_000);
        ($this->expectHealthy)('sale covered by good stock');
    });

    it('blocks the sale once the good stock runs out', function () {
        $milk = ($this->make)();
        ($this->deliver)($milk, 20, EXPIRY_GONE);
        ($this->deliver)($milk, 3, EXPIRY_SOON);

        expect(fn () => ($this->sell)($milk, 5))->toThrow(ExpiredStockError::class);
    });

    it('still blocks in a shop that no longer uses dates, while stock carries one', function () {
        Shop::setting('business_type', 'building_materials');
        Shop::setting('feature_expiry_batches', false);
        $milk = ($this->make)();
        ($this->deliver)($milk, 20, EXPIRY_GONE);
        ($this->deliver)($milk, 3, EXPIRY_SOON);

        expect(fn () => ($this->sell)($milk, 5))->toThrow(ExpiredStockError::class);
    });

    it('writes nothing at all when it blocks', function () {
        $milk = ($this->make)();
        $expired = ($this->deliver)($milk, 20, EXPIRY_GONE);
        $good = ($this->deliver)($milk, 3, EXPIRY_SOON);

        expect(fn () => ($this->sell)($milk, 5))->toThrow(ExpiredStockError::class);

        $held = ($this->held)($milk);
        expect(DB::table('sales')->count())->toBe(0)
            ->and($held[$expired])->toBe(20_000)
            ->and($held[$good])->toBe(3_000);
        ($this->expectHealthy)('after a refused sale');
    });

    it('names the goods and the crate, so somebody can be asked about them', function () {
        $milk = ($this->make)('Ideal Milk 170g');
        ($this->deliver)($milk, 20, EXPIRY_GONE);
        ($this->deliver)($milk, 3, EXPIRY_SOON);

        try {
            ($this->sell)($milk, 5);
            $this->fail('the sale should have been refused');
        } catch (ExpiredStockError $error) {
            expect($error->details['productName'])->toBe('Ideal Milk 170g')
                ->and($error->details['qtyExpired'])->toBe('2')
                ->and($error->details['batchRefs'])->toHaveCount(1)
                ->and($error->userMessage)->toContain('Ideal Milk 170g');
        }
    });
});

describe('when somebody senior says yes', function () {
    it('completes the sale, taking the good stock first', function () {
        $milk = ($this->make)();
        $expired = ($this->deliver)($milk, 20, EXPIRY_GONE);
        $good = ($this->deliver)($milk, 3, EXPIRY_SOON);

        ($this->sell)($milk, 5, true);

        $held = ($this->held)($milk);
        expect($held[$good])->toBe(0)->and($held[$expired])->toBe(18_000);
        ($this->expectHealthy)('approved sale of expired stock');
    });

    it('records who did it and which crate it came out of', function () {
        $milk = ($this->make)();
        ($this->deliver)($milk, 20, EXPIRY_GONE);
        ($this->deliver)($milk, 3, EXPIRY_SOON);

        $sale = ($this->sell)($milk, 5, true);

        $override = ($this->overrides)()->first();
        expect($override)->not->toBeNull();
        $metadata = json_decode($override->metadata, true);
        expect($override->username)->toBe('kwame')
            ->and($override->entity_id)->toBe((string) $sale['saleId'])
            ->and($override->summary)->toContain($sale['receiptNo'])
            ->and($metadata['batchRefs'])->toHaveCount(1)
            ->and($override->summary)->toContain($metadata['batchRefs'][0])
            ->and($metadata['reason'])->toBe('Customer was told and still wanted it');
    });

    it('records it again when the owner edits a sale that sold from an expired crate', function () {
        $milk = ($this->make)();
        ($this->deliver)($milk, 20, EXPIRY_GONE);
        ($this->deliver)($milk, 3, EXPIRY_SOON);
        $sale = ($this->sell)($milk, 5, true);

        SaleService::editSale($sale['saleId'], [
            'businessDate' => EXPIRY_TODAY,
            'items' => [['productId' => $milk, 'qty' => 5_000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 2_500]],
            'note' => 'Customer took the old tins',
        ], 'Add the note', $this->actor, Clock::fromStamp(EXPIRY_TODAY.' 12:00:00'));

        $rows = ($this->overrides)();
        expect($rows)->toHaveCount(2)
            ->and($rows->pluck('entity_id')->unique()->all())->toBe([(string) $sale['saleId']]);
        ($this->expectHealthy)('edited expired sale');
    });

    it('records it on a backdated sale of a crate that expired after the sale date', function () {
        // The till judges a backdated sale's stock as it stands today (expiryAsAt), so the record must too.
        $milk = ($this->make)();
        ($this->deliver)($milk, 20, EXPIRY_SOON);

        $sale = SaleService::createSale([
            'businessDate' => EXPIRY_TODAY,
            'items' => [['productId' => $milk, 'qty' => 5_000]],
            'tenders' => [['paymentAccountId' => $this->cash, 'amount' => 2_500]],
            'allowExpiredStock' => true,
            'overrideReason' => 'Customer was told and still wanted it',
            'expiryAsAt' => '2026-09-15',
            'arrivedBy' => EXPIRY_TODAY,
        ], $this->actor);

        $rows = ($this->overrides)();
        expect($rows)->toHaveCount(1)
            ->and($rows->first()->entity_id)->toBe((string) $sale['saleId'])
            ->and(json_decode($rows->first()->metadata, true)['batchRefs'])->toHaveCount(1);
    });

    it('leaves no override row behind on an ordinary sale', function () {
        $milk = ($this->make)();
        ($this->deliver)($milk, 20, EXPIRY_GONE);
        ($this->deliver)($milk, 30, EXPIRY_SOON);

        ($this->sell)($milk, 5, true);

        expect(($this->overrides)())->toHaveCount(0);
    });

    it('takes the oldest expired crate first', function () {
        $milk = ($this->make)();
        $older = ($this->deliver)($milk, 4, '2026-01-01');
        $newer = ($this->deliver)($milk, 4, '2026-06-01');

        ($this->sell)($milk, 6, true);

        $held = ($this->held)($milk);
        expect($held[$older])->toBe(0)->and($held[$newer])->toBe(2_000);
        ($this->expectHealthy)('two expired crates');
    });
});

describe('a shop that does not want any of this', function () {
    it('sells expired stock without anybody being asked', function () {
        Shop::setting('expiry_blocks_sales', false);
        $milk = ($this->make)();
        $expired = ($this->deliver)($milk, 20, EXPIRY_GONE);
        ($this->deliver)($milk, 3, EXPIRY_SOON);

        ($this->sell)($milk, 5);

        expect(($this->held)($milk)[$expired])->toBe(18_000);
        ($this->expectHealthy)('block turned off');
    });

    it('still records nothing as an override, because nobody overrode anything', function () {
        Shop::setting('expiry_blocks_sales', false);
        $milk = ($this->make)();
        ($this->deliver)($milk, 20, EXPIRY_GONE);
        ($this->sell)($milk, 5);

        expect(($this->overrides)())->toHaveCount(0);
    });
});

describe('first-expiry-first-out among good stock', function () {
    it('picks the earlier date first', function () {
        $milk = ($this->make)();
        $later = ($this->deliver)($milk, 5, EXPIRY_LATER);
        $soon = ($this->deliver)($milk, 5, EXPIRY_SOON);

        ($this->sell)($milk, 7);

        $held = ($this->held)($milk);
        expect($held[$soon])->toBe(0)->and($held[$later])->toBe(3_000);
        ($this->expectHealthy)('fefo across two good crates');
    });

    it('drains dated stock before undated, which has no deadline', function () {
        $milk = ($this->make)();
        $undated = ($this->deliver)($milk, 5, null);
        $dated = ($this->deliver)($milk, 5, EXPIRY_LATER);

        ($this->sell)($milk, 5);

        $held = ($this->held)($milk);
        expect($held[$dated])->toBe(0)->and($held[$undated])->toBe(5_000);
        ($this->expectHealthy)('dated before undated');
    });
});

describe('goods coming back', function () {
    it('a void returns each unit to the crate it left', function () {
        $milk = ($this->make)();
        $soon = ($this->deliver)($milk, 4, EXPIRY_SOON);
        $later = ($this->deliver)($milk, 10, EXPIRY_LATER);

        $sale = ($this->sell)($milk, 7);
        expect(($this->held)($milk)[$soon])->toBe(0);

        SaleService::voidSale($sale['saleId'], 'Customer changed their mind', $this->actor);

        $held = ($this->held)($milk);
        expect($held[$soon])->toBe(4_000)
            ->and($held[$later])->toBe(10_000)
            ->and($held)->toHaveCount(2);
        ($this->expectHealthy)('voided sale');
    });

    it('a partial return of a two-crate line splits in proportion', function () {
        $milk = ($this->make)();
        $soon = ($this->deliver)($milk, 2, EXPIRY_SOON);
        $later = ($this->deliver)($milk, 10, EXPIRY_LATER);

        $sale = ($this->sell)($milk, 8);
        $items = ReturnsService::getReturnableSaleItems($sale['saleId']);
        ReturnsService::createCustomerReturn($sale['saleId'], [
            'businessDate' => EXPIRY_TODAY,
            'items' => [['itemId' => $items[0]['id'], 'qty' => 4_000]],
            'refunds' => [['paymentAccountId' => $this->cash, 'amount' => 2_000]],
        ], $this->actor);

        $held = ($this->held)($milk);
        expect($held[$soon])->toBe(1_000)->and($held[$later])->toBe(7_000);
        ($this->expectHealthy)('partial return across two crates');
    });

    it('still voids a sale made before batches existed', function () {
        $milk = ($this->make)();
        ($this->deliver)($milk, 10, EXPIRY_LATER);
        $sale = ($this->sell)($milk, 4);

        // As a pre-migration receipt looks: no split to put back.
        DB::table('stock_ledger_batches')->delete();

        SaleService::voidSale($sale['saleId'], 'Rung up twice', $this->actor);

        expect(InventoryService::verifyProductStock($milk)['ok'])->toBeTrue()
            ->and(Shop::trialBalanced())->toBeTrue();
    });
});
