<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Domain\Inventory\Costing;
use App\Domain\Inventory\StockState;
use App\Domain\JsCompat;
use App\Domain\Quantity;
use App\Enums\AuditAction;
use App\Support\Clock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Products and categories. A port of the old catalog.service.ts.
 *
 * Note what is NOT here: no function sets a product's stock. Quantity and
 * value belong to InventoryService and change only through a recorded stock
 * movement.
 *
 * Inputs are arrays with the old field names. A product input:
 * ['name', 'sku'?, 'barcode'?, 'categoryId'?, 'unit'?, 'altUnit'?, 'altFactor'?,
 *  'altCostPrice'?, 'altSellingPrice'?, 'onlyListedUnits'?, 'description'?,
 *  'costPrice', 'sellingPrice', 'minStock'?, 'warnDays'?, 'trackInventory'?].
 */
final class CatalogService
{
    public const STOCK_STATUSES = ['in-stock', 'low', 'out', 'negative'];

    public const PRODUCT_SORTS = ['name', 'quantity', 'cost', 'price', 'value', 'category'];

    // --- categories ---------------------------------------------------------

    /**
     * Categories with how many products each holds: JOIN + GROUP BY rather
     * than a correlated subquery (the old CLAUDE.md "Drizzle trap").
     *
     * @return list<object>
     */
    public static function listCategories(bool $includeInactive = false): array
    {
        $query = DB::table('categories')
            ->leftJoin('products', 'products.category_id', '=', 'categories.id')
            ->select(['categories.id', 'categories.name', 'categories.description', 'categories.is_active', 'categories.sort_order'])
            ->selectRaw('COUNT(`products`.`id`) AS productCount')
            ->groupBy('categories.id');
        if (! $includeInactive) {
            $query->where('categories.is_active', true);
        }

        return $query->orderBy('categories.sort_order')->orderBy('categories.name')->get()
            ->map(function (object $row): object {
                $row->productCount = (int) $row->productCount;
                $row->is_active = (bool) $row->is_active;

                return $row;
            })->all();
    }

    /** @param  array{name: string, description?: ?string, sortOrder?: int}  $input */
    public static function createCategory(array $input, Actor $actor): int
    {
        $name = JsCompat::trim($input['name']);
        if ($name === '') {
            throw new ValidationError('Enter a category name.');
        }

        return Tx::run(function () use ($input, $name, $actor): int {
            if (DB::table('categories')->whereRaw('LOWER(`categories`.`name`) = LOWER(?)', [$name])->exists()) {
                throw new ConflictError("A category called \"{$name}\" already exists.");
            }
            $now = Clock::now();
            $id = (int) DB::table('categories')->insertGetId([
                'name' => $name,
                'description' => self::normaliseCode($input['description'] ?? null),
                'sort_order' => $input['sortOrder'] ?? 0,
                'created_at' => Clock::stamp($now),
                'updated_at' => Clock::stamp($now),
            ]);
            AuditService::write(AuditAction::Create, 'category', "Created category \"{$name}\"", $id, $actor, null, $now);

            return $id;
        });
    }

    public static function updateCategory(int $id, array $input, Actor $actor): void
    {
        $name = JsCompat::trim($input['name']);
        if ($name === '') {
            throw new ValidationError('Enter a category name.');
        }

        Tx::run(function () use ($id, $input, $name, $actor): void {
            $existing = DB::table('categories')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundError('Category', $id);
            $clash = DB::table('categories')
                ->whereRaw('LOWER(`categories`.`name`) = LOWER(?) AND `categories`.`id` <> ?', [$name, $id])
                ->exists();
            if ($clash) {
                throw new ConflictError("A category called \"{$name}\" already exists.");
            }
            $now = Clock::now();
            DB::table('categories')->where('id', $id)->update([
                'name' => $name,
                'description' => self::normaliseCode($input['description'] ?? null),
                'sort_order' => $input['sortOrder'] ?? (int) $existing->sort_order,
                'updated_at' => Clock::stamp($now),
            ]);
            AuditService::write(AuditAction::Update, 'category', "Updated category \"{$name}\"", $id, $actor, [
                'before' => ['name' => $existing->name],
                'after' => ['name' => $name],
            ], $now);
        });
    }

    /** Categories are archived, never deleted, so old products keep their classification. */
    public static function setCategoryActive(int $id, bool $isActive, Actor $actor): void
    {
        Tx::run(function () use ($id, $isActive, $actor): void {
            $existing = DB::table('categories')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundError('Category', $id);
            $now = Clock::now();
            DB::table('categories')->where('id', $id)->update(['is_active' => $isActive, 'updated_at' => Clock::stamp($now)]);
            AuditService::write(
                $isActive ? AuditAction::Restore : AuditAction::Archive,
                'category',
                ($isActive ? 'Restored' : 'Archived')." category \"{$existing->name}\"",
                $id,
                $actor,
                null,
                $now,
            );
        });
    }

    // --- products -----------------------------------------------------------

    /**
     * The unit columns as stored. The base unit must exist (or is created, for
     * callers that name it in text); the alternate must differ from it.
     */
    private static function unitColumns(array $input, string $fallbackUnit): array
    {
        $onlyListed = (bool) ($input['onlyListedUnits'] ?? false);
        $unit = UnitsService::ensureUnit($input['unit'] ?? $fallbackUnit, $onlyListed);
        if (($input['altUnit'] ?? null) === null || $input['altUnit'] === '') {
            return ['unit' => $unit, 'alt_unit' => null, 'alt_factor_milli' => null, 'alt_cost_price_minor' => null, 'alt_selling_price_minor' => null];
        }
        $altUnit = UnitsService::ensureUnit($input['altUnit'], $onlyListed);
        if ($altUnit === $unit) {
            throw new ValidationError('The alternate unit must be different from the base unit.');
        }

        return [
            'unit' => $unit,
            'alt_unit' => $altUnit,
            'alt_factor_milli' => $input['altFactor'] ?? null,
            'alt_cost_price_minor' => $input['altCostPrice'] ?? null,
            'alt_selling_price_minor' => $input['altSellingPrice'] ?? null,
        ];
    }

    /** True once any stock has moved for the product: its base unit is fixed from then on. */
    public static function productUnitsLocked(int $productId): bool
    {
        return DB::table('stock_ledger')->where('stock_ledger.product_id', $productId)->exists();
    }

    /**
     * True once stock has moved by anything but opening stock: the alternate
     * unit and conversion are fixed from then on. Opening lines keep the unit
     * and factor they were entered in, so a carton size set up wrong can still
     * be put right before the product is bought, sold or adjusted.
     */
    public static function productAltUnitLocked(int $productId): bool
    {
        return DB::table('stock_ledger')
            ->where('stock_ledger.product_id', $productId)
            ->where('stock_ledger.movement_type', '<>', 'OPENING_STOCK')
            ->exists();
    }

    private static function normaliseCode(?string $value): ?string
    {
        $trimmed = $value === null ? '' : JsCompat::trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function assertProductInput(array $input): void
    {
        if (JsCompat::trim($input['name']) === '') {
            throw new ValidationError('Enter a product name.');
        }
        if ($input['costPrice'] < 0) {
            throw new ValidationError('Cost price cannot be negative.');
        }
        if ($input['sellingPrice'] < 0) {
            throw new ValidationError('Selling price cannot be negative.');
        }
        if (($input['minStock'] ?? null) !== null && $input['minStock'] < 0) {
            throw new ValidationError('Reorder level cannot be negative.');
        }
        if (($input['altUnit'] ?? null) !== null && $input['altUnit'] !== '') {
            if (($input['altFactor'] ?? 0) <= 0) {
                throw new ValidationError('Say how many base units are in one alternate unit.');
            }
            if (($input['altCostPrice'] ?? 0) < 0 || ($input['altSellingPrice'] ?? 0) < 0) {
                throw new ValidationError('Alternate unit prices cannot be negative.');
            }
        }
        $warnDays = $input['warnDays'] ?? null;
        if ($warnDays !== null && (! is_int($warnDays) || $warnDays < 0)) {
            throw new ValidationError('The expiry warning period must be a whole number of days.', ['warnDays' => $warnDays]);
        }
    }

    public static function createProduct(array $input, Actor $actor): int
    {
        self::assertProductInput($input);
        $sku = self::normaliseCode($input['sku'] ?? null);
        $barcode = self::normaliseCode($input['barcode'] ?? null);

        return Tx::run(function () use ($input, $sku, $barcode, $actor): int {
            self::assertCodesAvailable($sku, $barcode, null);
            $now = Clock::now();
            $name = JsCompat::trim($input['name']);
            $id = (int) DB::table('products')->insertGetId([
                'name' => $name,
                'sku' => $sku,
                'barcode' => $barcode,
                'category_id' => $input['categoryId'] ?? null,
                ...self::unitColumns($input, 'pcs'),
                'description' => self::normaliseCode($input['description'] ?? null),
                'cost_price_minor' => $input['costPrice'],
                'selling_price_minor' => $input['sellingPrice'],
                'min_stock_milli' => $input['minStock'] ?? null,
                'warn_days' => $input['warnDays'] ?? null,
                'track_inventory' => $input['trackInventory'] ?? true,
                // Stock starts empty. The only way to give a product opening
                // stock is a recorded stock adjustment.
                'qty_on_hand_milli' => 0,
                'stock_value_minor' => 0,
                'is_active' => true,
                'created_by' => $actor->id,
                'created_at' => Clock::stamp($now),
                'updated_at' => Clock::stamp($now),
            ]);
            AuditService::write(AuditAction::Create, 'product', "Created product \"{$name}\"", $id, $actor, [
                'sku' => $sku,
                'barcode' => $barcode,
                'sellingPriceMinor' => $input['sellingPrice'],
            ], $now);

            return $id;
        });
    }

    public static function updateProduct(int $id, array $input, Actor $actor): void
    {
        self::assertProductInput($input);
        $sku = self::normaliseCode($input['sku'] ?? null);
        $barcode = self::normaliseCode($input['barcode'] ?? null);

        Tx::run(function () use ($id, $input, $sku, $barcode, $actor): void {
            $existing = DB::table('products')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundError('Product', $id);
            self::assertCodesAvailable($sku, $barcode, $id);

            // Turning off tracking for a product that holds stock would orphan
            // that value in the ledger.
            if (($input['trackInventory'] ?? null) === false && (int) $existing->qty_on_hand_milli !== 0) {
                throw new ValidationError('Clear this product’s stock before turning off inventory tracking.', ['qtyOnHandMilli' => (int) $existing->qty_on_hand_milli]);
            }

            // The base unit is fixed once any stock has moved; the carton once
            // the product has been bought, sold or adjusted.
            $unitCols = self::unitColumns($input, $existing->unit);
            if ($unitCols['unit'] !== $existing->unit && self::productUnitsLocked($id)) {
                throw new ValidationError("\"{$existing->name}\" already has stock movements, so its unit cannot change. Create a new product for the new unit.");
            }
            $existingFactor = $existing->alt_factor_milli === null ? null : (int) $existing->alt_factor_milli;
            $altChanged = $unitCols['alt_unit'] !== $existing->alt_unit || $unitCols['alt_factor_milli'] !== $existingFactor;
            if ($altChanged && self::productAltUnitLocked($id)) {
                throw new ValidationError("\"{$existing->name}\" has been bought, sold or adjusted, so its alternate unit and conversion cannot change. Create a new product for the new unit.");
            }

            $now = Clock::now();
            $name = JsCompat::trim($input['name']);
            DB::table('products')->where('id', $id)->update([
                'name' => $name,
                'sku' => $sku,
                'barcode' => $barcode,
                'category_id' => $input['categoryId'] ?? null,
                ...$unitCols,
                'description' => self::normaliseCode($input['description'] ?? null),
                'cost_price_minor' => $input['costPrice'],
                'selling_price_minor' => $input['sellingPrice'],
                'min_stock_milli' => $input['minStock'] ?? null,
                'warn_days' => $input['warnDays'] ?? null,
                'track_inventory' => $input['trackInventory'] ?? (bool) $existing->track_inventory,
                'updated_at' => Clock::stamp($now),
            ]);
            AuditService::write(AuditAction::Update, 'product', "Updated product \"{$name}\"", $id, $actor, [
                // Units are recorded too: a corrected carton size changes what
                // every on-hand carton figure means.
                'before' => [
                    'name' => $existing->name,
                    'sellingPriceMinor' => (int) $existing->selling_price_minor,
                    'costPriceMinor' => (int) $existing->cost_price_minor,
                    'unit' => $existing->unit,
                    'altUnit' => $existing->alt_unit,
                    'altFactorMilli' => $existingFactor,
                ],
                'after' => [
                    'name' => $name,
                    'sellingPriceMinor' => $input['sellingPrice'],
                    'unit' => $unitCols['unit'],
                    'altUnit' => $unitCols['alt_unit'],
                    'altFactorMilli' => $unitCols['alt_factor_milli'],
                ],
            ], $now);
        });
    }

    private static function assertCodesAvailable(?string $sku, ?string $barcode, ?int $excludeId): void
    {
        if ($sku !== null) {
            $clash = DB::table('products')
                ->whereRaw('LOWER(`products`.`sku`) = LOWER(?)', [$sku])
                ->when($excludeId !== null, fn (Builder $q) => $q->where('products.id', '<>', $excludeId))
                ->first(['products.id', 'products.name']);
            if ($clash !== null) {
                throw new ConflictError("SKU \"{$sku}\" is already used by \"{$clash->name}\".");
            }
        }
        if ($barcode !== null) {
            $clash = DB::table('products')
                ->where('products.barcode', $barcode)
                ->when($excludeId !== null, fn (Builder $q) => $q->where('products.id', '<>', $excludeId))
                ->first(['products.id', 'products.name']);
            if ($clash !== null) {
                throw new ConflictError("Barcode \"{$barcode}\" is already used by \"{$clash->name}\".");
            }
        }
    }

    /**
     * A product with history is archived, never deleted. Archiving is refused
     * while it holds stock: stock valuation leaves archived products out, so
     * the stock would still sit in the inventory account but vanish from the
     * report meant to agree with it.
     */
    public static function setProductActive(int $id, bool $isActive, Actor $actor): void
    {
        Tx::run(function () use ($id, $isActive, $actor): void {
            $existing = DB::table('products')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundError('Product', $id);
            if (! $isActive && ((int) $existing->qty_on_hand_milli !== 0 || (int) $existing->stock_value_minor !== 0)) {
                throw new ConflictError("\"{$existing->name}\" is not at zero stock. Bring its stock to zero with a stock adjustment before archiving it.");
            }
            // A replaced product restored would be a second live product for the
            // same goods, in the old unit and without its codes.
            if ($isActive && ! $existing->is_active) {
                $replacedBy = DB::table('audit_logs')
                    ->where('audit_logs.entity_type', 'product')
                    ->where('audit_logs.entity_id', (string) $id)
                    ->whereRaw("JSON_EXTRACT(`audit_logs`.`metadata`, '$.newProductId') IS NOT NULL")
                    ->selectRaw("JSON_EXTRACT(`audit_logs`.`metadata`, '$.newProductId') AS newId")
                    ->value('newId');
                if ($replacedBy !== null && $replacedBy !== 'null') {
                    $newId = (int) $replacedBy;
                    $successor = DB::table('products')->where('id', $newId)->value('name');
                    $named = $successor !== null ? "\"{$successor}\" (product #{$newId})" : "product #{$newId}";
                    throw new ConflictError("\"{$existing->name}\" was replaced by {$named} to change its units, so it cannot be restored. Use the product that replaced it.");
                }
            }

            $now = Clock::now();
            DB::table('products')->where('id', $id)->update(['is_active' => $isActive, 'updated_at' => Clock::stamp($now)]);
            AuditService::write(
                $isActive ? AuditAction::Restore : AuditAction::Archive,
                'product',
                ($isActive ? 'Restored' : 'Archived')." product \"{$existing->name}\"",
                $id,
                $actor,
                null,
                $now,
            );
        });
    }

    /** True when a sale, purchase, quotation, adjustment, batch or ledger movement names the product. */
    public static function isProductInUse(int $productId): bool
    {
        foreach (['stock_ledger', 'stock_adjustment_items', 'product_batches', 'purchase_items', 'quotation_items', 'sale_items'] as $table) {
            if (DB::table($table)->where("{$table}.product_id", $productId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** Delete a product that was never used: the way to remove a duplicate typed by mistake. */
    public static function deleteProduct(int $id, Actor $actor): void
    {
        Tx::run(function () use ($id, $actor): void {
            $existing = DB::table('products')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundError('Product', $id);
            if (self::isProductInUse($id)) {
                throw new ConflictError("\"{$existing->name}\" has sales, purchases, quotations or stock history, so it cannot be deleted. Archive it instead.");
            }
            DB::table('products')->where('id', $id)->delete();

            // No DELETE audit action exists; units log their deletes as ARCHIVE the same way.
            // The row is gone, so the trail keeps what it was, in the old app's field names.
            AuditService::write(AuditAction::Archive, 'product', "Deleted product \"{$existing->name}\"", $id, $actor, [
                'deleted' => self::snapshot($existing),
            ], Clock::now());
        });
    }

    /** A products row as the old app's audit snapshot held it: camelCase, booleans, ISO times. */
    private static function snapshot(object $row): array
    {
        $out = [];
        foreach ((array) $row as $column => $value) {
            $value = match (true) {
                in_array($column, ['track_inventory', 'is_active', 'is_demo'], true) => (bool) $value,
                in_array($column, ['created_at', 'updated_at'], true) => Clock::fromStamp($value)->format('Y-m-d\TH:i:s.v\Z'),
                default => $value,
            };
            $out[Str::camel($column)] = $value;
        }

        return $out;
    }

    // --- reads --------------------------------------------------------------

    public static function getLowStockThreshold(): int
    {
        return (int) (DB::table('business_settings')->where('id', 1)->value('low_stock_threshold_milli') ?? 0);
    }

    /** A LIKE pattern matching the text anywhere, with its own wildcards taken literally. */
    public static function containsPattern(string $text): string
    {
        return '%'.addcslashes($text, '%_\\').'%';
    }

    /**
     * Every product filter as one clause, shared by the list, the count and
     * the summary, so totals always describe the rows on screen.
     */
    private static function applyProductConditions(Builder $q, array $query): void
    {
        $fallbackMin = self::getLowStockThreshold();

        if (isset($query['id'])) {
            $q->where('products.id', $query['id']);
        }

        $status = $query['productStatus'] ?? null;
        if ($status === 'active') {
            $q->where('products.is_active', true);
        } elseif ($status === 'archived') {
            $q->where('products.is_active', false);
        } elseif (! ($query['includeInactive'] ?? false)) {
            $q->where('products.is_active', true);
        }

        if (isset($query['categoryId'])) {
            $q->where('products.category_id', $query['categoryId']);
        }

        if (isset($query['supplierId'])) {
            $q->whereRaw(
                'EXISTS (SELECT 1 FROM `purchase_items` JOIN `purchases` ON `purchases`.`id` = `purchase_items`.`purchase_id`
                         WHERE `purchase_items`.`product_id` = `products`.`id` AND `purchases`.`supplier_id` = ?)',
                [$query['supplierId']],
            );
        }

        $stockStatus = $query['stockStatus'] ?? (($query['lowStockOnly'] ?? false) ? 'low' : null);
        if ($stockStatus !== null) {
            // Products that do not track stock have no shelf to be low on.
            $q->where('products.track_inventory', true);
            // The product's own minimum where it has one, the shop's otherwise.
            $threshold = 'COALESCE(`products`.`min_stock_milli`, ?)';
            match ($stockStatus) {
                // "Low" includes what has run out: zero is the most urgent kind of low.
                'low' => $q->whereRaw("`products`.`qty_on_hand_milli` <= {$threshold}", [$fallbackMin]),
                'out' => $q->where('products.qty_on_hand_milli', '<=', 0),
                'negative' => $q->where('products.qty_on_hand_milli', '<', 0),
                'in-stock' => $q->whereRaw("`products`.`qty_on_hand_milli` > {$threshold}", [$fallbackMin]),
            };
        }

        // An EXISTS rather than a join: three crates going off is one product.
        if (isset($query['expiring'])) {
            $asAt = $query['asAt'] ?? Clock::today();
            $shopDays = (int) (DB::table('business_settings')->where('id', 1)->value('expiry_warning_days') ?? 30);
            [$window, $bindings] = $query['expiring'] === 'expired'
                ? ['`product_batches`.`expiry_date` < ?', [$asAt]]
                : ['`product_batches`.`expiry_date` >= ? AND DATEDIFF(`product_batches`.`expiry_date`, ?) <= COALESCE(`product_batches`.`warn_days`, `products`.`warn_days`, ?)', [$asAt, $asAt, $shopDays]];
            $q->whereRaw(
                "EXISTS (SELECT 1 FROM `product_batches`
                         WHERE `product_batches`.`product_id` = `products`.`id`
                           AND `product_batches`.`is_closed` = 0
                           AND `product_batches`.`qty_milli` > 0
                           AND `product_batches`.`expiry_date` IS NOT NULL
                           AND {$window})",
                $bindings,
            );
        }

        $search = isset($query['search']) ? JsCompat::trim($query['search']) : '';
        if ($search !== '') {
            $term = self::containsPattern($search);
            $q->where(fn (Builder $w) => $w
                ->whereRaw('LOWER(`products`.`name`) LIKE LOWER(?)', [$term])
                ->orWhereRaw("LOWER(COALESCE(`products`.`sku`, '')) LIKE LOWER(?)", [$term])
                ->orWhereRaw("LOWER(COALESCE(`products`.`barcode`, '')) LIKE LOWER(?)", [$term]));
        }
    }

    private static function productBase(): Builder
    {
        return DB::table('products')->leftJoin('categories', 'categories.id', '=', 'products.category_id');
    }

    /** @return list<array> ProductListItem rows */
    public static function listProducts(array $query = []): array
    {
        $fallbackMin = self::getLowStockThreshold();
        $q = self::productBase()->select([
            'products.id', 'products.name', 'products.sku', 'products.barcode', 'products.category_id',
            'categories.name as category_name', 'products.unit', 'products.alt_unit', 'products.alt_factor_milli',
            'products.alt_cost_price_minor', 'products.alt_selling_price_minor', 'products.cost_price_minor',
            'products.selling_price_minor', 'products.qty_on_hand_milli', 'products.stock_value_minor',
            'products.min_stock_milli', 'products.warn_days', 'products.track_inventory', 'products.is_active',
        ]);
        self::applyProductConditions($q, $query);
        self::applyProductOrder($q, $query);

        return $q->limit(min($query['limit'] ?? 200, 500))->offset($query['offset'] ?? 0)->get()
            ->map(fn (object $row): array => self::productItem($row, $fallbackMin))
            ->all();
    }

    private static function productItem(object $row, int $fallbackMin): array
    {
        $qty = (int) $row->qty_on_hand_milli;
        $value = (int) $row->stock_value_minor;
        $minStock = $row->min_stock_milli === null ? null : (int) $row->min_stock_milli;
        $altFactor = $row->alt_factor_milli === null ? null : (int) $row->alt_factor_milli;
        $tracked = (bool) $row->track_inventory;

        return [
            'warnDays' => $row->warn_days === null ? null : (int) $row->warn_days,
            'id' => (int) $row->id,
            'name' => $row->name,
            'sku' => $row->sku,
            'barcode' => $row->barcode,
            'categoryId' => $row->category_id === null ? null : (int) $row->category_id,
            'categoryName' => $row->category_name,
            'unit' => $row->unit,
            'altUnit' => $row->alt_unit,
            'altFactor' => $altFactor,
            'altCostPrice' => $altFactor === null ? null
                : ($row->alt_cost_price_minor === null ? Quantity::extendPrice((int) $row->cost_price_minor, $altFactor) : (int) $row->alt_cost_price_minor),
            'altSellingPrice' => $altFactor === null ? null
                : ($row->alt_selling_price_minor === null ? Quantity::extendPrice((int) $row->selling_price_minor, $altFactor) : (int) $row->alt_selling_price_minor),
            // As saved: null means derived from the base price (the edit form shows it blank).
            'storedAltCostPrice' => $row->alt_cost_price_minor === null ? null : (int) $row->alt_cost_price_minor,
            'storedAltSellingPrice' => $row->alt_selling_price_minor === null ? null : (int) $row->alt_selling_price_minor,
            'costPrice' => (int) $row->cost_price_minor,
            'sellingPrice' => (int) $row->selling_price_minor,
            'qtyOnHand' => $qty,
            'stockValue' => $value,
            'averageCost' => Costing::averageUnitCost(new StockState($qty, $value)),
            'minStock' => $minStock,
            'trackInventory' => $tracked,
            'isActive' => (bool) $row->is_active,
            'lowStock' => $tracked && Costing::isLowStock($qty, $minStock, $fallbackMin),
            'outOfStock' => $tracked && Costing::isOutOfStock($qty),
        ];
    }

    /** Sort keys are whitelisted; anything else sorts by name. Ties fall to the id, as SQLite's scan order did. */
    private static function applyProductOrder(Builder $q, array $query): void
    {
        $dir = ($query['direction'] ?? 'asc') === 'asc' ? 'ASC' : 'DESC';
        $column = match ($query['sort'] ?? 'name') {
            'quantity' => '`products`.`qty_on_hand_milli`',
            'cost' => '`products`.`cost_price_minor`',
            'price' => '`products`.`selling_price_minor`',
            'value' => '`products`.`stock_value_minor`',
            'category' => "LOWER(COALESCE(`categories`.`name`, 'zzzz'))",
            default => null,
        };
        if ($column === null) {
            $q->orderByRaw("LOWER(`products`.`name`) {$dir}");
        } else {
            $q->orderByRaw("{$column} {$dir}")->orderByRaw('LOWER(`products`.`name`) ASC');
        }
        $q->orderBy('products.id');
    }

    /** How many products match, ignoring the page. */
    public static function countProducts(array $query = []): int
    {
        $q = self::productBase();
        self::applyProductConditions($q, $query);

        return $q->count();
    }

    /** @return list<object> id, name, sku, is_active: enough for a filter dropdown, uncapped */
    public static function listProductOptions(bool $includeInactive = false): array
    {
        return DB::table('products')
            ->when(! $includeInactive, fn (Builder $q) => $q->where('products.is_active', true))
            ->orderBy('products.name')
            ->get(['products.id', 'products.name', 'products.sku', 'products.is_active'])
            ->all();
    }

    /** One product by id: an indexed lookup, whatever the size of the catalogue. */
    public static function getProduct(int $id): array
    {
        return self::listProducts(['includeInactive' => true, 'id' => $id, 'limit' => 1])[0]
            ?? throw new NotFoundError('Product', $id);
    }

    /** The till's lookup: an active product by exact barcode, or SKU ignoring case. */
    public static function findProductByCode(string $code): ?array
    {
        $trimmed = JsCompat::trim($code);
        if ($trimmed === '') {
            return null;
        }
        $id = DB::table('products')
            ->where('products.is_active', true)
            ->where(fn (Builder $q) => $q->where('products.barcode', $trimmed)->orWhereRaw('LOWER(`products`.`sku`) = LOWER(?)', [$trimmed]))
            ->value('products.id');

        return $id === null ? null : self::getProduct((int) $id);
    }

    /**
     * The headline stock figures for whatever products a filter selects,
     * aggregated in SQL. With no status asked for, an archived product still
     * counts while it holds stock, so this agrees with the inventory account.
     *
     * @return array{productCount: int, trackedCount: int, totalStockValue: int, lowStockCount: int, outOfStockCount: int}
     */
    public static function getStockSummary(array $query = []): array
    {
        $fallbackMin = self::getLowStockThreshold();
        $q = self::productBase();
        if (! isset($query['productStatus']) && ! ($query['includeInactive'] ?? false)) {
            self::applyProductConditions($q, [...$query, 'includeInactive' => true]);
            $q->where(fn (Builder $w) => $w
                ->where('products.is_active', true)
                ->orWhere('products.qty_on_hand_milli', '<>', 0)
                ->orWhere('products.stock_value_minor', '<>', 0));
        } else {
            self::applyProductConditions($q, $query);
        }

        $threshold = 'COALESCE(`products`.`min_stock_milli`, ?)';
        $row = $q->selectRaw(
            'COUNT(*) AS productCount,
             COALESCE(SUM(CASE WHEN `products`.`track_inventory` THEN 1 ELSE 0 END), 0) AS trackedCount,
             COALESCE(SUM(`products`.`stock_value_minor`), 0) AS totalStockValue,
             COALESCE(SUM(CASE WHEN `products`.`track_inventory` AND `products`.`qty_on_hand_milli` <= '.$threshold.'
                               AND `products`.`qty_on_hand_milli` > 0 THEN 1 ELSE 0 END), 0) AS lowStockCount,
             COALESCE(SUM(CASE WHEN `products`.`track_inventory` AND `products`.`qty_on_hand_milli` <= 0 THEN 1 ELSE 0 END), 0) AS outOfStockCount',
            [$fallbackMin],
        )->first();

        return [
            'productCount' => (int) $row->productCount,
            'trackedCount' => (int) $row->trackedCount,
            'totalStockValue' => (int) $row->totalStockValue,
            // "Low" here excludes what has run out: the two are shown side by side.
            'lowStockCount' => (int) $row->lowStockCount,
            'outOfStockCount' => (int) $row->outOfStockCount,
        ];
    }

    /** Whether any stock on the shelf carries a date. */
    public static function hasDatedStock(): bool
    {
        return DB::table('product_batches')
            ->where('product_batches.is_closed', false)
            ->whereNotNull('product_batches.expiry_date')
            ->where('product_batches.qty_milli', '>', 0)
            ->exists();
    }

    /**
     * What the shop needs to be told about dates, counted in products (three
     * crates of the same milk is one thing to deal with). Deliberately no value
     * for expiring-soon stock.
     *
     * @return array{expiredCount: int, expiredQtyMilli: int, expiringSoonCount: int, warningDays: int, uniformWindow: bool}
     */
    public static function getExpirySummary(?string $asAt = null): array
    {
        $asAt ??= Clock::today();
        $warningDays = (int) (DB::table('business_settings')->where('id', 1)->value('expiry_warning_days') ?? 30);

        $expired = DB::table('product_batches')
            ->where('product_batches.is_closed', false)
            ->where('product_batches.qty_milli', '>', 0)
            ->whereNotNull('product_batches.expiry_date')
            ->where('product_batches.expiry_date', '<', $asAt)
            ->selectRaw('COUNT(DISTINCT `product_batches`.`product_id`) AS productCount, COALESCE(SUM(`product_batches`.`qty_milli`), 0) AS qtyMilli')
            ->first();

        // The window that applies to a batch: its own, else its product's, else the shop's.
        $soon = DB::table('product_batches')
            ->join('products', 'products.id', '=', 'product_batches.product_id')
            ->where('product_batches.is_closed', false)
            ->where('product_batches.qty_milli', '>', 0)
            ->whereNotNull('product_batches.expiry_date')
            ->where('product_batches.expiry_date', '>=', $asAt)
            ->whereRaw('DATEDIFF(`product_batches`.`expiry_date`, ?) <= COALESCE(`product_batches`.`warn_days`, `products`.`warn_days`, ?)', [$asAt, $warningDays])
            ->selectRaw('COUNT(DISTINCT `product_batches`.`product_id`) AS productCount,
                         SUM(CASE WHEN `product_batches`.`warn_days` IS NOT NULL OR `products`.`warn_days` IS NOT NULL THEN 1 ELSE 0 END) AS overrides')
            ->first();

        return [
            'expiredCount' => (int) $expired->productCount,
            'expiredQtyMilli' => (int) $expired->qtyMilli,
            'expiringSoonCount' => (int) $soon->productCount,
            'warningDays' => $warningDays,
            'uniformWindow' => (int) ($soon->overrides ?? 0) === 0,
        ];
    }

    /** Active products with no category, used to prompt the owner to tidy up. */
    public static function countUncategorised(): int
    {
        return DB::table('products')->where('products.is_active', true)->whereNull('products.category_id')->count();
    }
}
