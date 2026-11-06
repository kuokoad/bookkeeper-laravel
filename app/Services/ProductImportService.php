<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Errors\DomainError;
use App\Domain\Errors\ValidationError;
use App\Domain\JsCompat;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Enums\AdjustmentReason;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;

/**
 * Adding many products at once from a spreadsheet the shop already keeps. A
 * port of the old product-import.service.ts (and lib/product-import-fields.ts).
 *
 * Two steps, and the first writes nothing: previewProductImport() says, row by
 * row, what would be created and why a row would be skipped. importProducts()
 * re-runs that same preview from the same raw cells (never trusting one sent
 * back) and creates every clean row in one transaction, whole or not at all.
 * Opening stock is ONE OPENING_STOCK adjustment for the whole file.
 *
 * Rows are lists of cell text; row 0 is the heading row. A column map is
 * [field => zero-based column], absent fields are not imported.
 */
final class ProductImportService
{
    /** Field => label, in the order the page shows them. */
    public const IMPORT_FIELDS = [
        'name' => 'Stock item name',
        'sku' => 'Code / SKU',
        'barcode' => 'Barcode',
        'unit' => 'Unit',
        'costPrice' => 'Cost price (per unit)',
        'sellingPrice' => 'Selling price (per unit)',
        'altFactor' => 'Units per pack',
        'altCostPrice' => 'Cost per pack',
        'altSellingPrice' => 'Selling price per pack',
        'minStock' => 'Reorder level',
        'category' => 'Stock group',
        'trackInventory' => 'Track stock (yes / no)',
        'openingQty' => 'Opening quantity',
        'openingValue' => 'Opening stock value',
    ];

    /** Headings each field is recognised by, after lower-casing and squeezing spaces. */
    private const HEADINGS = [
        'name' => ['product', 'product name', 'stock item', 'stock item name', 'name', 'item', 'item name', 'description'],
        'sku' => ['sku', 'code', 'product code', 'stock item code', 'item code'],
        'barcode' => ['barcode', 'bar code'],
        'unit' => ['unit', 'uom'],
        'costPrice' => ['cost price', 'cost', 'unit cost', 'cost per piece', 'cost per unit'],
        'sellingPrice' => ['selling price', 'price', 'sale price', 'selling price per piece', 'selling price per unit'],
        'altFactor' => ['pieces per pack', 'units per pack', 'qty per pack', 'pack size', 'pcs per pack'],
        'altCostPrice' => ['cost per pack', 'pack cost', 'cost price per pack'],
        'altSellingPrice' => ['selling price per pack', 'pack price', 'price per pack'],
        'minStock' => ['reorder level', 'min stock', 'minimum stock', 'reorder'],
        'category' => ['category', 'product stock group', 'stock group'],
        'trackInventory' => ['track stock', 'track inventory'],
        'openingQty' => ['opening quantity', 'opening qty', 'opening stock', 'stock', 'in stock', 'qty on hand', 'quantity on hand'],
        'openingValue' => ['opening value', 'opening stock value', 'stock value'],
    ];

    /** The template's heading row: one heading per field, each one guessColumns() recognises. */
    public const TEMPLATE_HEADINGS = [
        'Stock item name', 'SKU', 'Barcode', 'Unit', 'Cost price', 'Selling price', 'Units per pack', 'Cost per pack',
        'Selling price per pack', 'Reorder level', 'Stock group', 'Track stock', 'Opening quantity', 'Opening value',
    ];

    private const YES = ['yes', 'y', 'true', '1'];

    private const NO = ['no', 'n', 'false', '0'];

    /** At most this many rows in one import; past it the shop should split the file. */
    public const MAX_IMPORT_ROWS = 5_000;

    private static function squeeze(string $text): string
    {
        return mb_strtolower(self::collapse(JsCompat::trim($text)));
    }

    /** Runs of whitespace to one space, as JavaScript's /\s+/g. */
    private static function collapse(string $text): string
    {
        return preg_replace('/'.JsCompat::WHITESPACE.'+/u', ' ', $text) ?? $text;
    }

    /**
     * A first guess at the column for each field. Each column feeds one field;
     * the first matching column wins.
     *
     * @param  list<string>  $header
     * @return array<string, int>
     */
    public static function guessColumns(array $header): array
    {
        $map = [];
        $used = [];
        foreach (array_keys(self::IMPORT_FIELDS) as $key) {
            foreach (array_values($header) as $index => $cell) {
                if (! isset($used[$index]) && in_array(self::squeeze((string) $cell), self::HEADINGS[$key], true)) {
                    $map[$key] = $index;
                    $used[$index] = true;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * A spreadsheet amount in minor units. More than two decimals (a formula's
     * 1.7592592592592591) is rounded half up to the pesewa and noted.
     */
    private static function readMoney(string $text, string $label, array &$notes): int
    {
        if (preg_match('/^(\d+)\.(\d{3,})$/D', JsCompat::trim($text), $long) !== 1) {
            $value = Money::parse($text);
            // createProduct refuses a negative price; say so in the preview.
            if ($value < 0) {
                throw new ValidationError('"'.JsCompat::trim($text).'" is negative.');
            }

            return $value;
        }
        [, $whole, $fraction] = $long;
        $value = Money::fromBig(Money::divRoundHalf(BigInteger::of($whole.$fraction), BigInteger::ten()->power(strlen($fraction) - 2)));
        $notes[] = "{$label} ".self::trimDigits($text).' rounded to '.Money::toDecimalString($value, false);

        return $value;
    }

    /** Units per pack. Float noise like 49.99999999 is refused: a pack size is counted, not priced. */
    private static function readFactor(string $text): int
    {
        if (preg_match('/^(\d+)\.(\d{4,})$/D', JsCompat::trim($text)) === 1) {
            throw new ValidationError('Units per pack "'.self::trimDigits($text).'" is not a whole count.');
        }

        return Quantity::parsePositive($text, 'Units per pack');
    }

    private static function trimDigits(string $text): string
    {
        return preg_replace('/^(\d+\.\d{4})\d+$/D', '$1…', JsCompat::trim($text)) ?? $text;
    }

    private static function message(\Throwable $error): string
    {
        if ($error instanceof DomainError) {
            return $error->userMessage;
        }
        throw $error;
    }

    /**
     * What an import of these rows would do, without doing any of it. Blank
     * rows are left out.
     *
     * @param  list<list<string>>  $rows
     * @param  array<string, int>  $map
     * @return array{rows: list<array>, newUnits: list<string>, warnings: list<string>, ready: int, skipped: int, openingValue: int}
     */
    public static function previewProductImport(array $rows, array $map, string $packUnitText): array
    {
        if (count($rows) - 1 > self::MAX_IMPORT_ROWS) {
            throw new ValidationError('That sheet has more than '.self::MAX_IMPORT_ROWS.' rows. Split it into smaller files.');
        }
        if (! isset($map['name'])) {
            throw new ValidationError('Choose the column that holds the stock item name.');
        }

        $header = array_values($rows[0] ?? []);
        $warnings = [];
        $seenHeadings = [];
        foreach ($header as $i => $cell) {
            $key = self::squeeze((string) $cell);
            if ($key === '') {
                continue;
            }
            if (! isset($seenHeadings[$key])) {
                $seenHeadings[$key] = $i;
            } else {
                $warnings[] = 'Columns '.self::columnLetter($seenHeadings[$key]).' and '.self::columnLetter($i).' are both headed "'.JsCompat::trim((string) $cell).'". Check the right one is chosen below.';
            }
        }

        $listed = [];
        // A unit the import creates gets three decimals, like any unit named in text.
        $decimals = [];
        foreach (DB::table('units')->get(['symbol', 'decimal_places']) as $unit) {
            $listed[mb_strtolower($unit->symbol)] = $unit->symbol;
            $decimals[mb_strtolower($unit->symbol)] = (int) $unit->decimal_places;
        }
        $categoryByName = [];
        foreach (DB::table('categories')->where('categories.is_active', true)->orderBy('categories.id')->get(['id', 'name']) as $category) {
            $categoryByName[mb_strtolower($category->name)] ??= $category;
        }
        $defaultUnit = $listed['pcs'] ?? 'pcs';
        $packUnit = JsCompat::trim($packUnitText);
        $newUnits = [];
        $useUnit = function (string $text) use ($listed, &$newUnits): string {
            $known = $listed[mb_strtolower($text)] ?? null;
            if ($known !== null) {
                return $known;
            }
            $newUnits[$text] = true;

            return $text;
        };

        $takenCodes = array_flip(DB::table('products')->whereNotNull('products.sku')->pluck('sku')->map(fn ($c): string => mb_strtolower($c))->all());
        $takenBarcodes = array_flip(DB::table('products')->whereNotNull('products.barcode')->pluck('barcode')->map(fn ($c): string => mb_strtolower($c))->all());
        $fileCodes = [];
        $fileBarcodes = [];

        $result = [];
        $unnamed = 0;
        foreach (array_slice($rows, 1) as $index => $cells) {
            $cells = array_values($cells);
            $line = $index + 2;
            $cell = fn (string $field): string => isset($map[$field]) ? JsCompat::trim((string) ($cells[$map[$field]] ?? '')) : '';
            $empty = true;
            foreach (array_keys(self::IMPORT_FIELDS) as $key) {
                if ($cell($key) !== '') {
                    $empty = false;
                    break;
                }
            }
            if ($empty) {
                continue;
            }
            // A formula filled down past the list leaves rows of 0.00 with no name
            // and no code: not products, counted rather than listed.
            if ($cell('name') === '' && $cell('sku') === '') {
                $unnamed++;

                continue;
            }

            $problems = [];
            $notes = [];
            $name = self::collapse($cell('name'));
            $sku = $cell('sku');
            $barcode = $cell('barcode');
            if ($name === '') {
                $problems[] = 'No stock item name.';
            }

            if ($sku !== '') {
                $key = mb_strtolower($sku);
                if (isset($takenCodes[$key])) {
                    $problems[] = "Code {$sku} is already used by a stock item.";
                } elseif (isset($fileCodes[$key])) {
                    $problems[] = "Code {$sku} is also on row {$fileCodes[$key]}.";
                } else {
                    $fileCodes[$key] = $line;
                }
            }
            if ($barcode !== '') {
                $key = mb_strtolower($barcode);
                if (isset($takenBarcodes[$key])) {
                    $problems[] = "Barcode {$barcode} is already used by a stock item.";
                } elseif (isset($fileBarcodes[$key])) {
                    $problems[] = "Barcode {$barcode} is also on row {$fileBarcodes[$key]}.";
                } else {
                    $fileBarcodes[$key] = $line;
                }
            }

            $unit = $useUnit($cell('unit') !== '' ? $cell('unit') : $defaultUnit);

            $amount = function (string $field, string $label) use ($cell, &$notes, &$problems): ?int {
                $text = $cell($field);
                if ($text === '') {
                    return null;
                }
                try {
                    return self::readMoney($text, $label, $notes);
                } catch (\Throwable $error) {
                    $problems[] = "{$label}: ".self::message($error);

                    return null;
                }
            };

            $altFactor = null;
            if ($cell('altFactor') !== '') {
                try {
                    $altFactor = self::readFactor($cell('altFactor'));
                } catch (\Throwable $error) {
                    $problems[] = self::message($error);
                }
            }
            $altCostPrice = $amount('altCostPrice', 'Cost per pack');
            $altSellingPrice = $amount('altSellingPrice', 'Selling price per pack');
            $costPrice = $amount('costPrice', 'Cost price');
            $sellingPrice = $amount('sellingPrice', 'Selling price');

            $altUnit = null;
            if ($altFactor !== null) {
                if ($packUnit === '') {
                    $problems[] = 'Name the pack unit to import units per pack.';
                } elseif (mb_strtolower($packUnit) === mb_strtolower($unit)) {
                    $problems[] = 'The pack unit must differ from the unit.';
                } else {
                    $altUnit = $useUnit($packUnit);
                }
            } elseif ($altCostPrice !== null || $altSellingPrice !== null) {
                $problems[] = 'Pack prices need the number of units per pack.';
            }

            // A unit price the sheet does not give is the pack price over the
            // pack size: shown as worked out, never passed off as typed in.
            $perUnit = fn (?int $pack): ?int => $pack !== null && $altFactor !== null
                ? Money::fromBig(Money::divRoundHalf(BigInteger::of($pack)->multipliedBy(1000), BigInteger::of($altFactor)))
                : null;
            $startsWith = fn (string $prefix): bool => array_filter($problems, fn (string $p): bool => str_starts_with($p, $prefix)) !== [];
            if ($sellingPrice === null && $perUnit($altSellingPrice) !== null) {
                $sellingPrice = $perUnit($altSellingPrice);
                $notes[] = 'Selling price worked out from the pack price: '.Money::toDecimalString($sellingPrice, false);
            }
            if ($costPrice === null && $perUnit($altCostPrice) !== null) {
                $costPrice = $perUnit($altCostPrice);
                $notes[] = 'Cost price worked out from the pack cost: '.Money::toDecimalString($costPrice, false);
            }
            if ($sellingPrice === null && ! $startsWith('Selling price')) {
                $problems[] = 'No selling price.';
            }
            if ($costPrice === null && ! $startsWith('Cost price')) {
                $notes[] = 'No cost price — set to 0.00.';
            }

            if ($cell('minStock') !== '') {
                try {
                    if (Quantity::parse($cell('minStock')) < 0) {
                        $problems[] = 'Reorder level cannot be negative.';
                    }
                } catch (\Throwable $error) {
                    $problems[] = 'Reorder level: '.self::message($error);
                }
            }

            $categoryText = self::collapse($cell('category'));
            $category = $categoryText !== '' ? ($categoryByName[mb_strtolower($categoryText)] ?? null) : null;
            if ($categoryText !== '' && $category === null) {
                $problems[] = "Stock group {$categoryText} is not on your Stock groups list.";
            }

            $trackText = mb_strtolower($cell('trackInventory'));
            if ($trackText !== '' && ! in_array($trackText, self::YES, true) && ! in_array($trackText, self::NO, true)) {
                $problems[] = 'Track stock: enter yes or no.';
            }
            $trackInventory = ! in_array($trackText, self::NO, true);

            $openingQty = null;
            if ($cell('openingQty') !== '') {
                try {
                    $openingQty = Quantity::parse($cell('openingQty'));
                    if ($openingQty < 0) {
                        $problems[] = 'Opening quantity cannot be negative.';
                    } elseif ($openingQty !== 0 && ! $trackInventory) {
                        $problems[] = 'Only a stock item whose stock is tracked can have opening stock.';
                    } else {
                        Quantity::assertUnitDecimals($openingQty, $decimals[mb_strtolower($unit)] ?? Quantity::DECIMALS, $unit);
                    }
                } catch (\Throwable $error) {
                    $problems[] = 'Opening quantity: '.self::message($error);
                }
                if ($openingQty === 0) {
                    $openingQty = null;
                }
            }
            $openingValue = $amount('openingValue', 'Opening value');
            if ($openingQty === null) {
                if ($openingValue !== null && $openingValue > 0 && ! $startsWith('Opening quantity')) {
                    $problems[] = 'Opening value needs the opening quantity it is for.';
                }
                $openingValue = null;
            } elseif ($openingValue === null && ! $startsWith('Opening value')) {
                // No value given: the stock is worth what the same row says it cost.
                if ($costPrice === null) {
                    $problems[] = 'Opening stock needs a cost price or an opening value.';
                } else {
                    $openingValue = Quantity::extendPrice($costPrice, $openingQty);
                    $notes[] = 'Opening stock valued at cost: '.Money::toDecimalString($openingValue, false);
                }
            }

            $result[] = [
                'line' => $line,
                'name' => $name,
                'sku' => $sku,
                'unit' => $unit,
                'costPrice' => $costPrice ?? 0,
                'sellingPrice' => $sellingPrice ?? 0,
                'altUnit' => $altUnit,
                'altFactor' => $altFactor,
                'altCostPrice' => $altUnit !== null ? $altCostPrice : null,
                'altSellingPrice' => $altUnit !== null ? $altSellingPrice : null,
                'categoryId' => $category === null ? null : (int) $category->id,
                'categoryName' => $category?->name,
                'trackInventory' => $trackInventory,
                'openingQty' => $openingQty,
                'openingValue' => $openingValue,
                'problems' => $problems,
                'notes' => $notes,
            ];
        }

        if ($unnamed > 0) {
            $warnings[] = "{$unnamed} row".($unnamed === 1 ? ' has' : 's have').' figures but no name or code, and will be left out.';
        }
        $readyRows = array_values(array_filter($result, fn (array $row): bool => $row['problems'] === []));
        // A unit only counts as new if a row that will actually be imported uses it.
        $usedUnits = [];
        foreach ($readyRows as $row) {
            $usedUnits[$row['unit']] = true;
            $usedUnits[$row['altUnit'] ?? ''] = true;
        }

        return [
            'rows' => $result,
            'newUnits' => array_values(array_filter(array_map('strval', array_keys($newUnits)), fn (string $unit): bool => isset($usedUnits[$unit]))),
            'warnings' => $warnings,
            'ready' => count($readyRows),
            'skipped' => count($result) - count($readyRows),
            'openingValue' => Money::sum(array_map(fn (array $row): int => $row['openingValue'] ?? 0, $readyRows)),
        ];
    }

    /**
     * Create every row the preview of these same cells marks ready, and their
     * opening stock, in one transaction. Rows with problems are skipped: the
     * owner saw them listed, with the reason, before pressing import.
     *
     * @return array{created: int, adjustmentNo: ?string}
     */
    public static function importProducts(array $rows, array $map, string $packUnit, string $businessDate, Actor $actor): array
    {
        $preview = self::previewProductImport($rows, $map, $packUnit);
        $ready = array_values(array_filter($preview['rows'], fn (array $row): bool => $row['problems'] === []));
        if ($ready === []) {
            throw new ValidationError('There are no rows ready to import.');
        }

        return Tx::run(function () use ($rows, $map, $ready, $businessDate, $actor): array {
            $opening = [];
            foreach ($ready as $row) {
                $cells = array_values($rows[$row['line'] - 1] ?? []);
                $barcode = isset($map['barcode']) ? JsCompat::trim((string) ($cells[$map['barcode']] ?? '')) : '';
                $minStock = isset($map['minStock']) ? JsCompat::trim((string) ($cells[$map['minStock']] ?? '')) : '';
                $input = [
                    'name' => $row['name'],
                    'sku' => $row['sku'] !== '' ? $row['sku'] : null,
                    'barcode' => $barcode !== '' ? $barcode : null,
                    'unit' => $row['unit'],
                    'altUnit' => $row['altUnit'],
                    'altFactor' => $row['altFactor'],
                    'altCostPrice' => $row['altCostPrice'],
                    'altSellingPrice' => $row['altSellingPrice'],
                    'costPrice' => $row['costPrice'],
                    'sellingPrice' => $row['sellingPrice'],
                    'minStock' => $minStock !== '' ? Quantity::parse($minStock) : null,
                    'categoryId' => $row['categoryId'],
                    'trackInventory' => $row['trackInventory'],
                ];
                try {
                    $productId = CatalogService::createProduct($input, $actor);
                } catch (DomainError $error) {
                    throw new ValidationError("Row {$row['line']} ({$row['name']}): {$error->userMessage} Nothing was imported.");
                }
                if ($row['openingQty'] !== null && $row['openingValue'] !== null) {
                    $opening[] = ['productId' => $productId, 'direction' => 'IN', 'qty' => $row['openingQty'], 'totalCost' => $row['openingValue']];
                }
            }

            $adjustmentNo = $opening === [] ? null : StockAdjustmentService::createStockAdjustment([
                'businessDate' => $businessDate,
                'reason' => AdjustmentReason::OpeningStock,
                'note' => 'Imported with stock items',
                'items' => $opening,
            ], $actor)['adjustmentNo'];

            return ['created' => count($ready), 'adjustmentNo' => $adjustmentNo];
        });
    }

    /** Zero-based column index to its spreadsheet letters: 0 → A, 26 → AA. */
    private static function columnLetter(int $index): string
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + (($n - 1) % 26)).$letters;
        }

        return $letters;
    }
}
