<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\StaleUnitError;
use App\Domain\Errors\ValidationError;
use App\Domain\JsCompat;
use App\Domain\Quantity;
use App\Enums\AuditAction;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Units of measure, and the one place a line's quantity becomes stock.
 *
 * A product has a base unit and at most one alternate unit ("1 Carton = 24
 * Pcs"). Everything downstream of a document line works in the base unit
 * only. resolveLineUnit() is where an entered quantity is checked against its
 * unit's decimals and converted, so every document converts identically.
 *
 * A port of the old units.service.ts. Input arrays use the old field names:
 * ['symbol' => string, 'name' => string, 'decimalPlaces' => int].
 */
final class UnitsService
{
    private static function assertUnitInput(array $input): void
    {
        $symbol = JsCompat::trim((string) $input['symbol']);
        if ($symbol === '') {
            throw new ValidationError('Enter a unit symbol.');
        }
        if (mb_strlen($symbol) > 20) {
            throw new ValidationError('Keep the symbol to 20 characters.');
        }
        if (JsCompat::trim((string) $input['name']) === '') {
            throw new ValidationError('Enter a unit name.');
        }
        $decimals = $input['decimalPlaces'];
        if (! is_int($decimals) || $decimals < 0 || $decimals > Quantity::DECIMALS) {
            throw new ValidationError('Decimal places must be a whole number from 0 to '.Quantity::DECIMALS.'.');
        }
    }

    private static function findBySymbol(string $symbol, bool $lock = false): ?object
    {
        $query = DB::table('units')->whereRaw('LOWER(`units`.`symbol`) = LOWER(?)', [JsCompat::trim($symbol)]);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** @return list<object> each unit with productCount, ordered by symbol ignoring case */
    public static function listUnits(): array
    {
        return DB::table('units')
            ->select(['units.symbol', 'units.name', 'units.decimal_places', 'units.created_at', 'units.updated_at'])
            ->selectRaw('(SELECT COUNT(*) FROM `products` p WHERE p.`unit` = `units`.`symbol` OR p.`alt_unit` = `units`.`symbol`) AS productCount')
            ->orderByRaw('LOWER(`units`.`symbol`)')
            ->get()
            ->map(function (object $unit): object {
                $unit->decimal_places = (int) $unit->decimal_places;
                $unit->productCount = (int) $unit->productCount;

                return $unit;
            })
            ->all();
    }

    public static function getUnit(string $symbol): object
    {
        return self::findBySymbol($symbol) ?? throw new NotFoundError('Unit', $symbol);
    }

    public static function createUnit(array $input, Actor $actor): string
    {
        self::assertUnitInput($input);
        $symbol = JsCompat::trim($input['symbol']);

        return Tx::run(function () use ($input, $symbol, $actor): string {
            if (self::findBySymbol($symbol, lock: true) !== null) {
                throw new ConflictError("There is already a unit called \"{$symbol}\".");
            }
            $now = Clock::now();
            DB::table('units')->insert([
                'symbol' => $symbol,
                'name' => JsCompat::trim($input['name']),
                'decimal_places' => $input['decimalPlaces'],
                'created_at' => Clock::stamp($now),
                'updated_at' => Clock::stamp($now),
            ]);
            AuditService::write(AuditAction::Create, 'unit', "Created unit \"{$symbol}\"", $symbol, $actor, ['symbol' => $symbol, 'decimalPlaces' => $input['decimalPlaces']], $now);

            return $symbol;
        });
    }

    /**
     * A rename carries to every product using the unit. The old app's foreign
     * key cascaded it; MySQL cannot cascade a column that also has a CHECK, so
     * the products are renamed here, in the same transaction (PLAN Q6). Lines
     * already written keep the spelling they were printed with.
     */
    public static function updateUnit(string $symbol, array $input, Actor $actor): string
    {
        self::assertUnitInput($input);
        $next = JsCompat::trim($input['symbol']);

        return Tx::run(function () use ($symbol, $input, $next, $actor): string {
            $existing = self::findBySymbol($symbol, lock: true) ?? throw new NotFoundError('Unit', $symbol);
            $clash = self::findBySymbol($next);
            if ($clash !== null && $clash->symbol !== $existing->symbol) {
                throw new ConflictError("There is already a unit called \"{$next}\".");
            }

            // Narrowing the decimals must not strand a fraction already held:
            // every later line in this unit would be refused, so it could never
            // be sold or written off.
            if ($input['decimalPlaces'] < (int) $existing->decimal_places) {
                $step = 10 ** (Quantity::DECIMALS - $input['decimalPlaces']);
                // Locked first: a till that read the old decimals cannot then
                // commit a fraction after this check has passed.
                DB::table('products')->where('products.unit', $existing->symbol)->orderBy('products.id')->lockForUpdate()->pluck('products.id');
                $misfit = DB::table('products')
                    ->where('products.unit', $existing->symbol)
                    ->whereRaw('(`products`.`qty_on_hand_milli` % ? <> 0 OR COALESCE(`products`.`min_stock_milli`, 0) % ? <> 0)', [$step, $step])
                    ->value('products.name');
                if ($misfit !== null) {
                    throw new ValidationError(
                        "\"{$misfit}\" holds a quantity in {$existing->symbol} that does not fit {$input['decimalPlaces']} decimal places. "
                            .'Adjust its stock or reorder level first.',
                    );
                }
            }

            $now = Clock::now();
            $stamp = Clock::stamp($now);
            if ($next !== $existing->symbol) {
                // The products follow the unit. Checks are off only while the
                // parent and its children are renamed together. Inserting the new
                // row first would avoid the bypass, except for a rename that only
                // changes case ("pcs" to "Pcs"): that collides with the old row on
                // the case-insensitive unique index until the old row is gone.
                DB::statement('SET FOREIGN_KEY_CHECKS = 0');
                try {
                    DB::table('units')->where('symbol', $existing->symbol)->update(['symbol' => $next]);
                    DB::table('products')->where('unit', $existing->symbol)->update(['unit' => $next]);
                    DB::table('products')->where('alt_unit', $existing->symbol)->update(['alt_unit' => $next]);
                } finally {
                    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
                }
            }
            DB::table('units')->where('symbol', $next)->update([
                'name' => JsCompat::trim($input['name']),
                'decimal_places' => $input['decimalPlaces'],
                'updated_at' => $stamp,
            ]);
            AuditService::write(AuditAction::Update, 'unit', "Updated unit \"{$existing->symbol}\"", $next, $actor, [
                'before' => ['symbol' => $existing->symbol, 'decimalPlaces' => (int) $existing->decimal_places],
                'after' => ['symbol' => $next, 'decimalPlaces' => $input['decimalPlaces']],
            ], $now);

            return $next;
        });
    }

    public static function deleteUnit(string $symbol, Actor $actor): void
    {
        Tx::run(function () use ($symbol, $actor): void {
            $existing = self::findBySymbol($symbol, lock: true) ?? throw new NotFoundError('Unit', $symbol);
            $inUse = DB::table('products')
                ->where(fn ($q) => $q->where('products.unit', $existing->symbol)->orWhere('products.alt_unit', $existing->symbol))
                ->exists();
            if ($inUse) {
                throw new ConflictError("\"{$existing->symbol}\" is used by stock items, so it cannot be deleted.");
            }
            DB::table('units')->where('symbol', $existing->symbol)->delete();
            AuditService::write(AuditAction::Archive, 'unit', "Deleted unit \"{$existing->symbol}\"", $existing->symbol, $actor, null, Clock::now());
        });
    }

    /**
     * The stored spelling of a unit, creating it if it does not exist yet.
     *
     * The product form only offers listed units; this is for imports, scripts
     * and seeds that name a unit in text. A unit made this way allows three
     * decimals, and the owner can tighten it on the Units screen. Must run
     * inside the caller's transaction.
     */
    public static function ensureUnit(string $symbol, bool $onlyListed = false): string
    {
        $text = JsCompat::trim($symbol);
        if ($text === '') {
            throw new ValidationError('Choose a unit.');
        }
        $existing = self::findBySymbol($text);
        if ($existing !== null) {
            return $existing->symbol;
        }
        // A form only ever offers listed units, so anything else is a stale tab
        // or a forged request, and must not become a unit nobody chose.
        if ($onlyListed) {
            throw new ValidationError("\"{$text}\" is not a unit. Choose a unit from the list.");
        }
        Tx::assertOpen('Creating a unit');
        $stamp = Clock::stamp(Clock::now());
        DB::table('units')->insert(['symbol' => $text, 'name' => $text, 'decimal_places' => Quantity::DECIMALS, 'created_at' => $stamp, 'updated_at' => $stamp]);

        return $text;
    }

    private static function decimalsOf(string $symbol): int
    {
        $unit = self::findBySymbol($symbol);

        return $unit === null ? Quantity::DECIMALS : (int) $unit->decimal_places;
    }

    /**
     * Check a line's quantity against its unit and convert it to the base unit.
     *
     * $alt says the quantity was entered in the product's alternate unit. The
     * factor is always read from the product here, never taken from the
     * caller. $expectedFactor is the conversion the person entering the line
     * was shown: if the product's has changed since, the line is refused rather
     * than moving a different number of base units than the screen said.
     *
     * @param  object  $product  a products row
     * @return array{entryUnit: ?string, entryQtyMilli: ?int, entryFactorMilli: ?int, baseQty: int, listSellingPrice: int, listCostPrice: int}
     */
    public static function resolveLineUnit(object $product, int $qty, ?bool $alt = null, ?int $expectedFactor = null): array
    {
        if ($alt !== true) {
            Quantity::assertUnitDecimals($qty, self::decimalsOf($product->unit), $product->unit);

            return [
                'entryUnit' => null,
                'entryQtyMilli' => null,
                'entryFactorMilli' => null,
                'baseQty' => $qty,
                'listSellingPrice' => (int) $product->selling_price_minor,
                'listCostPrice' => (int) $product->cost_price_minor,
            ];
        }

        if ($product->alt_unit === null || $product->alt_factor_milli === null) {
            throw new ValidationError("\"{$product->name}\" has no alternate unit.");
        }
        $factor = (int) $product->alt_factor_milli;
        // No alternate price set: the base price times the conversion, so a
        // Carton of 24 is never offered at the price of one piece.
        $altSelling = $product->alt_selling_price_minor === null
            ? Quantity::extendPrice((int) $product->selling_price_minor, $factor)
            : (int) $product->alt_selling_price_minor;
        if ($expectedFactor !== null && $expectedFactor !== $factor) {
            throw new StaleUnitError(
                "One {$product->alt_unit} of \"{$product->name}\" is now ".Quantity::format($factor)
                    ." {$product->unit}, not ".Quantity::format($expectedFactor).' as shown here. Reload and check the quantity.',
                [
                    'productId' => (int) $product->id,
                    'productName' => $product->name,
                    'unit' => $product->unit,
                    'altUnit' => $product->alt_unit,
                    'altFactorMilli' => $factor,
                    'altSellingPriceMinor' => $altSelling,
                    'shownFactorMilli' => $expectedFactor,
                ],
            );
        }
        Quantity::assertUnitDecimals($qty, self::decimalsOf($product->alt_unit), $product->alt_unit);
        $baseQty = Quantity::toBase($qty, $factor);
        Quantity::assertUnitDecimals($baseQty, self::decimalsOf($product->unit), $product->unit);

        return [
            'entryUnit' => $product->alt_unit,
            'entryQtyMilli' => $qty,
            'entryFactorMilli' => $factor,
            'baseQty' => $baseQty,
            'listSellingPrice' => $altSelling,
            'listCostPrice' => $product->alt_cost_price_minor === null
                ? Quantity::extendPrice((int) $product->cost_price_minor, $factor)
                : (int) $product->alt_cost_price_minor,
        ];
    }
}
