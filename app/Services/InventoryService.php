<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Accounting\PeriodLock;
use App\Domain\BusinessDate;
use App\Domain\Errors\ExpiredStockError;
use App\Domain\Errors\InvariantViolatedError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Domain\Inventory\Batches;
use App\Domain\Inventory\Costing;
use App\Domain\Inventory\StockState;
use App\Domain\JsCompat;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Enums\AuditAction;
use App\Enums\DocType;
use App\Enums\MovementType;
use App\Support\Clock;
use App\Support\Filters\Filters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single gateway through which inventory changes. A port of the old
 * inventory.service.ts.
 *
 * Sales, purchases, returns and adjustments all call recordStockMovement(),
 * so the weighted-average arithmetic exists in one place and every movement
 * lands in the ledger with its running balance attached. It must run inside
 * the caller's transaction: a ledger row and the product cache commit
 * together or not at all.
 *
 * A movement input is an array with the old field names:
 * ['productId', 'direction' => 'IN'|'OUT', 'qty', 'totalCost'?, 'movementType',
 *  'sourceType', 'sourceId'?, 'sourceRef'?, 'businessDate', 'occurredAt' => Carbon,
 *  'userId'?, 'note'?, 'allowNegative'?, 'isDemo'?, 'overridePeriodLock'?, 'batch'?, 'cancels'?].
 *
 * 'cancels' => ['qty', 'cost']: goods put back whose first units are the document's own
 * oversold units, still below zero, at what it charged for them (Costing::stockIn).
 *
 * 'batch' says which batch the stock comes from or lands in:
 *   ['kind' => 'NEW', 'expiryDate'?, 'supplierId'?, 'warnDays'?, 'note'?]  a dated delivery (units already sold from it settle the undated batch first)
 *   ['kind' => 'SOURCE', 'allocations' => [...]]  the exact batches a document used
 *   ['kind' => 'PICK', 'allowExpired'?, 'expiredFirst'?, 'expiryAsAt'?, 'arrivedBy'?]  FEFO
 * Omitted: IN lands in the product's undated batch, OUT picks FEFO without expired stock.
 *
 * Batches carry quantity only, never a cost.
 */
final class InventoryService
{
    public const EXPIRY_BUCKETS = ['expired', 'within7', 'within30', 'within90', 'later', 'undated'];

    private const BUCKET_LABELS = [
        'expired' => 'Already expired',
        'within7' => 'Within 7 days',
        'within30' => 'Within 30 days',
        'within90' => 'Within 90 days',
        'later' => 'Later than 90 days',
        'undated' => 'No date recorded',
    ];

    /** Lock order (Tx): every product a document touches, by id, before anything else it locks. */
    public static function lockProducts(array $productIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        sort($ids);
        if ($ids !== []) {
            DB::table('products')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
        }
    }

    public static function getStockState(int $productId): StockState
    {
        $product = DB::table('products')->where('id', $productId)->first(['qty_on_hand_milli', 'stock_value_minor'])
            ?? throw new NotFoundError('Stock item', $productId);

        return new StockState((int) $product->qty_on_hand_milli, (int) $product->stock_value_minor);
    }

    /**
     * @return array{ledgerId: int, totalCost: int, unitCost: int, state: StockState, residual: int, batchAllocations: list<array>, expiredTaken: list<string>}
     */
    public static function recordStockMovement(array $input): array
    {
        Tx::assertOpen('A stock movement');

        // The product first, by id (Tx lock order), so no other till can read
        // the running pair between here and the write below.
        $product = DB::table('products')->where('id', $input['productId'])->lockForUpdate()->first();

        // Second books-lock checkpoint: a write-off of stock carrying no value
        // posts no journal entry, so the lock is enforced here too.
        PeriodLock::assertOpen($input['businessDate'], JournalService::readLockDate(), ($input['overridePeriodLock'] ?? false) === true);

        if ($product === null) {
            throw new NotFoundError('Stock item', $input['productId']);
        }
        if (! $product->track_inventory) {
            throw new ValidationError("\"{$product->name}\" is not stock-tracked, so its stock cannot be moved.", ['productId' => $input['productId']]);
        }
        if ($input['qty'] <= 0) {
            throw new ValidationError('Stock movement quantity must be greater than zero.', ['qty' => $input['qty']]);
        }

        $current = new StockState((int) $product->qty_on_hand_milli, (int) $product->stock_value_minor);
        $allowNegative = ($input['allowNegative'] ?? false) === true;
        $in = $input['direction'] === 'IN';

        // Only what is still below zero can be cancelled; a delivery since may have filled some of it.
        $cancels = $input['cancels'] ?? ['qty' => 0, 'cost' => 0];
        $cancelsQty = min($cancels['qty'], max(0, -$current->qty));
        $cancelsCost = $cancelsQty === $cancels['qty'] ? $cancels['cost'] : Money::mulDiv($cancels['cost'], $cancelsQty, $cancels['qty']);

        $movement = match (true) {
            $in => Costing::stockIn($current, $input['qty'], self::requireTotalCost($input), $cancelsQty, $cancelsCost),
            isset($input['totalCost']) => Costing::stockOutAtCost($current, $input['qty'], $input['totalCost'], $allowNegative, $product->name),
            default => Costing::stockOut($current, $input['qty'], (int) $product->cost_price_minor, $allowNegative, $product->name),
        };

        $at = Clock::stamp($input['occurredAt']);
        $type = $input['movementType'] instanceof MovementType ? $input['movementType']->value : $input['movementType'];
        $ledgerId = (int) DB::table('stock_ledger')->insertGetId([
            'product_id' => $input['productId'],
            'business_date' => $input['businessDate'],
            'occurred_at' => $at,
            'movement_type' => $type,
            'source_type' => $input['sourceType'],
            'source_id' => $input['sourceId'] ?? null,
            'source_ref' => $input['sourceRef'] ?? null,
            'qty_in_milli' => $in ? $input['qty'] : 0,
            'qty_out_milli' => $in ? 0 : $input['qty'],
            'unit_cost_minor' => $movement->unitCost,
            'total_cost_minor' => $movement->totalCost,
            'balance_qty_milli' => $movement->state->qty,
            'balance_value_minor' => $movement->state->value,
            'cancels_qty_milli' => $in ? $cancelsQty : 0,
            'cancels_cost_minor' => $in ? $cancelsCost : 0,
            'note' => $input['note'] ?? null,
            'user_id' => $input['userId'] ?? null,
            'is_demo' => $input['isDemo'] ?? false,
            'created_at' => $at,
        ]);

        // The cache, from the movement just computed. The ledger row is the truth.
        DB::table('products')->where('id', $input['productId'])->update([
            'qty_on_hand_milli' => $movement->state->qty,
            'stock_value_minor' => $movement->state->value,
            'updated_at' => $at,
        ]);

        // Which physical units moved, in the same transaction, so a shelf is
        // never left holding stock no batch owns.
        $allocations = self::allocateMovementToBatches($input, $ledgerId);

        return [
            'ledgerId' => $ledgerId,
            'totalCost' => $movement->totalCost,
            'unitCost' => $movement->unitCost,
            'state' => $movement->state,
            'residual' => $movement->residual,
            'batchAllocations' => $allocations,
            'expiredTaken' => self::expiredRefsAmong($input, $allocations),
        ];
    }

    // --- batch allocation ---------------------------------------------------

    /** @return list<array> */
    private static function allocateMovementToBatches(array $input, int $ledgerId): array
    {
        $in = $input['direction'] === 'IN';
        $allocations = $in ? self::allocateIn($input) : self::allocateOut($input);
        $at = Clock::stamp($input['occurredAt']);

        foreach ($allocations as $allocation) {
            DB::table('stock_ledger_batches')->insert([
                'ledger_id' => $ledgerId,
                'batch_id' => $allocation['batchId'],
                'qty_in_milli' => $in ? $allocation['qtyMilli'] : 0,
                'qty_out_milli' => $in ? 0 : $allocation['qtyMilli'],
                'created_at' => $at,
            ]);
            self::applyToBatchCache($allocation['batchId'], $in, $allocation['qtyMilli'], $at);
        }

        return $allocations;
    }

    /** Stock arriving: into a new batch (after settling what the undated one is below zero by), back where it came from, or undated. */
    private static function allocateIn(array $input): array
    {
        $directive = $input['batch'] ?? null;

        if (($directive['kind'] ?? null) === 'NEW') {
            // Units sold before the delivery was entered came out of it and left the
            // undated batch below zero. That debt is settled first, so the dated
            // batch holds only what is still on the shelf (the old app dated it all).
            $undated = DB::table('product_batches')
                ->where('product_batches.product_id', $input['productId'])
                ->whereNull('product_batches.expiry_date')
                ->orderBy('product_batches.id')
                ->lockForUpdate()
                ->first(['id', 'batch_ref', 'qty_milli']);
            // (If those sold units are later returned by the customer they go back undated, where their sale recorded them.)
            $settle = $undated === null ? 0 : min($input['qty'], max(0, -(int) $undated->qty_milli));
            $allocations = $settle === 0 ? [] : [['batchId' => (int) $undated->id, 'batchRef' => $undated->batch_ref, 'qtyMilli' => $settle]];
            if ($input['qty'] > $settle) {
                $batch = self::openBatch($input, $directive);
                $allocations[] = ['batchId' => $batch['id'], 'batchRef' => $batch['batchRef'], 'qtyMilli' => $input['qty'] - $settle];
            }

            return $allocations;
        }

        if (($directive['kind'] ?? null) === 'SOURCE') {
            // Back in the batches they left from. A batch that emptied is
            // reopened rather than replaced, or the recall trail breaks.
            $back = Batches::allocateProportional($directive['allocations'], $input['qty']);
            foreach ($back as $allocation) {
                self::reopenIfClosed($allocation['batchId']);
            }

            return $back;
        }

        $undated = self::findOrOpenUndatedBatch($input);

        return [['batchId' => $undated['id'], 'batchRef' => $undated['batchRef'], 'qtyMilli' => $input['qty']]];
    }

    /** Stock leaving: back to its source if a document named one, otherwise FEFO. */
    private static function allocateOut(array $input): array
    {
        $directive = $input['batch'] ?? null;
        $pick = ($directive['kind'] ?? null) === 'PICK';
        $expiredFirst = $pick && ($directive['expiredFirst'] ?? false) === true;
        $approved = $expiredFirst || ($pick && ($directive['allowExpired'] ?? false) === true);
        $expiryAsAt = $pick && isset($directive['expiryAsAt']) ? $directive['expiryAsAt'] : $input['businessDate'];

        if (($directive['kind'] ?? null) === 'SOURCE') {
            return self::allocateOutToSource($input, $directive['allocations']);
        }

        $all = DB::table('product_batches')
            ->where('product_batches.product_id', $input['productId'])
            ->where('product_batches.is_closed', false)
            ->orderBy('product_batches.id')
            ->lockForUpdate()
            ->get(['id', 'batch_ref', 'expiry_date', 'qty_milli', 'received_date', 'source_type'])
            ->map(fn (object $b): array => [
                'id' => (int) $b->id,
                'batchRef' => $b->batch_ref,
                'expiryDate' => $b->expiry_date,
                'qtyMilli' => (int) $b->qty_milli,
                'receivedDate' => $b->received_date,
                'sourceType' => $b->source_type,
            ])->all();

        // A sale the till records on an earlier day leaves from what was on the
        // shelf that day. OPENING batches predate batches altogether, so they
        // count as on the shelf for any day.
        $arrivedBy = $pick ? ($directive['arrivedBy'] ?? null) : null;
        $open = $arrivedBy === null
            ? $all
            : array_values(array_filter($all, fn (array $b): bool => $b['sourceType'] === 'OPENING' || $b['receivedDate'] <= $arrivedBy));
        $openIds = array_column($open, 'id');
        $later = array_values(array_filter($all, fn (array $b): bool => ! in_array($b['id'], $openIds, true)));

        if ($expiredFirst) {
            $written = Batches::allocateFefo($open, $input['qty'], $expiryAsAt, true, true);
            if ($written['shortfall'] > 0) {
                self::refuseIfOnlyLaterStock($input, $written, $later);

                return self::withShortfall($input, $written);
            }

            return $written['allocations'];
        }

        // Planned WITHOUT expired stock first, always: on the ordinary path that
        // is also the answer, so an old crate never interrupts the till.
        $plan = Batches::allocateFefo($open, $input['qty'], $expiryAsAt, false);

        if ($plan['expiredNeeded'] > 0) {
            if (! $approved && self::expiryBlocksSales()) {
                $name = DB::table('products')->where('id', $input['productId'])->value('name');
                throw new ExpiredStockError($name ?? 'this stock item', Batches::formatQty($plan['expiredNeeded']), $plan['expiredRefs']);
            }
            // Approved, or the shop does not block on dates: plan again with the
            // expired batches, or the stock would leave with no batch giving it up.
            $plan = Batches::allocateFefo($open, $input['qty'], $expiryAsAt, true);
        }

        if ($plan['shortfall'] > 0) {
            self::refuseIfOnlyLaterStock($input, $plan, $later);

            return self::withShortfall($input, $plan);
        }

        return $plan['allocations'];
    }

    /**
     * Refuse a backdated sale that only stock received after its date could
     * cover. The stock exists, it had not arrived yet, which is nearly always
     * a wrong date. A shop that allows negative stock carries the gap instead.
     */
    private static function refuseIfOnlyLaterStock(array $input, array $plan, array $later): void
    {
        if (($input['allowNegative'] ?? false) === true) {
            return;
        }
        if (array_filter($later, fn (array $b): bool => $b['qtyMilli'] > 0) === []) {
            return;
        }
        $product = DB::table('products')->where('id', $input['productId'])->first(['name', 'unit']);
        $onShelf = $input['qty'] - $plan['shortfall'];

        throw new ValidationError(
            'On '.self::formatDate($input['businessDate']).' there was only '.Batches::formatQty($onShelf).' '
                .($product->unit ?? '').' of '.($product->name ?? 'this stock item').' in stock; the rest arrived later. Check the date.',
            ['productId' => $input['productId'], 'businessDate' => $input['businessDate'], 'shortfall' => $plan['shortfall']],
        );
    }

    /**
     * The batches cannot cover what the product says is there. The costing
     * engine has already decided whether an oversell is allowed.
     */
    private static function withShortfall(array $input, array $plan): array
    {
        if (($input['allowNegative'] ?? false) === true) {
            // The shortfall drives the undated batch below zero, merged (one
            // movement may touch a batch once: uq_stock_ledger_batches).
            $undated = self::findOrOpenUndatedBatch($input);

            return self::mergeByBatch([...$plan['allocations'], ['batchId' => $undated['id'], 'batchRef' => $undated['batchRef'], 'qtyMilli' => $plan['shortfall']]]);
        }

        throw new InvariantViolatedError(
            'Stock exists that no batch owns: '.Batches::formatQty($plan['shortfall'])." of stock item {$input['productId']}.",
            ['productId' => $input['productId'], 'shortfall' => $plan['shortfall']],
        );
    }

    /** Fold repeated batches into one line, keeping first-touched order. */
    private static function mergeByBatch(array $allocations): array
    {
        $merged = [];
        foreach ($allocations as $allocation) {
            if (isset($merged[$allocation['batchId']])) {
                $merged[$allocation['batchId']]['qtyMilli'] += $allocation['qtyMilli'];
            } else {
                $merged[$allocation['batchId']] = $allocation;
            }
        }

        return array_values($merged);
    }

    /**
     * Take stock back out of the batches a document put it in. What is left of
     * their crate goes back first; the remainder falls through to ordinary
     * picking, expired batches included (a void is not a sale).
     */
    private static function allocateOutToSource(array $input, array $source): array
    {
        $held = DB::table('product_batches')
            ->where('product_batches.product_id', $input['productId'])
            ->orderBy('product_batches.id')
            ->lockForUpdate()
            ->pluck('qty_milli', 'id')
            ->map(fn ($qty): int => (int) $qty)
            ->all();

        $allocations = [];
        $short = 0;
        foreach (Batches::allocateProportional($source, $input['qty']) as $allocation) {
            $available = max(0, $held[$allocation['batchId']] ?? 0);
            $take = min($available, $allocation['qtyMilli']);
            if ($take > 0) {
                $allocations[] = [...$allocation, 'qtyMilli' => $take];
            }
            $short += $allocation['qtyMilli'] - $take;
        }

        if ($short === 0) {
            return $allocations;
        }

        // The document's own crates first: a delivery that settled an oversell shows part of itself in
        // the undated batch, which holds none of it, so that part comes out of the delivery's dated crate.
        foreach ($source as $own) {
            $takenHere = array_sum(array_column(array_filter($allocations, fn (array $a): bool => $a['batchId'] === $own['batchId']), 'qtyMilli'));
            $more = min($short, max(0, $held[$own['batchId']] ?? 0) - $takenHere);
            if ($more > 0) {
                $allocations[] = ['batchId' => $own['batchId'], 'batchRef' => $own['batchRef'], 'qtyMilli' => $more];
                $short -= $more;
            }
        }
        $allocations = self::mergeByBatch($allocations);
        if ($short === 0) {
            return $allocations;
        }

        // Then what is still on the shelf in any other crate.
        $taken = array_column($allocations, 'qtyMilli', 'batchId');
        $rest = DB::table('product_batches')
            ->where('product_batches.product_id', $input['productId'])
            ->where('product_batches.is_closed', false)
            ->orderBy('product_batches.id')
            ->get(['id', 'batch_ref', 'expiry_date', 'qty_milli'])
            ->map(fn (object $b): array => ['id' => (int) $b->id, 'batchRef' => $b->batch_ref, 'expiryDate' => $b->expiry_date, 'qtyMilli' => (int) $b->qty_milli - ($taken[(int) $b->id] ?? 0)])
            ->filter(fn (array $b): bool => $b['qtyMilli'] > 0 || ! isset($taken[$b['id']]))
            ->values()
            ->all();

        $plan = $rest === []
            ? ['allocations' => [], 'shortfall' => $short]
            : Batches::allocateFefo($rest, $short, $input['businessDate'], true);

        $merged = self::mergeByBatch([...$allocations, ...$plan['allocations']]);
        if ($plan['shortfall'] === 0) {
            return $merged;
        }

        if (($input['allowNegative'] ?? false) === true) {
            $undated = self::findOrOpenUndatedBatch($input);

            return self::mergeByBatch([...$merged, ['batchId' => $undated['id'], 'batchRef' => $undated['batchRef'], 'qtyMilli' => $plan['shortfall']]]);
        }

        throw new InvariantViolatedError(
            'Stock exists that no batch owns: '.Batches::formatQty($plan['shortfall'])." of stock item {$input['productId']}.",
            ['productId' => $input['productId'], 'shortfall' => $plan['shortfall']],
        );
    }

    /**
     * Which of these batches had passed their date on the day the picking
     * judged them by: the day of the movement, or today for a backdated sale
     * (expiryAsAt), which is the day somebody had to say yes. Only a movement
     * given the right can have used it, so the ordinary till returns before
     * touching the database.
     */
    private static function expiredRefsAmong(array $input, array $allocations): array
    {
        $directive = $input['batch'] ?? null;
        $approved = ($directive['kind'] ?? null) === 'PICK' && ($directive['allowExpired'] ?? false) === true;
        if (! $approved || $allocations === []) {
            return [];
        }
        $asAt = $directive['expiryAsAt'] ?? $input['businessDate'];
        $dates = DB::table('product_batches')->where('product_batches.product_id', $input['productId'])->pluck('expiry_date', 'id')->all();

        return array_values(array_map(
            fn (array $a): string => $a['batchRef'],
            array_filter($allocations, fn (array $a): bool => ($dates[$a['batchId']] ?? null) !== null && $dates[$a['batchId']] < $asAt),
        ));
    }

    /** Shop policy: is expired stock refused at the till? */
    private static function expiryBlocksSales(): bool
    {
        $blocks = DB::table('business_settings')->where('id', 1)->value('expiry_blocks_sales');

        return $blocks === null ? true : (bool) $blocks;
    }

    /** @return array{id: int, batchRef: string} */
    private static function openBatch(array $input, array $directive): array
    {
        $expiryDate = $directive['expiryDate'] ?? null;
        if ($expiryDate !== null) {
            BusinessDate::assert($expiryDate, 'expiry date');
        }

        $batchRef = SequenceService::next(DocType::Batch);
        $at = Clock::stamp($input['occurredAt']);
        $id = (int) DB::table('product_batches')->insertGetId([
            'product_id' => $input['productId'],
            'batch_ref' => $batchRef,
            'expiry_date' => $expiryDate,
            'received_date' => $input['businessDate'],
            // Opens empty and is filled by the allocation about to be written,
            // so the ledger is the whole story for it.
            'qty_milli' => 0,
            'opening_qty_milli' => 0,
            'source_type' => $input['sourceType'],
            'source_id' => $input['sourceId'] ?? null,
            'supplier_id' => $directive['supplierId'] ?? null,
            // Left null unless the delivery says otherwise: readers resolve the
            // batch, product, shop cascade, so a later change to the product reaches it.
            'warn_days' => $directive['warnDays'] ?? null,
            'note' => $directive['note'] ?? null,
            'is_demo' => $input['isDemo'] ?? false,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return ['id' => $id, 'batchRef' => $batchRef];
    }

    /** The product's one undated batch, opened if it has none. */
    private static function findOrOpenUndatedBatch(array $input): array
    {
        $existing = DB::table('product_batches')
            ->where('product_batches.product_id', $input['productId'])
            ->whereNull('product_batches.expiry_date')
            ->orderBy('product_batches.id')
            ->lockForUpdate()
            ->first(['id', 'batch_ref']);

        if ($existing !== null) {
            self::reopenIfClosed((int) $existing->id);

            return ['id' => (int) $existing->id, 'batchRef' => $existing->batch_ref];
        }

        return self::openBatch($input, ['kind' => 'NEW', 'expiryDate' => null]);
    }

    /** A batch receiving stock again must not stay marked empty. */
    private static function reopenIfClosed(int $batchId): void
    {
        DB::table('product_batches')->where('id', $batchId)->where('is_closed', true)->update(['is_closed' => false]);
    }

    /**
     * Move a batch's cached quantity, and close it when it empties exactly. A
     * negative position stays open: it is a debt against the next delivery.
     */
    private static function applyToBatchCache(int $batchId, bool $in, int $qtyMilli, string $at): void
    {
        $batch = DB::table('product_batches')->where('id', $batchId)->lockForUpdate()->first(['qty_milli'])
            ?? throw new InvariantViolatedError('Batch vanished mid-movement.', ['batchId' => $batchId]);
        $next = $in ? Quantity::add((int) $batch->qty_milli, $qtyMilli) : Quantity::subtract((int) $batch->qty_milli, $qtyMilli);

        DB::table('product_batches')->where('id', $batchId)->update(['qty_milli' => $next, 'is_closed' => $next === 0, 'updated_at' => $at]);
    }

    private static function requireTotalCost(array $input): int
    {
        if (! isset($input['totalCost'])) {
            throw new ValidationError('Receiving stock requires the total cost of the goods.', ['productId' => $input['productId']]);
        }

        return $input['totalCost'];
    }

    /** "05 Aug 2026", as the old app's en-GB Intl format (which writes "Sept"). */
    public static function formatDate(string $businessDate): string
    {
        $date = Carbon::createFromFormat('!Y-m-d', $businessDate, 'UTC');

        return $date->format('d').' '.($date->month === 9 ? 'Sept' : $date->format('M')).' '.$date->format('Y');
    }

    // --- batch reads --------------------------------------------------------

    /**
     * The batch split of each stock movement a document made for one product,
     * oldest movement first: one list per ledger row. An empty answer is not a
     * failure: movements made before batches existed have no split.
     *
     * @return list<list<array>>
     */
    public static function readBatchSplits(string $sourceType, int $sourceId, int $productId): array
    {
        $rows = DB::table('stock_ledger_batches')
            ->join('stock_ledger', 'stock_ledger.id', '=', 'stock_ledger_batches.ledger_id')
            ->join('product_batches', 'product_batches.id', '=', 'stock_ledger_batches.batch_id')
            ->where('stock_ledger.source_type', $sourceType)
            ->where('stock_ledger.source_id', $sourceId)
            ->where('stock_ledger.product_id', $productId)
            ->where('stock_ledger.id', '>', self::lastEditMark($sourceType, $sourceId))
            ->orderBy('stock_ledger_batches.ledger_id')
            ->orderBy('stock_ledger_batches.id')
            ->get(['stock_ledger_batches.ledger_id', 'stock_ledger_batches.batch_id', 'product_batches.batch_ref', 'stock_ledger_batches.qty_in_milli', 'stock_ledger_batches.qty_out_milli']);

        $byMovement = [];
        foreach ($rows as $row) {
            $moved = (int) $row->qty_in_milli + (int) $row->qty_out_milli;
            if ($moved <= 0) {
                continue;
            }
            $byMovement[(int) $row->ledger_id][] = ['batchId' => (int) $row->batch_id, 'batchRef' => $row->batch_ref, 'qtyMilli' => $moved];
        }

        return array_values($byMovement);
    }

    /**
     * Per current movement of the document for this product, in order (as readBatchSplits): the
     * units it took beyond what was on the shelf, and what it charged for them (all it charged less
     * the value it found). Putting the goods back cancels these first (Costing::stockIn).
     *
     * @return list<array{qty: int, cost: int, ledgerId: int}>
     */
    public static function readOversold(string $sourceType, int $sourceId, int $productId): array
    {
        return DB::table('stock_ledger')->where('stock_ledger.source_type', $sourceType)->where('stock_ledger.source_id', $sourceId)
            ->where('stock_ledger.product_id', $productId)->where('stock_ledger.id', '>', self::lastEditMark($sourceType, $sourceId))
            ->orderBy('stock_ledger.id')->get(['id', 'qty_out_milli', 'total_cost_minor', 'balance_qty_milli', 'balance_value_minor'])
            ->map(function (object $row): array {
                $out = (int) $row->qty_out_milli;
                $balance = (int) $row->balance_qty_milli;
                if ($out === 0 || $balance >= 0) {
                    return ['qty' => 0, 'cost' => 0, 'ledgerId' => (int) $row->id];
                }
                $found = Quantity::add($balance, $out) > 0 ? Money::add((int) $row->balance_value_minor, (int) $row->total_cost_minor) : 0;

                return ['qty' => min($out, -$balance), 'cost' => Money::subtract((int) $row->total_cost_minor, $found), 'ledgerId' => (int) $row->id];
            })->all();
    }

    /**
     * D34: an edit takes a document's movements back out (as <type>_VOID rows
     * under the document's own id) and posts new ones. Only movements after
     * the latest edit are the document's current lines; before any edit this
     * is 0. A real void writes its rows under the reversal's id, never here.
     */
    public static function lastEditMark(string $sourceType, int $sourceId): Builder
    {
        return DB::table('stock_ledger as edit_marks')
            ->where('edit_marks.source_type', "{$sourceType}_VOID")
            ->where('edit_marks.source_id', $sourceId)
            ->selectRaw('COALESCE(MAX(`edit_marks`.`id`), 0)');
    }

    private static function batchRow(object $row, string $asAt): array
    {
        return [
            'id' => (int) $row->id,
            'batchRef' => $row->batch_ref,
            'expiryDate' => $row->expiry_date,
            'receivedDate' => $row->received_date,
            'qtyMilli' => (int) $row->qty_milli,
            'isClosed' => (bool) $row->is_closed,
            'supplierName' => $row->supplier_name,
            'daysLeft' => $row->expiry_date === null ? null : BusinessDate::daysBetween($asAt, $row->expiry_date),
        ];
    }

    /**
     * One product's open batches, in the order stock will be taken from them:
     * dated-and-good by soonest date, then undated, then expired.
     *
     * @return list<array>
     */
    public static function listProductBatches(int $productId, string $asAt): array
    {
        return DB::table('product_batches')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'product_batches.supplier_id')
            ->where('product_batches.product_id', $productId)
            ->where('product_batches.is_closed', false)
            ->orderByRaw('CASE WHEN `product_batches`.`expiry_date` IS NULL THEN 1 WHEN `product_batches`.`expiry_date` < ? THEN 2 ELSE 0 END', [$asAt])
            ->orderBy('product_batches.expiry_date')
            ->orderBy('product_batches.id')
            ->get(['product_batches.id', 'product_batches.batch_ref', 'product_batches.expiry_date', 'product_batches.received_date', 'product_batches.qty_milli', 'product_batches.is_closed', 'suppliers.name as supplier_name'])
            ->map(fn (object $row): array => self::batchRow($row, $asAt))
            ->all();
    }

    /**
     * Correct the date on a crate. Changes nothing about quantity or value,
     * only which crate the till reaches for next, so it is audited with both
     * the old date and the new.
     */
    public static function setBatchExpiry(int $batchId, ?string $expiryDate, Actor $actor): void
    {
        if ($expiryDate !== null) {
            BusinessDate::assert($expiryDate, 'expiry date');
        }

        Tx::run(function () use ($batchId, $expiryDate, $actor): void {
            $batch = DB::table('product_batches')->where('id', $batchId)->lockForUpdate()->first(['id', 'batch_ref', 'expiry_date', 'product_id'])
                ?? throw new NotFoundError('Batch', $batchId);
            if ($batch->expiry_date === $expiryDate) {
                return;
            }
            $now = Clock::now();
            DB::table('product_batches')->where('id', $batchId)->update(['expiry_date' => $expiryDate, 'updated_at' => Clock::stamp($now)]);
            AuditService::write(
                AuditAction::Update,
                'product_batch',
                $expiryDate === null ? "{$batch->batch_ref}: expiry date removed" : "{$batch->batch_ref}: expiry date set to {$expiryDate}",
                $batchId,
                $actor,
                ['before' => ['expiryDate' => $batch->expiry_date], 'after' => ['expiryDate' => $expiryDate], 'productId' => (int) $batch->product_id],
                $now,
            );
        });
    }

    /**
     * Every crate that still holds stock, soonest date first; undated last.
     *
     * @return list<array>
     */
    public static function listAllOpenBatches(string $asAt): array
    {
        return DB::table('product_batches')
            ->join('products', 'products.id', '=', 'product_batches.product_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'product_batches.supplier_id')
            ->where('product_batches.is_closed', false)
            ->where('product_batches.qty_milli', '<>', 0)
            ->orderByRaw('CASE WHEN `product_batches`.`expiry_date` IS NULL THEN 1 ELSE 0 END')
            ->orderBy('product_batches.expiry_date')
            ->orderBy('products.name')
            ->orderBy('product_batches.id')
            ->get(['product_batches.id', 'product_batches.batch_ref', 'product_batches.expiry_date', 'product_batches.received_date', 'product_batches.qty_milli', 'product_batches.is_closed', 'suppliers.name as supplier_name', 'products.id as product_id', 'products.name as product_name', 'products.sku', 'products.unit'])
            ->map(fn (object $row): array => [
                ...self::batchRow($row, $asAt),
                'productId' => (int) $row->product_id,
                'productName' => $row->product_name,
                'sku' => $row->sku,
                'unit' => $row->unit,
            ])
            ->all();
    }

    /**
     * Stock by how long it has left. Quantity, never value: a batch carries no
     * cost. Undated stock has its own bucket: unknown is not distant. qtyMilli
     * adds every unit together, so it only means something in one unit; byUnit
     * is what a screen shows.
     *
     * @return list<array{bucket: string, label: string, batchCount: int, qtyMilli: int, byUnit: array<string, int>}>
     */
    public static function getExpiryAgeing(string $asAt): array
    {
        $totals = array_fill_keys(self::EXPIRY_BUCKETS, ['batchCount' => 0, 'qtyMilli' => 0, 'byUnit' => []]);
        $in7 = BusinessDate::addDays($asAt, 7);
        $in30 = BusinessDate::addDays($asAt, 30);
        $in90 = BusinessDate::addDays($asAt, 90);

        $rows = DB::table('product_batches')
            ->join('products', 'products.id', '=', 'product_batches.product_id')
            ->where('product_batches.is_closed', false)
            ->where('product_batches.qty_milli', '>', 0)
            ->get(['product_batches.expiry_date', 'product_batches.qty_milli', 'products.unit']);
        foreach ($rows as $row) {
            $date = $row->expiry_date;
            $bucket = match (true) {
                $date === null => 'undated',
                $date < $asAt => 'expired',
                $date <= $in7 => 'within7',
                $date <= $in30 => 'within30',
                $date <= $in90 => 'within90',
                default => 'later',
            };
            $totals[$bucket]['batchCount']++;
            $totals[$bucket]['qtyMilli'] = Quantity::add($totals[$bucket]['qtyMilli'], (int) $row->qty_milli);
            // Kilograms and tins do not add up: shown per unit, so the quantity column means something.
            $totals[$bucket]['byUnit'][$row->unit] = Quantity::add($totals[$bucket]['byUnit'][$row->unit] ?? 0, (int) $row->qty_milli);
        }

        return array_map(
            fn (string $bucket): array => ['bucket' => $bucket, 'label' => self::BUCKET_LABELS[$bucket], ...$totals[$bucket]],
            self::EXPIRY_BUCKETS,
        );
    }

    /**
     * Everything that ever touched one batch, oldest first: the recall query.
     *
     * @return array{batch: array, entries: list<array>}
     */
    public static function getBatchHistory(int $batchId): array
    {
        $batch = DB::table('product_batches')
            ->join('products', 'products.id', '=', 'product_batches.product_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'product_batches.supplier_id')
            ->where('product_batches.id', $batchId)
            ->first(['product_batches.id', 'product_batches.product_id', 'products.name as product_name', 'products.unit', 'product_batches.batch_ref', 'product_batches.expiry_date', 'product_batches.received_date', 'product_batches.qty_milli', 'product_batches.opening_qty_milli', 'product_batches.is_closed', 'suppliers.name as supplier_name', 'product_batches.note'])
            ?? throw new NotFoundError('Batch', $batchId);

        $entries = DB::table('stock_ledger_batches')
            ->join('stock_ledger', 'stock_ledger.id', '=', 'stock_ledger_batches.ledger_id')
            ->where('stock_ledger_batches.batch_id', $batchId)
            ->orderBy('stock_ledger.id')
            ->get(['stock_ledger.id', 'stock_ledger.business_date', 'stock_ledger.occurred_at', 'stock_ledger.movement_type', 'stock_ledger.source_type', 'stock_ledger.source_id', 'stock_ledger.source_ref', 'stock_ledger_batches.qty_in_milli', 'stock_ledger_batches.qty_out_milli'])
            ->map(fn (object $row): array => [
                'ledgerId' => (int) $row->id,
                'businessDate' => $row->business_date,
                'occurredAt' => Clock::fromStamp($row->occurred_at),
                'movementType' => $row->movement_type,
                'sourceType' => $row->source_type,
                'sourceId' => $row->source_id === null ? null : (int) $row->source_id,
                'sourceRef' => $row->source_ref,
                'qtyInMilli' => (int) $row->qty_in_milli,
                'qtyOutMilli' => (int) $row->qty_out_milli,
                'partyName' => self::partyFor($row->source_type, $row->source_id === null ? null : (int) $row->source_id),
                'isEdit' => self::isEditTakeBack($row->source_type, $row->source_id === null ? null : (int) $row->source_id),
            ])
            ->all();

        return [
            'batch' => [
                'id' => (int) $batch->id,
                'productId' => (int) $batch->product_id,
                'productName' => $batch->product_name,
                'unit' => $batch->unit,
                'batchRef' => $batch->batch_ref,
                'expiryDate' => $batch->expiry_date,
                'receivedDate' => $batch->received_date,
                'qtyMilli' => (int) $batch->qty_milli,
                'openingQtyMilli' => (int) $batch->opening_qty_milli,
                'isClosed' => (bool) $batch->is_closed,
                'supplierName' => $batch->supplier_name,
                'note' => $batch->note,
            ],
            'entries' => $entries,
        ];
    }

    /**
     * D34: an edit takes a document's old movements back out as <type>_VOID rows
     * under the document's OWN id; a real void writes them under its reversal.
     * So a _VOID row whose document reverses nothing is an edit, not a cancellation.
     */
    private static function isEditTakeBack(string $sourceType, ?int $sourceId): bool
    {
        [$table, $voids] = match ($sourceType) {
            'SALE_VOID' => ['sales', 'voids_sale_id'],
            'PURCHASE_VOID' => ['purchases', 'voids_purchase_id'],
            'STOCK_ADJUSTMENT_VOID' => ['stock_adjustments', 'voids_adjustment_id'],
            default => [null, null],
        };

        return $table !== null && $sourceId !== null
            && DB::table($table)->where("{$table}.id", $sourceId)->whereNull("{$table}.{$voids}")->exists();
    }

    /** Who a movement's document was with, when it was with anybody. */
    private static function partyFor(string $sourceType, ?int $sourceId): ?string
    {
        if ($sourceId === null) {
            return null;
        }
        if (in_array($sourceType, ['SALE', 'SALE_RETURN', 'SALE_VOID'], true)) {
            $row = DB::table('sales')->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
                ->where('sales.id', $sourceId)->first(['customers.name']);

            // A walk-in is a real answer: the customer who cannot be telephoned.
            return $row === null ? null : ($row->name ?? 'Walk-in customer');
        }
        if (in_array($sourceType, ['PURCHASE', 'PURCHASE_RETURN', 'PURCHASE_VOID'], true)) {
            return DB::table('purchases')->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
                ->where('purchases.id', $sourceId)->value('suppliers.name');
        }

        return null;
    }

    /**
     * Every crate past its date that still holds stock, oldest problem first.
     *
     * @return list<array{id: int, productId: int, batchRef: string, expiryDate: string, qtyMilli: int}>
     */
    public static function listExpiredBatches(string $asAt): array
    {
        return DB::table('product_batches')
            ->where('product_batches.is_closed', false)
            ->where('product_batches.qty_milli', '>', 0)
            ->whereNotNull('product_batches.expiry_date')
            ->where('product_batches.expiry_date', '<', $asAt)
            ->orderBy('product_batches.expiry_date')
            ->orderBy('product_batches.id')
            ->get(['id', 'product_id', 'batch_ref', 'expiry_date', 'qty_milli'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'productId' => (int) $row->product_id, 'batchRef' => $row->batch_ref, 'expiryDate' => $row->expiry_date, 'qtyMilli' => (int) $row->qty_milli])
            ->all();
    }

    /**
     * What each product's dates look like as at a business day, in one pass,
     * for the till (one product when it adds a line). Undated stock counts as good.
     *
     * @return array<int, array{goodQtyMilli: int, soonestExpiry: ?string, warnDays: int}>
     */
    public static function getExpiryOutlook(string $businessDate, ?int $productId = null): array
    {
        $shopDays = (int) (DB::table('business_settings')->where('id', 1)->value('expiry_warning_days') ?? 30);

        return DB::table('product_batches')
            ->join('products', 'products.id', '=', 'product_batches.product_id')
            ->where('product_batches.is_closed', false)
            ->where('product_batches.qty_milli', '>', 0)
            ->when($productId !== null, fn (Builder $q) => $q->where('product_batches.product_id', $productId))
            ->where(fn (Builder $q) => $q->whereNull('product_batches.expiry_date')->orWhere('product_batches.expiry_date', '>=', $businessDate))
            ->groupBy('product_batches.product_id')
            ->selectRaw('`product_batches`.`product_id` AS productId, COALESCE(SUM(`product_batches`.`qty_milli`), 0) AS goodQtyMilli,
                         MIN(`product_batches`.`expiry_date`) AS soonestExpiry, COALESCE(MIN(`products`.`warn_days`), ?) AS warnDays', [$shopDays])
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->productId => [
                'goodQtyMilli' => (int) $row->goodQtyMilli,
                'soonestExpiry' => $row->soonestExpiry,
                'warnDays' => (int) $row->warnDays,
            ]])
            ->all();
    }

    // --- integrity ----------------------------------------------------------

    /**
     * Per batch: qty === opening + Σ in − Σ out over its allocations. The
     * opening figure is recorded, not derived, so this proves something.
     *
     * @return list<array{batchId: int, batchRef: string, productId: int, cachedQty: int, allocatedQty: int, drift: int, ok: bool}>
     */
    public static function verifyProductBatches(int $productId): array
    {
        $moved = DB::table('stock_ledger_batches')
            ->join('product_batches', 'product_batches.id', '=', 'stock_ledger_batches.batch_id')
            ->where('product_batches.product_id', $productId)
            ->groupBy('stock_ledger_batches.batch_id')
            ->selectRaw('`stock_ledger_batches`.`batch_id` AS batchId, SUM(`stock_ledger_batches`.`qty_in_milli`) AS inQty, SUM(`stock_ledger_batches`.`qty_out_milli`) AS outQty')
            ->get()->keyBy('batchId');

        return DB::table('product_batches')->where('product_batches.product_id', $productId)->orderBy('product_batches.id')->get()
            ->map(function (object $batch) use ($moved): array {
                $m = $moved[$batch->id] ?? null;
                $allocated = (int) $batch->opening_qty_milli + (int) ($m->inQty ?? 0) - (int) ($m->outQty ?? 0);
                $drift = (int) $batch->qty_milli - $allocated;

                return [
                    'batchId' => (int) $batch->id,
                    'batchRef' => $batch->batch_ref,
                    'productId' => (int) $batch->product_id,
                    'cachedQty' => (int) $batch->qty_milli,
                    'allocatedQty' => $allocated,
                    'drift' => $drift,
                    'ok' => $drift === 0,
                ];
            })->all();
    }

    /**
     * Per product: does every unit on the shelf belong to some batch? The one
     * that matters most, and cheap: one grouped sum, no replay.
     *
     * @return list<array{productId: int, productName: string, productQty: int, batchedQty: int, drift: int, ok: bool, batchCount: int}>
     */
    public static function verifyBatchCoverage(): array
    {
        $rows = DB::select(
            'SELECT p.id AS productId, p.name AS productName, p.qty_on_hand_milli AS productQty,
                    COALESCE(SUM(b.qty_milli), 0) AS batchedQty, COUNT(b.id) AS batchCount
             FROM products p
             LEFT JOIN product_batches b ON b.product_id = p.id
             WHERE p.track_inventory = 1
             GROUP BY p.id
             ORDER BY p.id',
        );

        return array_map(function (object $row): array {
            $drift = (int) $row->productQty - (int) $row->batchedQty;

            return [
                'productId' => (int) $row->productId,
                'productName' => $row->productName,
                'productQty' => (int) $row->productQty,
                'batchedQty' => (int) $row->batchedQty,
                'drift' => $drift,
                'ok' => $drift === 0,
                'batchCount' => (int) $row->batchCount,
            ];
        }, $rows);
    }

    /**
     * Recompute a product's position from its first movement and compare it
     * with the cached columns. The cache is never the authority.
     */
    public static function verifyProductStock(int $productId): array
    {
        $product = DB::table('products')->where('id', $productId)->first() ?? throw new NotFoundError('Stock item', $productId);
        $movements = DB::table('stock_ledger')->where('stock_ledger.product_id', $productId)->orderBy('stock_ledger.id')
            // Every column, and the cancels pair read as 0 when absent: a backup of a database one update behind runs this check too.
            ->get()
            ->map(fn (object $row): array => ['qtyIn' => (int) $row->qty_in_milli, 'qtyOut' => (int) $row->qty_out_milli, 'totalCost' => (int) $row->total_cost_minor, 'cancelsQty' => (int) ($row->cancels_qty_milli ?? 0), 'cancelsCost' => (int) ($row->cancels_cost_minor ?? 0)])
            ->all();
        $replayed = Costing::replay($movements);

        return [
            ...self::compareCache($productId, $product->name, (int) $product->qty_on_hand_milli, (int) $product->stock_value_minor, $replayed),
            'movementCount' => count($movements),
        ];
    }

    private static function compareCache(int $productId, string $name, int $cachedQty, int $cachedValue, StockState $ledger): array
    {
        $qtyDrift = Quantity::subtract($cachedQty, $ledger->qty);
        $valueDrift = Money::subtract($cachedValue, $ledger->value);

        return [
            'productId' => $productId,
            'productName' => $name,
            'cachedQty' => $cachedQty,
            'cachedValue' => $cachedValue,
            'ledgerQty' => $ledger->qty,
            'ledgerValue' => $ledger->value,
            'qtyDrift' => $qtyDrift,
            'valueDrift' => $valueDrift,
            'ok' => $qtyDrift === 0 && $valueDrift === 0,
        ];
    }

    /**
     * The cheap check: does the cache agree with the LAST balance the ledger
     * recorded? One index seek per product, whatever the history. It does not
     * catch a ledger that is inconsistent within itself; only a replay does.
     *
     * @return list<array>
     */
    public static function verifyStockAgainstLedger(): array
    {
        // MAX(id) correlated to one product is a seek on (product_id, id).
        $rows = DB::select(
            'SELECT p.id AS productId, p.name AS productName, p.qty_on_hand_milli AS cachedQty, p.stock_value_minor AS cachedValue,
                    COALESCE(l.balance_qty_milli, 0) AS ledgerQty, COALESCE(l.balance_value_minor, 0) AS ledgerValue
             FROM products p
             LEFT JOIN stock_ledger l ON l.id = (SELECT MAX(sl.id) FROM stock_ledger sl WHERE sl.product_id = p.id)
             WHERE p.track_inventory = 1
             ORDER BY p.id',
        );

        // A product that has never moved reads zero, and is checked rather than skipped.
        return array_map(fn (object $row): array => self::compareCache(
            (int) $row->productId,
            $row->productName,
            (int) $row->cachedQty,
            (int) $row->cachedValue,
            new StockState((int) $row->ledgerQty, (int) $row->ledgerValue),
        ), $rows);
    }

    /** Every stock-tracked product, replayed from its first movement. Thorough and slow. */
    public static function verifyAllStock(): array
    {
        return DB::table('products')->where('products.track_inventory', true)->orderBy('products.id')->pluck('id')
            ->map(fn ($id): array => self::verifyProductStock((int) $id))
            ->all();
    }

    /** Total value of stock on hand, from the product cache. */
    public static function getInventoryValue(): int
    {
        return (int) DB::table('products')->selectRaw('COALESCE(SUM(`products`.`stock_value_minor`), 0) AS total')->value('total');
    }

    /** Total value of stock on hand, recomputed from the ledger. */
    public static function getInventoryValueFromLedger(): int
    {
        return Money::sum(array_column(self::verifyAllStock(), 'ledgerValue'));
    }

    // --- the stock ledger ---------------------------------------------------

    /**
     * The ledger filters, shared by the list and the count. Read-only: each
     * row's balance was recorded when it moved, so a filter shows fewer rows
     * and the same balances.
     *
     * Query keys: productId, categoryId, from, to, movementType, userId, search, limit, offset.
     */
    private static function applyLedgerConditions(Builder $q, array $query): void
    {
        if (isset($query['productId'])) {
            $q->where('stock_ledger.product_id', $query['productId']);
        }
        if (isset($query['from'])) {
            $q->where('stock_ledger.business_date', '>=', $query['from']);
        }
        if (isset($query['to'])) {
            $q->where('stock_ledger.business_date', '<=', $query['to']);
        }
        if (isset($query['movementType'])) {
            $type = $query['movementType'];
            $q->where('stock_ledger.movement_type', $type instanceof MovementType ? $type->value : $type);
        }
        if (isset($query['categoryId'])) {
            $q->whereRaw('EXISTS (SELECT 1 FROM `products` p WHERE p.`id` = `stock_ledger`.`product_id` AND p.`category_id` = ?)', [$query['categoryId']]);
        }
        // A movement has no author of its own, only a cause: the person is on the document.
        if (isset($query['userId'])) {
            $user = $query['userId'];
            $q->whereRaw(
                "((`stock_ledger`.`source_type` IN ('SALE', 'SALE_RETURN', 'SALE_VOID')
                   AND EXISTS (SELECT 1 FROM `sales` s WHERE s.`id` = `stock_ledger`.`source_id` AND s.`created_by` = ?))
                  OR (`stock_ledger`.`source_type` IN ('PURCHASE', 'PURCHASE_RETURN', 'PURCHASE_VOID')
                   AND EXISTS (SELECT 1 FROM `purchases` pu WHERE pu.`id` = `stock_ledger`.`source_id` AND pu.`created_by` = ?))
                  OR (`stock_ledger`.`source_type` IN ('STOCK_ADJUSTMENT', 'STOCK_ADJUSTMENT_VOID')
                   AND EXISTS (SELECT 1 FROM `stock_adjustments` sa WHERE sa.`id` = `stock_ledger`.`source_id` AND sa.`created_by` = ?)))",
                [$user, $user, $user],
            );
        }
        $search = isset($query['search']) ? JsCompat::trim($query['search']) : '';
        if ($search !== '') {
            $term = CatalogService::containsPattern($search);
            $q->whereRaw(
                "(LOWER(COALESCE(`stock_ledger`.`source_ref`, '')) LIKE LOWER(?)
                  OR LOWER(COALESCE(`stock_ledger`.`note`, '')) LIKE LOWER(?)
                  OR EXISTS (SELECT 1 FROM `products` p WHERE p.`id` = `stock_ledger`.`product_id`
                             AND (LOWER(p.`name`) LIKE LOWER(?) OR LOWER(COALESCE(p.`sku`, '')) LIKE LOWER(?))))",
                [$term, $term, $term, $term],
            );
        }
    }

    /**
     * Newest trading day first, and within a day the order the cost chain was
     * built (id), so each row's balance reads in sequence.
     *
     * @return list<array>
     */
    public static function getStockLedger(array $query = []): array
    {
        $q = DB::table('stock_ledger')->join('products', 'products.id', '=', 'stock_ledger.product_id');
        self::applyLedgerConditions($q, $query);

        return $q->orderByDesc('stock_ledger.business_date')->orderByDesc('stock_ledger.id')
            ->limit(min($query['limit'] ?? 100, Filters::MAX_LIST_ROWS))
            ->offset($query['offset'] ?? 0)
            ->get([
                'stock_ledger.id', 'stock_ledger.product_id', 'products.name as product_name', 'products.unit as product_unit',
                'products.category_id', 'stock_ledger.business_date', 'stock_ledger.occurred_at', 'stock_ledger.movement_type',
                'stock_ledger.source_type', 'stock_ledger.source_id', 'stock_ledger.source_ref', 'stock_ledger.qty_in_milli',
                'stock_ledger.qty_out_milli', 'stock_ledger.unit_cost_minor', 'stock_ledger.total_cost_minor',
                'stock_ledger.balance_qty_milli', 'stock_ledger.balance_value_minor', 'stock_ledger.note',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'productId' => (int) $row->product_id,
                'productName' => $row->product_name,
                'productUnit' => $row->product_unit,
                'categoryId' => $row->category_id === null ? null : (int) $row->category_id,
                'businessDate' => $row->business_date,
                'occurredAt' => Clock::fromStamp($row->occurred_at),
                'movementType' => $row->movement_type,
                'sourceType' => $row->source_type,
                'sourceId' => $row->source_id === null ? null : (int) $row->source_id,
                'sourceRef' => $row->source_ref,
                'qtyIn' => (int) $row->qty_in_milli,
                'qtyOut' => (int) $row->qty_out_milli,
                'unitCost' => (int) $row->unit_cost_minor,
                'totalCost' => (int) $row->total_cost_minor,
                'balanceQty' => (int) $row->balance_qty_milli,
                'balanceValue' => (int) $row->balance_value_minor,
                'note' => $row->note,
            ])
            ->all();
    }

    /** How many movements match, ignoring the page. */
    public static function countStockLedger(array $query = []): int
    {
        $q = DB::table('stock_ledger');
        self::applyLedgerConditions($q, $query);

        return $q->count();
    }
}
