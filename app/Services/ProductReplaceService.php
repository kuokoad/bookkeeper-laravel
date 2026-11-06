<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Domain\Quantity;
use App\Enums\AdjustmentReason;
use App\Enums\AuditAction;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Replace a product whose units are locked with a new one in the right units.
 * A port of the old product-replace.service.ts.
 *
 * One transaction: create the new product, take every unit off the old one and
 * put it on the new one in ONE stock adjustment at the SAME total value, move
 * the SKU and barcode across, archive the old one. Value is carried as a
 * total, never through a unit cost, so no pesewa is lost. The count in the new
 * unit was made against what the form showed; if stock moved since, the
 * replacement is refused.
 */
final class ProductReplaceService
{
    /**
     * @param  array  $input  a CatalogService product input for the new product
     * @param  array{expectedOldQty: int, newQty: int}  $move
     * @return array{productId: int, adjustmentNo: ?string}
     */
    public static function replaceProductUnit(int $oldId, array $input, array $move, string $businessDate, Actor $actor): array
    {
        return Tx::run(function () use ($oldId, $input, $move, $businessDate, $actor): array {
            $old = DB::table('products')->where('id', $oldId)->lockForUpdate()->first() ?? throw new NotFoundError('Stock item', $oldId);
            $oldQty = (int) $old->qty_on_hand_milli;
            $oldValue = (int) $old->stock_value_minor;
            $oldFactor = $old->alt_factor_milli === null ? null : (int) $old->alt_factor_milli;
            if (! $old->is_active) {
                throw new ValidationError("\"{$old->name}\" is archived. Restore it first.");
            }
            if (! CatalogService::productUnitsLocked($oldId)) {
                throw new ValidationError("\"{$old->name}\" has no stock movements, so its units can be changed directly. Edit the stock item instead.");
            }
            if ($oldQty !== $move['expectedOldQty']) {
                throw new ConflictError("\"{$old->name}\" now holds ".Quantity::formatWithUnit($oldQty, $old->unit).': stock moved while this form was open. Reload the page, count it again and re-enter the quantity.');
            }

            $onlyListed = (bool) ($input['onlyListedUnits'] ?? false);
            $unit = UnitsService::ensureUnit($input['unit'] ?? $old->unit, $onlyListed);
            $altUnit = ($input['altUnit'] ?? null) !== null && $input['altUnit'] !== '' ? UnitsService::ensureUnit($input['altUnit'], $onlyListed) : null;
            $altFactor = $altUnit === null ? null : ($input['altFactor'] ?? null);
            if ($unit === $old->unit && $altUnit === $old->alt_unit && $altFactor === $oldFactor) {
                throw new ValidationError('The units are the same as before. Change the unit or the conversion.');
            }

            if ($oldQty < 0) {
                throw new ValidationError("\"{$old->name}\" shows negative stock. Count it and correct it with a stock adjustment first.");
            }
            $moving = $oldQty > 0;
            if ($moving && $move['newQty'] <= 0) {
                throw new ValidationError("Enter how much of \"{$old->name}\" you hold, counted in the new unit.");
            }
            // Same base unit: a different figure is a recount, which belongs in a stock count.
            if ($moving && $unit === $old->unit && $move['newQty'] !== $oldQty) {
                $held = Quantity::formatWithUnit($oldQty, $old->unit);
                throw new ValidationError("The base unit stays {$old->unit}, so the new stock item takes the same {$held}. If the shelf holds a different amount, correct it with a stock count first.");
            }
            if ($moving && ($input['trackInventory'] ?? null) === false) {
                throw new ValidationError("The new stock item must track stock, because \"{$old->name}\" holds stock that moves across to it.");
            }
            // A pooled average can leave stock carried below zero; a stock-in cannot carry that.
            if ($moving && $oldValue < 0) {
                throw new ValidationError("\"{$old->name}\" has stock valued below zero, which cannot be moved to a new stock item. Correct its value with a stock count first.");
            }

            // shortcut: dated batches are refused rather than carried across, because a
            // stock adjustment can only open undated stock. Upgrade when shops need it.
            $dated = DB::table('product_batches')
                ->where('product_batches.product_id', $oldId)
                ->where('product_batches.is_closed', false)
                ->where('product_batches.qty_milli', '>', 0)
                ->whereNotNull('product_batches.expiry_date')
                ->exists();
            if ($dated) {
                throw new ValidationError("\"{$old->name}\" holds stock with expiry dates, and moving it would lose them. Sell or write off the dated stock first.");
            }

            // When the stock on the shelf first arrived, so a backdated sale can still find it.
            // shortcut: every batch is pooled into one, taking the earliest arrival and any
            // opening status for all of it. Upgrade by moving stock batch by batch.
            $arrived = DB::table('product_batches')
                ->where('product_batches.product_id', $oldId)
                ->where('product_batches.is_closed', false)
                ->where('product_batches.qty_milli', '>', 0)
                ->selectRaw("MIN(`product_batches`.`received_date`) AS first, MAX(`product_batches`.`source_type` = 'OPENING') AS opening")
                ->first();

            // The codes belong to whatever is on the shelf now: free them first,
            // or the new product would clash with the one it replaces.
            $now = Clock::now();
            DB::table('products')->where('id', $oldId)->update(['sku' => null, 'barcode' => null, 'updated_at' => Clock::stamp($now)]);

            $productId = CatalogService::createProduct([
                ...$input,
                // The unit resolved above, so a caller that names none keeps the old one.
                'unit' => $unit,
                'sku' => $input['sku'] ?? $old->sku,
                'barcode' => $input['barcode'] ?? $old->barcode,
            ], $actor);

            $adjustment = $moving
                ? StockAdjustmentService::createStockAdjustment([
                    'businessDate' => $businessDate,
                    'reason' => AdjustmentReason::Other,
                    'note' => "Unit change: \"{$old->name}\" replaced by stock item #{$productId}",
                    'items' => [
                        ['productId' => $oldId, 'direction' => 'OUT', 'qty' => $oldQty, 'totalCost' => $oldValue],
                        ['productId' => $productId, 'direction' => 'IN', 'qty' => $move['newQty'], 'totalCost' => $oldValue],
                    ],
                ], $actor)
                : null;
            $adjustmentNo = $adjustment['adjustmentNo'] ?? null;

            if ($adjustment !== null) {
                // The move opened the new product's undated batch dated today. The
                // goods are older: a sale recorded for an earlier day must still find them.
                $first = $arrived->first;
                $earlier = $first !== null && $first < $businessDate;
                $opening = (bool) $arrived->opening;
                if ($earlier || $opening) {
                    DB::table('product_batches')
                        ->where('product_batches.product_id', $productId)
                        ->whereNull('product_batches.expiry_date')
                        ->update([
                            ...($earlier ? ['received_date' => $first] : []),
                            ...($opening ? ['source_type' => 'OPENING'] : []),
                        ]);
                }
                // Marks the adjustment as half of a replacement, so it is never voided on its own.
                AuditService::write(
                    AuditAction::Update,
                    'stock_adjustment',
                    "{$adjustment['adjustmentNo']} moved \"{$old->name}\" to stock item #{$productId} for a unit change",
                    $adjustment['adjustmentId'],
                    $actor,
                    [StockAdjustmentService::UNIT_CHANGE_MARKER => ['fromProductId' => $oldId, 'toProductId' => $productId]],
                    $now,
                );
            }

            CatalogService::setProductActive($oldId, false, $actor);

            AuditService::write(AuditAction::Update, 'product', "Replaced \"{$old->name}\" with stock item #{$productId} to change its units", $oldId, $actor, [
                'newProductId' => $productId,
                'adjustmentNo' => $adjustmentNo,
                'before' => ['unit' => $old->unit, 'altUnit' => $old->alt_unit, 'altFactorMilli' => $oldFactor, 'qtyMilli' => $oldQty],
                'after' => ['unit' => $unit, 'altUnit' => $altUnit, 'altFactorMilli' => $altFactor, 'qtyMilli' => $moving ? $move['newQty'] : 0],
                'valueMinor' => $oldValue,
            ], $now);

            return ['productId' => $productId, 'adjustmentNo' => $adjustmentNo];
        });
    }
}
