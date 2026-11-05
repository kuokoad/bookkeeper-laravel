<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/catalog-lookup.test.ts.
|
| Fetching ONE product must not depend on how many products the shop sells:
| getProduct once paged through the first 500 by name, so everything sorted
| after the five hundredth was unreachable, and that reached the till.
*/

use App\Domain\Errors\NotFoundError;
use App\Services\CatalogService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const LOOKUP_COUNT = 620;

/**
 * A catalogue too big for one page, written straight in so the test is about
 * the lookup rather than the posting path. Names run "Product 0000" upward.
 *
 * @return array{firstId: int, lastId: int}
 */
function lookupCatalogue(int $count): array
{
    $rows = [];
    for ($index = 0; $index < $count; $index++) {
        $padded = str_pad((string) $index, 4, '0', STR_PAD_LEFT);
        $rows[] = ['name' => "Product {$padded}", 'sku' => "SKU-{$padded}", 'barcode' => "BAR-{$padded}", 'unit' => 'pcs', 'cost_price_minor' => 500, 'selling_price_minor' => 800];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('products')->insert($chunk);
    }
    $ids = DB::table('products')->orderBy('id')->pluck('id');

    return ['firstId' => (int) $ids->first(), 'lastId' => (int) $ids->last()];
}

beforeEach(function () {
    Shop::owner();
});

describe('a catalogue larger than one page', function () {
    it('finds a product sorted past the old 500 limit', function () {
        ['lastId' => $lastId] = lookupCatalogue(LOOKUP_COUNT);

        $product = CatalogService::getProduct($lastId);
        expect($product['id'])->toBe($lastId)
            ->and($product['name'])->toBe('Product '.lookupPad(LOOKUP_COUNT - 1));
    });

    it('still finds the first product', function () {
        ['firstId' => $firstId] = lookupCatalogue(LOOKUP_COUNT);
        expect(CatalogService::getProduct($firstId)['name'])->toBe('Product 0000');
    });

    it('scans a barcode for a product past the limit', function () {
        lookupCatalogue(LOOKUP_COUNT);
        $scanned = CatalogService::findProductByCode('BAR-'.lookupPad(LOOKUP_COUNT - 1));

        expect($scanned)->not->toBeNull()
            ->and($scanned['name'])->toBe('Product '.lookupPad(LOOKUP_COUNT - 1));
    });

    it('looks a product up by SKU past the limit too', function () {
        lookupCatalogue(LOOKUP_COUNT);
        expect(CatalogService::findProductByCode('SKU-'.lookupPad(LOOKUP_COUNT - 1))['name'])->toBe('Product '.lookupPad(LOOKUP_COUNT - 1));
    });
});

describe('the id filter', function () {
    it('returns exactly one product', function () {
        ['lastId' => $lastId] = lookupCatalogue(10);
        $rows = CatalogService::listProducts(['includeInactive' => true, 'id' => $lastId]);

        expect($rows)->toHaveCount(1)->and($rows[0]['id'])->toBe($lastId);
    });

    it('finds an archived product, which is what editing one needs', function () {
        ['lastId' => $lastId] = lookupCatalogue(3);
        DB::table('products')->where('id', $lastId)->update(['is_active' => false]);

        expect(CatalogService::getProduct($lastId)['isActive'])->toBeFalse()
            // …and it stays out of the ordinary list.
            ->and(array_column(CatalogService::listProducts(), 'id'))->not->toContain($lastId);
    });

    it('still refuses an id that does not exist', function () {
        lookupCatalogue(3);
        expect(fn () => CatalogService::getProduct(999_999))->toThrow(NotFoundError::class);
    });
});

function lookupPad(int $index): string
{
    return str_pad((string) $index, 4, '0', STR_PAD_LEFT);
}
