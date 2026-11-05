<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/product-import.test.ts: a supplier price
| list shaped like the shop's own JCG sheet (two columns headed "cost price",
| per-piece costs that are formula output, prices per pack only, and empty
| formatted rows at the bottom).
|
| "lands whole or not at all" made the old database refuse the second product
| with a SQLite trigger. MySQL commits on CREATE TRIGGER, which would end the
| test's transaction, so the second product here is refused by MySQL itself:
| a name longer than the column holds (strict mode errors, never truncates).
| The point tested is the same: the insert after the first fails at the
| database, and nothing of the file stays behind.
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ValidationError;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Services\ProductImportService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

const IMPORT_TODAY = '2026-10-24';

const IMPORT_SHEET = [
    ['PRODUCT', 'PRODUCT CODE', 'cost price', '', 'COST PRICE  ', 'PIECES PER PACK', 'COST PER PACK', 'SELLING PRICE PER PACK'],
    ['KRAFTED BOWL 750ML', 'JCG-KB-RD750', '2', '200', '400', '50', '100', '120'],
    ['KRAFTED BOWL RECTANGULAR 1000ML', 'JCG-KB-R1000', '1.7592592592592591', '900', '1583.33', '50', '87.962962962962948', '130'],
    ['', '', '', '', '', '', '', ''],
    ['LUXURY PAPER BAGS', '', '', '', '', '', '', ''],
    ['POLYMAILERS  25*35', 'JCG-KB-RD750', '0.17592592592592593', '4500', '791.67', '100', '17.59', '60'],
    // The cost formulas, filled down past the end of the list.
    ['', '', '', '', '0', '', '0', ''],
    ['', '', '', '', '0', '', '0', ''],
];

const IMPORT_HEAD = ['Product name', 'Unit', 'Cost price', 'Selling price', 'Category', 'Track stock', 'Opening quantity', 'Opening value'];

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->map = fn (): array => ProductImportService::guessColumns(IMPORT_SHEET[0]);
    $this->preview = fn (array $rows, ?array $map = null): array => ProductImportService::previewProductImport($rows, $map ?? ProductImportService::guessColumns($rows[0]), 'pack');
    $this->run = fn (array $rows): array => ProductImportService::importProducts($rows, ProductImportService::guessColumns($rows[0]), 'pack', IMPORT_TODAY, $this->actor);
    $this->sheet = fn (array ...$rows): array => [IMPORT_HEAD, ...$rows];
    $this->lineOf = fn (array $preview, int $line): array => collect($preview['rows'])->firstWhere('line', $line);
});

describe('product import preview', function () {
    it('takes the first "cost price" column and warns that two share the heading', function () {
        expect(($this->map)())->toMatchArray(['name' => 0, 'sku' => 1, 'costPrice' => 2, 'altFactor' => 5, 'altCostPrice' => 6, 'altSellingPrice' => 7])
            ->and(($this->preview)(IMPORT_SHEET, ($this->map)())['warnings'])->toBe([
                'Columns C and E are both headed "COST PRICE". Check the right one is chosen below.',
                '2 rows have figures but no name or code, and will be left out.',
            ]);
    });

    it('rounds formula costs to the pesewa and says so, and works out the piece price from the pack price', function () {
        [$bowl, $rectangular] = ($this->preview)(IMPORT_SHEET)['rows'];

        expect($bowl)->toMatchArray(['line' => 2, 'costPrice' => 200, 'sellingPrice' => 240, 'altFactor' => 50_000, 'altSellingPrice' => 12_000])
            ->and($bowl['notes'])->toBe(['Selling price worked out from the pack price: 2.40'])
            ->and($rectangular['costPrice'])->toBe(176)
            ->and($rectangular['altCostPrice'])->toBe(8_796)
            ->and($rectangular['notes'])->toContain('Cost price 1.7592… rounded to 1.76');
    });

    it('leaves out blank and nameless rows, and skips a row with no price or a repeated code with the reason', function () {
        $preview = ($this->preview)(IMPORT_SHEET);
        expect(array_column($preview['rows'], 'line'))->toBe([2, 3, 5, 6])
            ->and(($this->lineOf)($preview, 5)['problems'])->toBe(['No selling price.'])
            ->and(($this->lineOf)($preview, 6)['problems'])->toBe(['Code JCG-KB-RD750 is also on row 2.'])
            ->and($preview)->toMatchArray(['ready' => 2, 'skipped' => 2, 'newUnits' => ['pack']]);
    });

    it('skips a code that an existing product already uses', function () {
        CatalogService::createProduct(['name' => 'Old bowl', 'sku' => 'jcg-kb-r1000', 'costPrice' => 0, 'sellingPrice' => 100], $this->actor);
        expect(($this->lineOf)(($this->preview)(IMPORT_SHEET), 3)['problems'])->toBe(['Code JCG-KB-R1000 is already used by a product.']);
    });

    it('writes nothing', function () {
        ($this->preview)(IMPORT_SHEET);
        expect(DB::table('products')->count())->toBe(0)
            ->and(DB::table('units')->where('symbol', 'pack')->exists())->toBeFalse();
    });

    it('marks a negative price as a problem, not a row that fails at import', function () {
        $sheet = [['name', 'selling price', 'cost price'], ['Refund line', '5', '(2.00)']];
        expect(($this->preview)($sheet)['rows'][0]['problems'])->toBe(['Cost price: "(2.00)" is negative.']);
    });
});

describe('importing products', function () {
    it('creates the ready rows with their pack unit and no stock, and skips the rest', function () {
        expect(ProductImportService::importProducts(IMPORT_SHEET, ($this->map)(), 'pack', IMPORT_TODAY, $this->actor))->toBe(['created' => 2, 'adjustmentNo' => null]);

        $created = DB::table('products')->orderBy('id')->get();
        expect($created->map(fn ($p): array => [$p->name, $p->sku, $p->unit, $p->alt_unit, $p->alt_factor_milli, $p->cost_price_minor, $p->selling_price_minor])->all())->toBe([
            ['KRAFTED BOWL 750ML', 'JCG-KB-RD750', 'pcs', 'pack', 50_000, 200, 240],
            ['KRAFTED BOWL RECTANGULAR 1000ML', 'JCG-KB-R1000', 'pcs', 'pack', 50_000, 176, 260],
        ])
            ->and($created->every(fn ($p): bool => $p->qty_on_hand_milli === 0 && $p->stock_value_minor === 0))->toBeTrue()
            ->and(DB::table('stock_ledger')->count())->toBe(0);
    });

    it('re-checks on import, so a code taken since the preview cannot slip in twice', function () {
        ProductImportService::importProducts(IMPORT_SHEET, ($this->map)(), 'pack', IMPORT_TODAY, $this->actor);
        // Same file again: every code is now taken, so nothing is ready.
        expect(fn () => ProductImportService::importProducts(IMPORT_SHEET, ($this->map)(), 'pack', IMPORT_TODAY, $this->actor))->toThrow(ValidationError::class)
            ->and(DB::table('products')->count())->toBe(2);
    });

    it('lands whole or not at all', function () {
        // The second insert fails inside the transaction; the first must not stay behind.
        $sheet = [['name', 'selling price'], ['Tin', '5'], [str_repeat('Bag', 400), '6']];
        expect(fn () => ($this->run)($sheet))->toThrow(QueryException::class)
            ->and(DB::table('products')->count())->toBe(0);
    });
});

describe('importing opening stock', function () {
    it('the template headings are all recognised, one field each', function () {
        $guessed = array_keys(ProductImportService::guessColumns(ProductImportService::TEMPLATE_HEADINGS));
        $fields = array_keys(ProductImportService::IMPORT_FIELDS);
        sort($guessed);
        sort($fields);
        expect($guessed)->toBe($fields);
    });

    it('puts opening stock through one opening adjustment, with the books balanced', function () {
        $result = ($this->run)(($this->sheet)(
            ['Milo 400g', '', '35.00', '45.00', '', '', '48', '1680.00'],
            // No value given: quantity times cost price, 2.5 kg x 12.50 = 31.25.
            ['Rice', 'kg', '12.50', '15.00', '', '', '2.5', ''],
            ['Sugar', '', '8.00', '10.00', '', '', '', ''],
        ));

        expect($result['created'])->toBe(3)
            ->and($result['adjustmentNo'])->not->toBeNull()
            ->and(DB::table('stock_adjustments')->get(['reason', 'business_date'])->map(fn ($a): array => [$a->reason, $a->business_date])->all())->toBe([['OPENING_STOCK', IMPORT_TODAY]])
            ->and(DB::table('journal_entries')->count())->toBe(1)
            ->and(DB::table('stock_ledger')->count())->toBe(2);

        $byName = DB::table('products')->get()->keyBy('name');
        expect([$byName['Milo 400g']->qty_on_hand_milli, $byName['Milo 400g']->stock_value_minor])->toBe([48_000, 168_000])
            ->and([$byName['Rice']->qty_on_hand_milli, $byName['Rice']->stock_value_minor])->toBe([2_500, 3_125])
            ->and([$byName['Sugar']->qty_on_hand_milli, $byName['Sugar']->stock_value_minor])->toBe([0, 0]);
        foreach ($byName as $product) {
            expect(InventoryService::verifyProductStock($product->id)['ok'])->toBeTrue();
        }

        expect(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(171_125)
            ->and(Shop::trialBalanced())->toBeTrue();
    });

    it('shows the opening stock and its total in the preview before anything is written', function () {
        $preview = ($this->preview)(($this->sheet)(['Rice', 'kg', '12.50', '15.00', '', '', '2.5', ''], ['Milo', '', '35', '45', '', '', '2', '70']));
        expect($preview['rows'][0])->toMatchArray(['openingQty' => 2_500, 'openingValue' => 3_125])
            ->and($preview['rows'][0]['notes'])->toContain('Opening stock valued at cost: 31.25')
            ->and($preview['openingValue'])->toBe(10_125)
            ->and(DB::table('stock_ledger')->count())->toBe(0);
    });

    it('skips opening stock it cannot value or count honestly, with the reason', function () {
        $preview = ($this->preview)(($this->sheet)(
            ['Soap', 'pcs', '1.00', '2.00', '', '', '1.5', ''],
            ['Bread', '', '5.00', '6.00', '', 'no', '10', ''],
            ['Tin', '', '', '6.00', '', '', '10', ''],
            ['Ghost', '', '1.00', '2.00', '', '', '', '50.00'],
            ['Minus', '', '1.00', '2.00', '', '', '-3', ''],
            ['Maybe', '', '1.00', '2.00', '', 'sometimes', '', ''],
        ));
        expect(array_column($preview['rows'], 'problems'))->toBe([
            ['Opening quantity: pcs is counted in whole numbers, and 1.5 is not one.'],
            ['Only a product whose stock is tracked can have opening stock.'],
            ['Opening stock needs a cost price or an opening value.'],
            ['Opening value needs the opening quantity it is for.'],
            ['Opening quantity cannot be negative.'],
            ['Track stock: enter yes or no.'],
        ])
            ->and($preview['openingValue'])->toBe(0);
    });

    it('files products under an existing category and refuses one that is not on the list', function () {
        CatalogService::createCategory(['name' => 'Drinks'], $this->actor);
        $rows = ($this->sheet)(['Milo', '', '35', '45', 'drinks', '', '', ''], ['Bread', '', '5', '6', 'Bakery', 'No', '', '']);
        $preview = ($this->preview)($rows);
        expect($preview['rows'][0])->toMatchArray(['categoryName' => 'Drinks', 'problems' => []])
            ->and($preview['rows'][1]['problems'])->toBe(['Category Bakery is not on your Categories list.']);

        ($this->run)($rows);
        $milo = DB::table('products')->first();
        expect($milo->name)->toBe('Milo')
            ->and((bool) $milo->track_inventory)->toBeTrue()
            ->and($milo->category_id)->not->toBeNull();
    });

    it('a product that fails takes the opening stock of the whole file with it', function () {
        expect(fn () => ($this->run)(($this->sheet)(['Tin', '', '1', '5', '', '', '10', ''], [str_repeat('Bag', 400), '', '1', '6', '', '', '', ''])))
            ->toThrow(QueryException::class)
            ->and(DB::table('stock_adjustments')->count())->toBe(0)
            ->and(DB::table('journal_entries')->count())->toBe(0)
            ->and(Shop::balance(ChartOfAccounts::INVENTORY))->toBe(0);
    });
});
