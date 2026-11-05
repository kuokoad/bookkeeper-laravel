<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/batch-integrity.test.ts: every unit of
| stock belongs to a batch, and that is provable. The opening-batch helper is
| App\Database\OpeningBatches (the old db/seed/opening-batches.ts).
*/

use App\Database\OpeningBatches;
use App\Services\CatalogService;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const INTEGRITY_TODAY = '2026-08-25';

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->make = fn (string $name): int => Shop::product($this->actor, $name);
    // Stock brought in the ordinary way, through the gateway, which allocates.
    $this->addStock = fn (int $id, int $units, int $cost) => Shop::openingStock($this->actor, $id, $units * 1000, $cost, INTEGRITY_TODAY);
    // Stock on the shelf that no batch owns, as a database from before batches holds it.
    $this->addStockBeforeBatches = fn (int $id, int $units) => DB::table('products')->where('id', $id)->update(['qty_on_hand_milli' => $units * 1000]);
});

describe('a freshly migrated database', function () {
    it('has the batch tables and the two settings', function () {
        $settings = DB::table('business_settings')->where('id', 1)->first();

        expect($settings->expiry_warning_days)->toBe(30)
            ->and((bool) $settings->expiry_blocks_sales)->toBeTrue()
            ->and(DB::table('product_batches')->count())->toBe(0);
    });

    it('reports clean coverage when there is no stock at all', function () {
        expect(collect(InventoryService::verifyBatchCoverage())->every(fn (array $row): bool => $row['ok']))->toBeTrue();
    });

    it('round-trips both settings', function () {
        DB::table('business_settings')->where('id', 1)->update(['expiry_warning_days' => 7, 'expiry_blocks_sales' => false]);

        $settings = DB::table('business_settings')->where('id', 1)->first();
        expect($settings->expiry_warning_days)->toBe(7)->and((bool) $settings->expiry_blocks_sales)->toBeFalse();
    });
});

describe('opening batches', function () {
    it('gives every stocked product exactly one', function () {
        $milo = ($this->make)('Milo 400g');
        $rice = ($this->make)('Rice 5kg');
        ($this->addStockBeforeBatches)($milo, 10);
        ($this->addStockBeforeBatches)($rice, 4);

        expect(OpeningBatches::countUncovered())->toBe(2)
            ->and(OpeningBatches::open(INTEGRITY_TODAY))->toBe(2);

        $batches = DB::table('product_batches')->get();
        expect($batches)->toHaveCount(2)
            ->and($batches->pluck('qty_milli')->sort()->values()->all())->toBe([4_000, 10_000])
            // Undated: nothing here carries a date anybody entered.
            ->and($batches->every(fn ($b): bool => $b->expiry_date === null))->toBeTrue()
            ->and($batches->every(fn ($b): bool => $b->source_type === 'OPENING'))->toBeTrue();
    });

    it('records what each batch began with, rather than leaving it to be derived', function () {
        $milo = ($this->make)('Milo 400g');
        ($this->addStockBeforeBatches)($milo, 10);
        OpeningBatches::open(INTEGRITY_TODAY);

        $batch = DB::table('product_batches')->first();
        expect($batch->opening_qty_milli)->toBe(10_000)->and($batch->qty_milli)->toBe($batch->opening_qty_milli);
    });

    it('skips a product that has no stock', function () {
        ($this->make)('Never stocked');
        expect(OpeningBatches::open(INTEGRITY_TODAY))->toBe(0)
            ->and(DB::table('product_batches')->count())->toBe(0);
    });

    it('carries a negative position into a negative opening batch', function () {
        // The coverage invariant has to hold below zero too.
        $milo = ($this->make)('Milo 400g');
        DB::table('products')->where('id', $milo)->update(['qty_on_hand_milli' => -3_000]);

        expect(OpeningBatches::open(INTEGRITY_TODAY))->toBe(1)
            ->and(DB::table('product_batches')->value('qty_milli'))->toBe(-3_000)
            ->and(collect(InventoryService::verifyBatchCoverage())->every(fn (array $row): bool => $row['ok']))->toBeTrue();
    });

    it('is idempotent — running it twice does not double the shelf', function () {
        $milo = ($this->make)('Milo 400g');
        ($this->addStockBeforeBatches)($milo, 10);

        expect(OpeningBatches::open(INTEGRITY_TODAY))->toBe(1)
            ->and(OpeningBatches::open(INTEGRITY_TODAY))->toBe(0)
            ->and(DB::table('product_batches')->count())->toBe(1)
            ->and(collect(InventoryService::verifyBatchCoverage())->every(fn (array $row): bool => $row['ok']))->toBeTrue();
    });
});

describe('coverage: does every unit belong to a batch?', function () {
    it('is clean once opening batches exist', function () {
        $milo = ($this->make)('Milo 400g');
        ($this->addStock)($milo, 10, 5_000);
        OpeningBatches::open(INTEGRITY_TODAY);

        $coverage = InventoryService::verifyBatchCoverage();
        expect($coverage)->toHaveCount(1)
            ->and($coverage[0]['ok'])->toBeTrue()
            ->and($coverage[0]['productQty'])->toBe(10_000)
            ->and($coverage[0]['batchedQty'])->toBe(10_000)
            ->and($coverage[0]['batchCount'])->toBe(1);
    });

    it('reports stock that no batch owns', function () {
        $milo = ($this->make)('Milo 400g');
        ($this->addStockBeforeBatches)($milo, 10);

        $coverage = InventoryService::verifyBatchCoverage();
        expect($coverage[0]['ok'])->toBeFalse()
            ->and($coverage[0]['drift'])->toBe(10_000)
            ->and($coverage[0]['batchCount'])->toBe(0);
    });

    it('reports a batch quantity tampered with by hand', function () {
        $milo = ($this->make)('Milo 400g');
        ($this->addStock)($milo, 10, 5_000);
        OpeningBatches::open(INTEGRITY_TODAY);

        DB::table('product_batches')->update(['qty_milli' => 4_000]);

        $coverage = InventoryService::verifyBatchCoverage();
        expect($coverage[0]['ok'])->toBeFalse()->and($coverage[0]['drift'])->toBe(6_000);
    });

    it('ignores products that are not stock-tracked', function () {
        $service = CatalogService::createProduct(['name' => 'Delivery', 'costPrice' => 0, 'sellingPrice' => 1_000, 'unit' => 'job', 'trackInventory' => false], $this->actor);
        expect(array_column(InventoryService::verifyBatchCoverage(), 'productId'))->not->toContain($service);
    });
});

describe('per batch: is the cached quantity backed by anything?', function () {
    it('is clean for an untouched opening batch', function () {
        $milo = ($this->make)('Milo 400g');
        ($this->addStock)($milo, 10, 5_000);
        OpeningBatches::open(INTEGRITY_TODAY);

        $checks = InventoryService::verifyProductBatches($milo);
        expect($checks)->toHaveCount(1)
            ->and($checks[0]['ok'])->toBeTrue()
            ->and($checks[0]['allocatedQty'])->toBe(10_000);
    });

    it('catches a cached quantity that no longer matches its opening figure', function () {
        $milo = ($this->make)('Milo 400g');
        ($this->addStock)($milo, 10, 5_000);
        OpeningBatches::open(INTEGRITY_TODAY);

        DB::table('product_batches')->update(['qty_milli' => 7_000]);

        $check = InventoryService::verifyProductBatches($milo)[0];
        expect($check['ok'])->toBeFalse()
            ->and($check['drift'])->toBe(-3_000)
            ->and($check['cachedQty'])->toBe(7_000)
            ->and($check['allocatedQty'])->toBe(10_000);
    });

    it('catches an opening figure edited to cover a wrong quantity', function () {
        $milo = ($this->make)('Milo 400g');
        ($this->addStockBeforeBatches)($milo, 10);
        OpeningBatches::open(INTEGRITY_TODAY);

        DB::table('product_batches')->update(['opening_qty_milli' => 2_000]);

        $check = InventoryService::verifyProductBatches($milo)[0];
        expect($check['ok'])->toBeFalse()->and($check['drift'])->toBe(8_000);
    });
});
