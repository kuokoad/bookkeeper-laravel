<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/stock-integrity-check.test.ts: the cheap
| check (cache against the ledger's last balance) and the full replay must
| reach the same verdict on what actually goes wrong.
*/

use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->make = fn (string $name): int => Shop::product($this->actor, $name);
    $this->move = fn (int $id, string $direction, int $units, int $cost) => $direction === 'IN'
        ? Shop::openingStock($this->actor, $id, $units * 1000, $cost)
        : Shop::stockOut($this->actor, $id, $units * 1000);
    // Corrupt the cached columns behind the services' back, as a stray write would.
    $this->corrupt = fn (int $id, int $qty, int $value) => DB::table('products')->where('id', $id)->update(['qty_on_hand_milli' => $qty, 'stock_value_minor' => $value]);
    $this->verdicts = fn (array $rows): array => array_column($rows, 'ok', 'productId');
    $this->cheapFor = fn (int $id): array => collect(InventoryService::verifyStockAgainstLedger())->firstWhere('productId', $id);
});

describe('the cheap check and the full replay', function () {
    it('agree that a healthy shop is healthy', function () {
        $milo = ($this->make)('Milo 400g');
        $rice = ($this->make)('Rice 5kg');
        ($this->move)($milo, 'IN', 10, 5_000);
        ($this->move)($rice, 'IN', 4, 12_000);
        ($this->move)($milo, 'OUT', 3, 0);

        expect(collect(InventoryService::verifyStockAgainstLedger())->every(fn (array $r): bool => $r['ok']))->toBeTrue()
            ->and(collect(InventoryService::verifyAllStock())->every(fn (array $r): bool => $r['ok']))->toBeTrue();
    });

    it('agree about a product whose cache has been tampered with', function () {
        $milo = ($this->make)('Milo 400g');
        $rice = ($this->make)('Rice 5kg');
        ($this->move)($milo, 'IN', 10, 5_000);
        ($this->move)($rice, 'IN', 4, 12_000);

        ($this->corrupt)($milo, 99_000, 5_000);

        $cheap = ($this->verdicts)(InventoryService::verifyStockAgainstLedger());
        $deep = ($this->verdicts)(InventoryService::verifyAllStock());
        expect($cheap[$milo])->toBeFalse()
            ->and($deep[$milo])->toBeFalse()
            // …and the untouched product is not dragged down with it.
            ->and($cheap[$rice])->toBeTrue()
            ->and($deep[$rice])->toBeTrue();
    });

    it('agree once the value alone has drifted, quantity intact', function () {
        $milo = ($this->make)('Milo 400g');
        ($this->move)($milo, 'IN', 10, 5_000);

        ($this->corrupt)($milo, 10_000, 9_999);

        expect(($this->verdicts)(InventoryService::verifyStockAgainstLedger())[$milo])->toBeFalse()
            ->and(($this->verdicts)(InventoryService::verifyAllStock())[$milo])->toBeFalse();
    });

    it('agree across a chain that passes through empty', function () {
        // An empty shelf is worth nothing, so value is cleared at zero.
        $milo = ($this->make)('Milo 400g');
        ($this->move)($milo, 'IN', 5, 2_500);
        ($this->move)($milo, 'OUT', 5, 0);
        ($this->move)($milo, 'IN', 3, 2_100);

        $cheap = ($this->cheapFor)($milo);
        $deep = InventoryService::verifyProductStock($milo);
        expect($cheap['ok'])->toBeTrue()
            ->and($deep['ok'])->toBeTrue()
            ->and($cheap['ledgerQty'])->toBe($deep['ledgerQty'])
            ->and($cheap['ledgerValue'])->toBe($deep['ledgerValue']);
    });

    it('report the same figures, not merely the same verdict', function () {
        $milo = ($this->make)('Milo 400g');
        ($this->move)($milo, 'IN', 7, 3_500);
        ($this->move)($milo, 'OUT', 2, 0);

        $cheap = ($this->cheapFor)($milo);
        $deep = InventoryService::verifyProductStock($milo);
        foreach (['cachedQty', 'cachedValue', 'ledgerQty', 'ledgerValue', 'qtyDrift', 'valueDrift'] as $key) {
            expect($cheap[$key])->toBe($deep[$key], $key);
        }
    });
});

describe('a product that has never moved', function () {
    it('is expected to hold nothing, and passes when it does', function () {
        $row = ($this->cheapFor)(($this->make)('Milo 400g'));
        expect($row['ok'])->toBeTrue()->and($row['ledgerQty'])->toBe(0)->and($row['ledgerValue'])->toBe(0);
    });

    it('is caught if it somehow holds something', function () {
        $milo = ($this->make)('Milo 400g');
        ($this->corrupt)($milo, 4_000, 2_000);

        $row = ($this->cheapFor)($milo);
        expect($row['ok'])->toBeFalse()->and($row['qtyDrift'])->toBe(4_000);
    });
});

describe('what the cheap check costs', function () {
    it('reads a fixed number of rows however long the shop has traded', function () {
        $milo = ($this->make)('Milo 400g');
        ($this->move)($milo, 'IN', 500, 250_000);
        for ($i = 0; $i < 60; $i++) {
            ($this->move)($milo, 'OUT', 1, 0);
        }

        expect(DB::table('stock_ledger')->count())->toBeGreaterThan(60);

        // Still correct, and still one row back per product.
        $rows = InventoryService::verifyStockAgainstLedger();
        expect($rows)->toHaveCount(1)
            ->and($rows[0]['ok'])->toBeTrue()
            ->and($rows[0]['ledgerQty'])->toBe(440_000);
    });
});
