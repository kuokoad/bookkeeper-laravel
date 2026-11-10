<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\RoleGuard;
use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Domain\Accounting\PeriodLock;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Domain\Inventory\Batches;
use App\Domain\JsCompat;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Enums\AdjustmentReason;
use App\Enums\AuditAction;
use App\Enums\DocType;
use App\Enums\JournalSourceType;
use App\Enums\MovementType;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stock adjustments: the only way stock changes without a sale or a
 * purchase. Each is one transaction producing the document and its items, one
 * stock movement per item, ONE balanced journal entry, and an audit record.
 * A port of the old stock-adjustment.service.ts.
 *
 * Input: ['businessDate', 'reason' => AdjustmentReason, 'note'?, 'occurredAt'? => Carbon,
 * 'isDemo'?, 'items' => list of ['productId', 'direction' => 'IN'|'OUT', 'qty',
 * 'alt'?, 'altFactor'?, 'totalCost'?, 'note'?, 'batchId'?]].
 */
final class StockAdjustmentService
{
    /** Audit metadata key marking an adjustment as half of a unit-change replacement. */
    public const UNIT_CHANGE_MARKER = 'unitChange';

    /** Which account the value is posted against; Inventory is always the other side. */
    public const REASON_ACCOUNT = [
        // Opening stock is capital introduced, not an expense.
        'OPENING_STOCK' => ChartOfAccounts::OPENING_BALANCE_EQUITY,
        'DAMAGED' => ChartOfAccounts::INVENTORY_SHRINKAGE,
        'LOST' => ChartOfAccounts::INVENTORY_SHRINKAGE,
        'EXPIRED' => ChartOfAccounts::INVENTORY_SHRINKAGE,
        'FOUND' => ChartOfAccounts::INVENTORY_SHRINKAGE,
        'COUNT_CORRECTION' => ChartOfAccounts::INVENTORY_SHRINKAGE,
        // Goods consumed by the business are a running cost.
        'INTERNAL_USE' => '6900',
        'OTHER' => ChartOfAccounts::INVENTORY_SHRINKAGE,
    ];

    public const REASON_LABELS = [
        'OPENING_STOCK' => 'Opening stock',
        'DAMAGED' => 'Damaged',
        'LOST' => 'Lost or stolen',
        'EXPIRED' => 'Expired',
        'FOUND' => 'Found / extra stock',
        'COUNT_CORRECTION' => 'Stock count correction',
        'INTERNAL_USE' => 'Used in the business',
        'OTHER' => 'Other',
    ];

    /** Reasons that only ever add stock, and those that only ever remove it. */
    public const REASON_DEFAULT_DIRECTION = [
        'OPENING_STOCK' => 'IN',
        'FOUND' => 'IN',
        'DAMAGED' => 'OUT',
        'LOST' => 'OUT',
        'EXPIRED' => 'OUT',
        'INTERNAL_USE' => 'OUT',
        'COUNT_CORRECTION' => null,
        'OTHER' => null,
    ];

    private static function allowNegativeStock(): bool
    {
        return (bool) (DB::table('business_settings')->where('id', 1)->value('allow_negative_stock') ?? false);
    }

    /**
     * @return array{adjustmentId: int, adjustmentNo: string, journalEntryId: int, totalInValue: int, totalOutValue: int}
     */
    public static function createStockAdjustment(array $input, Actor $actor): array
    {
        if (($input['items'] ?? []) === []) {
            throw new ValidationError('Add at least one stock item to the adjustment.');
        }
        $reason = $input['reason'] instanceof AdjustmentReason ? $input['reason'] : (AdjustmentReason::tryFrom((string) $input['reason']) ?? throw new ValidationError('Choose a reason for the adjustment.'));

        return Tx::run(function () use ($input, $reason, $actor): array {
            InventoryService::lockProducts(array_column($input['items'], 'productId'));
            JournalService::readLockDate();

            $occurredAt = $input['occurredAt'] ?? Clock::now();
            $at = Clock::stamp($occurredAt);
            $isDemo = $input['isDemo'] ?? false;
            $adjustmentNo = SequenceService::next(DocType::Adjustment);
            $label = self::REASON_LABELS[$reason->value];

            $adjustmentId = (int) DB::table('stock_adjustments')->insertGetId([
                'adjustment_no' => $adjustmentNo,
                'business_date' => $input['businessDate'],
                'occurred_at' => $at,
                'reason' => $reason->value,
                'note' => $input['note'] ?? null,
                'status' => 'POSTED',
                'created_by' => $actor->id,
                'is_demo' => $isDemo,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $posted = self::postLines($adjustmentId, $adjustmentNo, $input, $reason, $occurredAt, $actor, $isDemo);
            $totalIn = $posted['totalIn'];
            $totalOut = $posted['totalOut'];

            if ($posted['entryId'] === null) {
                // Every movement was valued at zero: stock that carried no value.
                // Nothing to post, and an empty entry would fail the balance check.
                DB::table('stock_adjustments')->where('id', $adjustmentId)->update(['updated_at' => $at]);
                AuditService::write(AuditAction::Create, 'stock_adjustment', "{$adjustmentNo}: {$label} (no value moved)", $adjustmentId, $actor, [
                    'reason' => $reason->value,
                    'itemCount' => count($input['items']),
                ], $occurredAt);

                return ['adjustmentId' => $adjustmentId, 'adjustmentNo' => $adjustmentNo, 'journalEntryId' => 0, 'totalInValue' => 0, 'totalOutValue' => 0];
            }

            DB::table('stock_adjustments')->where('id', $adjustmentId)->update(['journal_entry_id' => $posted['entryId'], 'updated_at' => $at]);
            AuditService::write(AuditAction::Create, 'stock_adjustment', "{$adjustmentNo}: {$label}, ".count($input['items']).' product(s)', $adjustmentId, $actor, [
                'reason' => $reason->value,
                'totalInMinor' => $totalIn,
                'totalOutMinor' => $totalOut,
                'entryNo' => $posted['entryNo'],
            ], $occurredAt);

            return ['adjustmentId' => $adjustmentId, 'adjustmentNo' => $adjustmentNo, 'journalEntryId' => $posted['entryId'], 'totalInValue' => $totalIn, 'totalOutValue' => $totalOut];
        });
    }

    /**
     * Void an adjustment by writing its opposite, dated today. The original
     * and its movements stay exactly as recorded.
     *
     * @return array{adjustmentId: int, adjustmentNo: string, journalEntryId: int, totalInValue: int, totalOutValue: int}
     */
    public static function voidStockAdjustment(int $adjustmentId, string $reason, Actor $actor, ?Carbon $now = null): array
    {
        $now ??= Clock::now();

        return Tx::run(function () use ($adjustmentId, $reason, $actor, $now): array {
            InventoryService::lockProducts(DB::table('stock_adjustment_items')->where('adjustment_id', $adjustmentId)->pluck('product_id')->all());
            $original = DB::table('stock_adjustments')->where('id', $adjustmentId)->lockForUpdate()->first()
                ?? throw new NotFoundError('Stock adjustment', $adjustmentId);
            if ($original->status === 'VOIDED') {
                throw new ConflictError('That adjustment has already been voided.');
            }
            $why = JsCompat::trim($reason);
            if (mb_strlen($why) < 3) {
                throw new ValidationError('Give a reason for voiding this adjustment.');
            }
            // Half of a unit-change replacement: voiding it alone would put the
            // stock back on the archived product, which no longer has its codes.
            if (self::isUnitChange($adjustmentId)) {
                throw new ConflictError("{$original->adjustment_no} moved stock to a new stock item for a unit change, so it cannot be voided on its own. Correct the new stock item with a stock adjustment instead.");
            }

            $items = DB::table('stock_adjustment_items')->where('adjustment_id', $adjustmentId)->orderBy('id')->get();
            JournalService::readLockDate();

            $businessDate = Clock::dateOf($now);
            $at = Clock::stamp($now);
            $reversalNo = SequenceService::next(DocType::Adjustment);
            $allowNegative = self::allowNegativeStock();
            $costingResidual = 0;

            $reversalId = (int) DB::table('stock_adjustments')->insertGetId([
                'adjustment_no' => $reversalNo,
                'business_date' => $businessDate,
                'occurred_at' => $at,
                'reason' => $original->reason,
                'note' => "Void of {$original->adjustment_no}: {$why}",
                'status' => 'POSTED',
                'voids_adjustment_id' => $adjustmentId,
                'created_by' => $actor->id,
                'is_demo' => (bool) $original->is_demo,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $movedVia = [];
            foreach ($items as $item) {
                $movedVia[(int) $item->product_id] ??= InventoryService::readBatchSplits('STOCK_ADJUSTMENT', $adjustmentId, (int) $item->product_id);
            }
            foreach ($items as $item) {
                $batches = array_shift($movedVia[(int) $item->product_id]);
                // Opposite direction, at the ORIGINAL value both ways. The journal
                // side is an exact reversal, so an average-costed removal would
                // make the two counts of inventory disagree.
                $opposite = $item->direction === 'IN' ? 'OUT' : 'IN';
                $moved = InventoryService::recordStockMovement([
                    'productId' => (int) $item->product_id,
                    'direction' => $opposite,
                    'qty' => (int) $item->qty_milli,
                    'totalCost' => (int) $item->total_cost_minor,
                    'movementType' => $opposite === 'IN' ? MovementType::AdjustmentIn : MovementType::AdjustmentOut,
                    'sourceType' => 'STOCK_ADJUSTMENT_VOID',
                    'sourceId' => $reversalId,
                    'sourceRef' => $reversalNo,
                    'businessDate' => $businessDate,
                    'occurredAt' => $now,
                    'userId' => $actor->id,
                    'note' => "Void of {$original->adjustment_no}",
                    'allowNegative' => $allowNegative,
                    'isDemo' => (bool) $original->is_demo,
                    // Back through the batches it moved, not the soonest-expiring one.
                    ...($batches === null || $batches === [] ? [] : ['batch' => ['kind' => 'SOURCE', 'allocations' => $batches]]),
                ]);

                DB::table('stock_adjustment_items')->insert([
                    'adjustment_id' => $reversalId,
                    'product_id' => $item->product_id,
                    'direction' => $opposite,
                    'qty_milli' => $item->qty_milli,
                    'entry_unit' => $item->entry_unit,
                    'entry_qty_milli' => $item->entry_qty_milli,
                    'entry_factor_milli' => $item->entry_factor_milli,
                    'unit_cost_minor' => $moved['unitCost'],
                    'total_cost_minor' => $moved['totalCost'],
                    'note' => "Void of {$original->adjustment_no}",
                    'created_at' => $at,
                ]);
                $costingResidual = Money::add($costingResidual, $moved['residual']);
            }

            $reversalEntryId = 0;
            if ($original->journal_entry_id !== null) {
                $reversed = JournalService::reverse(
                    (int) $original->journal_entry_id,
                    $businessDate,
                    JournalSourceType::StockAdjustment,
                    $reversalId,
                    "Void of {$original->adjustment_no}: {$why}",
                    occurredAt: $now,
                    actor: $actor,
                );
                $reversalEntryId = $reversed['entryId'];
                DB::table('stock_adjustments')->where('id', $reversalId)->update(['journal_entry_id' => $reversalEntryId, 'updated_at' => $at]);
            }

            // Value the shelf could not keep: emptying a product at the value that
            // went in can leave the running value off zero once the average has
            // moved on. It leaves inventory and the general ledger together.
            if ($costingResidual !== 0) {
                $cogs = JournalService::accountIdByCode(ChartOfAccounts::COST_OF_GOODS_SOLD);
                $inventory = JournalService::accountIdByCode(ChartOfAccounts::INVENTORY);
                JournalService::post(
                    $businessDate,
                    JournalSourceType::StockAdjustment,
                    $costingResidual > 0
                        ? [
                            Journal::debit($cogs, $costingResidual, ['description' => "{$reversalNo} costing difference"]),
                            Journal::credit($inventory, $costingResidual, ['description' => "{$reversalNo} stock value released"]),
                        ]
                        : [
                            Journal::debit($inventory, Money::negate($costingResidual), ['description' => "{$reversalNo} stock value restored"]),
                            Journal::credit($cogs, Money::negate($costingResidual), ['description' => "{$reversalNo} costing difference"]),
                        ],
                    sourceId: $reversalId,
                    memo: "Stock costing difference on voiding {$original->adjustment_no}",
                    occurredAt: $now,
                    actor: $actor,
                );
            }

            DB::table('stock_adjustments')->where('id', $adjustmentId)->update([
                'status' => 'VOIDED',
                'voided_at' => $at,
                'void_reason' => $why,
                'voided_by_adjustment_id' => $reversalId,
                'updated_at' => $at,
            ]);
            AuditService::write(AuditAction::Void, 'stock_adjustment', "Voided {$original->adjustment_no} with {$reversalNo}", $adjustmentId, $actor, [
                'reason' => $why,
                'reversalId' => $reversalId,
            ], $now);

            return ['adjustmentId' => $reversalId, 'adjustmentNo' => $reversalNo, 'journalEntryId' => $reversalEntryId, 'totalInValue' => 0, 'totalOutValue' => 0];
        });
    }

    /**
     * Owner decision D34: correct a saved adjustment in place. The old lines
     * are taken back out on the day they were recorded (opposite movements at
     * their original value and a reversal of the journal entry), then the new
     * lines are posted exactly as a new adjustment would, under the same
     * number. Nothing posted is changed; what the adjustment was before is
     * kept in the audit log.
     *
     * Input: as createStockAdjustment (businessDate, reason, note?, items).
     *
     * @return array{adjustmentId: int, adjustmentNo: string, journalEntryId: int, totalInValue: int, totalOutValue: int}
     */
    public static function editStockAdjustment(int $adjustmentId, array $input, string $why, Actor $actor, ?Carbon $now = null): array
    {
        RoleGuard::assertActorIsOwner($actor, 'edit a saved stock adjustment');
        if (($input['items'] ?? []) === []) {
            throw new ValidationError('Add at least one stock item to the adjustment.');
        }
        $reason = $input['reason'] instanceof AdjustmentReason ? $input['reason'] : (AdjustmentReason::tryFrom((string) $input['reason']) ?? throw new ValidationError('Choose a reason for the adjustment.'));
        $why = JsCompat::trim($why);
        if (mb_strlen($why) < 3) {
            throw new ValidationError('Say why this adjustment is being edited.');
        }
        $now ??= Clock::now();

        return Tx::run(function () use ($adjustmentId, $input, $reason, $why, $actor, $now): array {
            InventoryService::lockProducts([
                ...DB::table('stock_adjustment_items')->where('stock_adjustment_items.adjustment_id', $adjustmentId)->pluck('product_id')->map(fn ($id): int => (int) $id)->all(),
                ...array_column($input['items'], 'productId'),
            ]);
            $original = DB::table('stock_adjustments')->where('id', $adjustmentId)->lockForUpdate()->first()
                ?? throw new NotFoundError('Stock adjustment', $adjustmentId);
            // Read under the lock: an edit that committed while this one waited has replaced the lines.
            $oldItems = DB::table('stock_adjustment_items')->where('adjustment_id', $adjustmentId)->orderBy('id')->lockForUpdate()->get();
            if ($original->status === 'VOIDED') {
                throw new ConflictError("{$original->adjustment_no} has been voided, so it cannot be edited.");
            }
            if ($original->voids_adjustment_id !== null) {
                throw new ConflictError("{$original->adjustment_no} is the reversal of a voided adjustment, so it cannot be edited.");
            }
            if (self::isUnitChange($adjustmentId)) {
                throw new ConflictError("{$original->adjustment_no} moved stock to a new stock item for a unit change, so it cannot be edited. Correct the new stock item with a stock adjustment instead.");
            }
            // Both days must be open: the old lines come out on theirs, the new go in on theirs.
            $lockedBefore = JournalService::readLockDate();
            PeriodLock::assertOpen((string) $original->business_date, $lockedBefore);
            PeriodLock::assertOpen($input['businessDate'], $lockedBefore);

            $before = self::snapshot($adjustmentId);
            $at = Clock::stamp($now);
            $originalDate = (string) $original->business_date;
            $isDemo = (bool) $original->is_demo;
            $allowNegative = self::allowNegativeStock();
            $residual = 0;

            // 1. Take the old lines back out, at their original value, on their own day, through the batches they moved.
            $movedVia = [];
            foreach ($oldItems as $item) {
                $movedVia[(int) $item->product_id] ??= InventoryService::readBatchSplits('STOCK_ADJUSTMENT', $adjustmentId, (int) $item->product_id);
            }
            foreach ($oldItems as $item) {
                $opposite = $item->direction === 'IN' ? 'OUT' : 'IN';
                $batches = array_shift($movedVia[(int) $item->product_id]);
                $moved = InventoryService::recordStockMovement([
                    'productId' => (int) $item->product_id,
                    'direction' => $opposite,
                    'qty' => (int) $item->qty_milli,
                    'totalCost' => (int) $item->total_cost_minor,
                    'movementType' => $opposite === 'IN' ? MovementType::AdjustmentIn : MovementType::AdjustmentOut,
                    'sourceType' => 'STOCK_ADJUSTMENT_VOID',
                    'sourceId' => $adjustmentId,
                    'sourceRef' => $original->adjustment_no,
                    'businessDate' => $originalDate,
                    'occurredAt' => $now,
                    'userId' => $actor->id,
                    'note' => "Edit of {$original->adjustment_no}",
                    'allowNegative' => $allowNegative,
                    'isDemo' => $isDemo,
                    ...($batches === null || $batches === [] ? [] : ['batch' => ['kind' => 'SOURCE', 'allocations' => $batches]]),
                ]);
                $residual = Money::add($residual, $moved['residual']);
            }
            if ($original->journal_entry_id !== null) {
                JournalService::reverse(
                    (int) $original->journal_entry_id,
                    $originalDate,
                    JournalSourceType::StockAdjustment,
                    $adjustmentId,
                    "Edit of {$original->adjustment_no}: {$why}",
                    occurredAt: $now,
                    actor: $actor,
                );
            }
            PurchaseService::postCostingResidual($residual, $originalDate, $original->adjustment_no, $adjustmentId, "Stock costing difference on editing {$original->adjustment_no}", $now, $actor, JournalSourceType::StockAdjustment, $isDemo);
            // The old lines live on in the audit row below; the document now lists the new ones.
            DB::table('stock_adjustment_items')->where('adjustment_id', $adjustmentId)->delete();

            // 2. Post the lines as they should have been.
            $posted = self::postLines($adjustmentId, $original->adjustment_no, $input, $reason, $now, $actor, $isDemo);
            DB::table('stock_adjustments')->where('id', $adjustmentId)->update([
                'business_date' => $input['businessDate'],
                'reason' => $reason->value,
                'note' => $input['note'] ?? null,
                'journal_entry_id' => $posted['entryId'],
                'edited_at' => $at,
                'updated_at' => $at,
            ]);
            AuditService::write(AuditAction::Update, 'stock_adjustment', "Edited {$original->adjustment_no}: {$why}", $adjustmentId, $actor, [
                'edit' => true,
                'reason' => $why,
                'before' => $before,
                'after' => self::snapshot($adjustmentId),
            ], $now);

            return ['adjustmentId' => $adjustmentId, 'adjustmentNo' => $original->adjustment_no, 'journalEntryId' => $posted['entryId'] ?? 0, 'totalInValue' => $posted['totalIn'], 'totalOutValue' => $posted['totalOut']];
        });
    }

    /** Half of a unit-change replacement: it moved stock between two products and stands or falls with its pair. */
    private static function isUnitChange(int $adjustmentId): bool
    {
        return DB::table('audit_logs')
            ->where('audit_logs.entity_type', 'stock_adjustment')
            ->where('audit_logs.entity_id', (string) $adjustmentId)
            ->whereRaw('JSON_EXTRACT(`audit_logs`.`metadata`, ?) IS NOT NULL', ['$.'.self::UNIT_CHANGE_MARKER])
            ->exists();
    }

    /**
     * What an adjustment says, in the shape the edit history shows for any
     * document: its date, its fields, and one line per item.
     *
     * @return array{date: string, fields: array<string, ?string>, lines: list<array{label: string, amount: ?int}>, total: ?int}
     */
    private static function snapshot(int $adjustmentId): array
    {
        $data = self::getStockAdjustment($adjustmentId);

        return [
            'date' => (string) $data['adjustment']->business_date,
            'fields' => [
                'Reason' => self::REASON_LABELS[$data['adjustment']->reason] ?? $data['adjustment']->reason,
                'Note' => $data['adjustment']->note,
            ],
            'lines' => array_map(fn (object $i): array => [
                'label' => "{$i->product_name}: ".($i->direction === 'IN' ? 'in ' : 'out ').Quantity::formatWithUnit((int) $i->qty_milli, $i->unit),
                'amount' => (int) $i->total_cost_minor,
            ], $data['items']),
            'total' => null,
        ];
    }

    /**
     * The lines of an adjustment and everything they move: the items, one
     * stock movement each, the costing residual and ONE balanced journal
     * entry. Shared by create and edit, so an edited adjustment posts exactly
     * what a new one would.
     *
     * @return array{entryId: ?int, entryNo: ?string, totalIn: int, totalOut: int}
     */
    private static function postLines(int $adjustmentId, string $adjustmentNo, array $input, AdjustmentReason $reason, Carbon $occurredAt, Actor $actor, bool $isDemo): array
    {
        $at = Clock::stamp($occurredAt);
        $allowNegative = self::allowNegativeStock();
        $label = self::REASON_LABELS[$reason->value];

        $inValues = [];
        $outValues = [];
        $residual = 0;
        foreach ($input['items'] as $entered) {
            $product = DB::table('products')->where('id', $entered['productId'])->first()
                ?? throw new NotFoundError('Stock item', $entered['productId']);
            // Only a stale form names an archived product.
            if (! $product->is_active) {
                throw new ValidationError("\"{$product->name}\" is archived, so its stock cannot be adjusted. Reload the form; if it was replaced, adjust the stock item that replaced it.");
            }
            $lineUnit = UnitsService::resolveLineUnit($product, $entered['qty'], $entered['alt'] ?? null, $entered['altFactor'] ?? null);
            // From here on the line is in the base unit, like every stock movement.
            $item = [...$entered, 'qty' => $lineUnit['baseQty']];
            if ($item['qty'] <= 0) {
                throw new ValidationError('Every adjustment line needs a quantity greater than zero.');
            }
            if ($item['direction'] === 'IN' && ! isset($item['totalCost'])) {
                throw new ValidationError('Adding stock requires the value of the goods received.');
            }

            $batch = self::resolveWriteOffBatch($reason, $item);
            $movement = [
                'productId' => $item['productId'],
                'direction' => $item['direction'],
                'qty' => $item['qty'],
                // Opening stock is tagged distinctly, and only stock counted IN:
                // productAltUnitLocked leaves a carton editable while every row is OPENING_STOCK.
                'movementType' => match (true) {
                    $reason === AdjustmentReason::OpeningStock && $item['direction'] === 'IN' => MovementType::OpeningStock,
                    $item['direction'] === 'IN' => MovementType::AdjustmentIn,
                    default => MovementType::AdjustmentOut,
                },
                'sourceType' => 'STOCK_ADJUSTMENT',
                'sourceId' => $adjustmentId,
                'sourceRef' => $adjustmentNo,
                'businessDate' => $input['businessDate'],
                'occurredAt' => $occurredAt,
                'userId' => $actor->id,
                'note' => $item['note'] ?? null,
                'allowNegative' => $allowNegative,
                'isDemo' => $isDemo,
            ];
            if (isset($item['totalCost'])) {
                $movement['totalCost'] = $item['totalCost'];
            }
            // A named crate is taken exactly; an unnamed expired write-off takes
            // whatever has turned, oldest first, before anything else.
            if ($batch !== null) {
                $movement['batch'] = ['kind' => 'SOURCE', 'allocations' => [['batchId' => $batch['id'], 'batchRef' => $batch['batchRef'], 'qtyMilli' => $item['qty']]]];
            } elseif ($reason === AdjustmentReason::Expired && $item['direction'] === 'OUT') {
                $movement['batch'] = ['kind' => 'PICK', 'expiredFirst' => true];
            }
            $moved = InventoryService::recordStockMovement($movement);
            // D26: stock counted in onto a shelf that was below zero.
            $residual = Money::add($residual, $moved['residual']);

            DB::table('stock_adjustment_items')->insert([
                'adjustment_id' => $adjustmentId,
                'product_id' => $item['productId'],
                'batch_id' => $batch['id'] ?? null,
                'direction' => $item['direction'],
                'qty_milli' => $item['qty'],
                'entry_unit' => $lineUnit['entryUnit'],
                'entry_qty_milli' => $lineUnit['entryQtyMilli'],
                'entry_factor_milli' => $lineUnit['entryFactorMilli'],
                'unit_cost_minor' => $moved['unitCost'],
                'total_cost_minor' => $moved['totalCost'],
                'note' => $item['note'] ?? null,
                'created_at' => $at,
            ]);

            if ($item['direction'] === 'IN') {
                $inValues[] = $moved['totalCost'];
            } else {
                $outValues[] = $moved['totalCost'];
            }
        }

        PurchaseService::postCostingResidual($residual, $input['businessDate'], $adjustmentNo, $adjustmentId, "Stock costing difference on {$adjustmentNo}", $occurredAt, $actor, JournalSourceType::StockAdjustment, $isDemo);

        $totalIn = Money::sum($inValues);
        $totalOut = Money::sum($outValues);
        $inventory = JournalService::accountIdByCode(ChartOfAccounts::INVENTORY);
        $counter = JournalService::accountIdByCode(self::REASON_ACCOUNT[$reason->value]);

        // Stock in: Dr Inventory, Cr counter. Stock out: Dr counter, Cr Inventory.
        $lines = [];
        if ($totalIn !== 0) {
            $lines[] = Journal::debit($inventory, $totalIn, ['description' => "{$label} (in)"]);
            $lines[] = Journal::credit($counter, $totalIn, ['description' => "{$label} (in)"]);
        }
        if ($totalOut !== 0) {
            $lines[] = Journal::debit($counter, $totalOut, ['description' => "{$label} (out)"]);
            $lines[] = Journal::credit($inventory, $totalOut, ['description' => "{$label} (out)"]);
        }

        if ($lines === []) {
            return ['entryId' => null, 'entryNo' => null, 'totalIn' => 0, 'totalOut' => 0];
        }

        $posted = JournalService::post(
            $input['businessDate'],
            JournalSourceType::StockAdjustment,
            $lines,
            sourceId: $adjustmentId,
            memo: "{$adjustmentNo} — {$label}",
            isOpening: $reason === AdjustmentReason::OpeningStock,
            isDemo: $isDemo,
            occurredAt: $occurredAt,
            actor: $actor,
        );

        return ['entryId' => $posted['entryId'], 'entryNo' => $posted['entryNo'], 'totalIn' => $totalIn, 'totalOut' => $totalOut];
    }

    // --- reads --------------------------------------------------------------

    /** @return list<object> */
    public static function listStockAdjustments(int $limit = 50, int $offset = 0): array
    {
        return DB::table('stock_adjustments')
            ->orderByDesc('stock_adjustments.occurred_at')
            ->orderByDesc('stock_adjustments.id')
            ->limit(min($limit, 200))
            ->offset($offset)
            ->get()
            ->all();
    }

    /** @return array{adjustment: object, items: list<object>} */
    public static function getStockAdjustment(int $adjustmentId): array
    {
        $adjustment = DB::table('stock_adjustments')->where('id', $adjustmentId)->first()
            ?? throw new NotFoundError('Stock adjustment', $adjustmentId);
        $items = DB::table('stock_adjustment_items')
            ->join('products', 'products.id', '=', 'stock_adjustment_items.product_id')
            ->where('stock_adjustment_items.adjustment_id', $adjustmentId)
            ->orderBy('stock_adjustment_items.id')
            ->get([
                'stock_adjustment_items.id', 'stock_adjustment_items.product_id', 'stock_adjustment_items.batch_id', 'products.name as product_name', 'products.unit',
                'stock_adjustment_items.direction', 'stock_adjustment_items.qty_milli', 'stock_adjustment_items.entry_unit',
                'stock_adjustment_items.entry_qty_milli', 'stock_adjustment_items.entry_factor_milli',
                'stock_adjustment_items.unit_cost_minor', 'stock_adjustment_items.total_cost_minor', 'stock_adjustment_items.note',
            ])
            ->all();

        return ['adjustment' => $adjustment, 'items' => $items];
    }

    /**
     * The crate an item names, checked against the reason that named it. Only
     * a write-off of expired goods may name one: a recount says the shelf is
     * wrong, not which crate is.
     *
     * @return array{id: int, batchRef: string}|null
     */
    private static function resolveWriteOffBatch(AdjustmentReason $reason, array $item): ?array
    {
        if ($reason !== AdjustmentReason::Expired) {
            if (isset($item['batchId'])) {
                throw new ValidationError('Only a write-off of expired goods names the batch it came from.', ['reason' => $reason->value]);
            }

            return null;
        }
        if (! isset($item['batchId'])) {
            return null;
        }

        $batch = DB::table('product_batches')->where('id', $item['batchId'])->first(['id', 'batch_ref', 'product_id', 'qty_milli'])
            ?? throw new NotFoundError('Batch', $item['batchId']);
        if ((int) $batch->product_id !== $item['productId']) {
            throw new ValidationError('That batch belongs to a different stock item.', ['batchId' => $item['batchId']]);
        }
        if ($item['qty'] > (int) $batch->qty_milli) {
            throw new ValidationError(
                "{$batch->batch_ref} only holds ".Batches::formatQty((int) $batch->qty_milli).', so '.Batches::formatQty($item['qty']).' cannot be written off from it.',
                ['batchId' => (int) $batch->id, 'holds' => (int) $batch->qty_milli, 'requested' => $item['qty']],
            );
        }

        return ['id' => (int) $batch->id, 'batchRef' => $batch->batch_ref];
    }
}
