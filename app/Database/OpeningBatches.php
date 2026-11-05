<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Support\Facades\DB;

/**
 * Give every product holding stock an undated opening batch, if it has none.
 * A port of the old db/seed/opening-batches.ts, for stock written by a path
 * that does not allocate batches itself (the demo seed).
 *
 * Idempotent: a product that already has any batch is left alone. Undated on
 * purpose: nothing here carries a date anybody entered.
 */
final class OpeningBatches
{
    public static function open(string $receivedDate): int
    {
        return Tx::run(function () use ($receivedDate): int {
            $uncovered = DB::select(
                'SELECT p.id AS id, p.qty_on_hand_milli AS qty, p.is_demo AS isDemo
                 FROM products p
                 WHERE p.track_inventory = 1
                   AND p.qty_on_hand_milli <> 0
                   AND NOT EXISTS (SELECT 1 FROM product_batches b WHERE b.product_id = p.id)
                 ORDER BY p.id
                 FOR UPDATE',
            );

            foreach ($uncovered as $product) {
                DB::table('product_batches')->insert([
                    'product_id' => $product->id,
                    // Outside the BAT-##### sequence, so the first real delivery still opens BAT-00001.
                    'batch_ref' => sprintf('BAT-OPEN-%05d', $product->id),
                    'expiry_date' => null,
                    'received_date' => $receivedDate,
                    'qty_milli' => $product->qty,
                    // Recorded rather than derived: see InventoryService::verifyProductBatches.
                    'opening_qty_milli' => $product->qty,
                    'source_type' => 'OPENING',
                    'is_demo' => (bool) $product->isDemo,
                ]);
            }

            return count($uncovered);
        });
    }

    /** Whether any stock is not yet owned by a batch. */
    public static function countUncovered(): int
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM products
             WHERE products.track_inventory = 1
               AND products.qty_on_hand_milli <> 0
               AND NOT EXISTS (SELECT 1 FROM product_batches b WHERE b.product_id = products.id)',
        )->n;
    }
}
