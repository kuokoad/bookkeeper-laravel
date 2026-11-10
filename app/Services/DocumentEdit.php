<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Accounting\PeriodLock;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\ValidationError;
use App\Domain\JsCompat;
use App\Domain\Money;
use App\Enums\AuditAction;
use App\Enums\JournalSourceType;
use App\Enums\MovementType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision D34, the steps every edit of a saved document shares: the
 * old version comes back out on the day it was recorded (its stock through
 * the batches it moved, its entry reversed, its rows kept whole in the audit
 * log), then the service posts the new version as a new document would, and
 * the edit is recorded. One place, so the rules cannot drift between sales,
 * purchases, stock adjustments and the cashbook.
 */
final class DocumentEdit
{
    /** The reason the owner gave, trimmed; refused when it says nothing. */
    public static function reason(string $why, string $noun): string
    {
        $why = JsCompat::trim($why);
        if (mb_strlen($why) < 3) {
            throw new ValidationError("Say why this {$noun} is being edited.");
        }

        return $why;
    }

    /** Both days must be open: the old version comes out on its own day, the new goes in on its. */
    public static function assertDaysOpen(string $originalDate, string $newDate): void
    {
        $lockedBefore = JournalService::readLockDate();
        PeriodLock::assertOpen($originalDate, $lockedBefore);
        PeriodLock::assertOpen($newDate, $lockedBefore);
    }

    /**
     * Stock a document brought in that has gone out since was costed with it:
     * taking it back at that value would leave those sales' cost wrong. The
     * document's own movements (an adjustment's OUT lines) do not count.
     *
     * @param  list<int>  $productIds  the products it brought in
     * @param  callable(string): string  $refusal  the message, given the first document the stock went out on
     */
    public static function assertNothingWentOutSince(string $sourceType, int $documentId, array $productIds, callable $refusal): void
    {
        if ($productIds === []) {
            return;
        }
        $firstIn = DB::table('stock_ledger')->where('stock_ledger.source_type', $sourceType)->where('stock_ledger.source_id', $documentId)
            ->where('stock_ledger.id', '>', InventoryService::lastEditMark($sourceType, $documentId))->whereIn('stock_ledger.product_id', $productIds)
            ->where('stock_ledger.qty_in_milli', '>', 0)->min('stock_ledger.id');
        if ($firstIn === null) {
            return;
        }
        $since = DB::table('stock_ledger')->whereIn('stock_ledger.product_id', $productIds)->where('stock_ledger.id', '>', $firstIn)
            ->where('stock_ledger.qty_out_milli', '>', 0)
            ->whereNot(fn ($q) => $q->where('stock_ledger.source_type', $sourceType)->where('stock_ledger.source_id', $documentId))
            ->orderBy('stock_ledger.id')->value('stock_ledger.source_ref');
        if ($since !== null) {
            throw new ConflictError($refusal((string) $since));
        }
    }

    /**
     * The old movements back out, on the document's own day, at the value
     * each went at, through the batches it moved. Lines are in document
     * order and only those that moved stock, so each takes back its own
     * movement. Written as <type>_VOID under the document's own id (see
     * InventoryService::lastEditMark).
     *
     * @param  list<array{productId: int, direction: 'IN'|'OUT', qty: int, totalCost: int, movementType: MovementType}>  $lines  the take-back movements
     * @return int the value the shelf could not keep, for postCostingResidual
     */
    public static function takeBack(string $sourceType, int $documentId, string $documentNo, string $originalDate, array $lines, bool $allowNegative, bool $isDemo, Carbon $now, Actor $actor): int
    {
        // Every split read before any movement: the take-backs add rows of their own.
        $movedVia = [];
        foreach ($lines as $line) {
            $movedVia[$line['productId']] ??= InventoryService::readBatchSplits($sourceType, $documentId, $line['productId']);
        }
        $residual = 0;
        foreach ($lines as $line) {
            $batches = array_shift($movedVia[$line['productId']]);
            $moved = InventoryService::recordStockMovement([
                'productId' => $line['productId'],
                'direction' => $line['direction'],
                'qty' => $line['qty'],
                'totalCost' => $line['totalCost'],
                'movementType' => $line['movementType'],
                'sourceType' => "{$sourceType}_VOID",
                'sourceId' => $documentId,
                'sourceRef' => $documentNo,
                'businessDate' => $originalDate,
                'occurredAt' => $now,
                'userId' => $actor->id,
                'note' => "Edit of {$documentNo}",
                'allowNegative' => $allowNegative,
                'isDemo' => $isDemo,
                ...($batches === null || $batches === [] ? [] : ['batch' => ['kind' => 'SOURCE', 'allocations' => $batches]]),
            ]);
            $residual = Money::add($residual, $moved['residual']);
        }

        return $residual;
    }

    /**
     * The old entry reversed on its own day, what the take-back left off the
     * shelf posted, and the document's old rows cleared, kept whole first.
     *
     * @param  list<string>  $tables  the document's line tables, keyed by $foreignKey
     * @return array<string, list<array<string, mixed>>> the rows cleared, for record()
     */
    public static function reverseAndClear(JournalSourceType $source, ?int $entryId, string $originalDate, int $documentId, string $documentNo, string $why, int $residual, bool $isDemo, string $foreignKey, array $tables, Carbon $now, Actor $actor): array
    {
        if ($entryId !== null) {
            JournalService::reverse($entryId, $originalDate, $source, $documentId, "Edit of {$documentNo}: {$why}", occurredAt: $now, actor: $actor);
        }
        PurchaseService::postCostingResidual($residual, $originalDate, $documentNo, $documentId, "Stock costing difference on editing {$documentNo}", $now, $actor, $source, $isDemo);
        if ($tables === []) {
            return [];
        }
        $replaced = AuditService::rowsBeingReplaced($foreignKey, $documentId, ...$tables);
        foreach ($tables as $table) {
            DB::table($table)->where("{$table}.{$foreignKey}", $documentId)->delete();
        }

        return $replaced;
    }

    /**
     * The edit in the audit log: who, why, what it said before and after
     * (editHistory), and every row it replaced, whole.
     *
     * @param  array<string, list<array<string, mixed>>>  $replaced
     */
    public static function record(string $entityType, int $documentId, string $documentNo, string $why, array $before, array $after, array $replaced, Actor $actor, Carbon $now): void
    {
        AuditService::write(AuditAction::Update, $entityType, "Edited {$documentNo}: {$why}", $documentId, $actor, [
            'edit' => true,
            'reason' => $why,
            'before' => $before,
            'after' => $after,
            ...($replaced === [] ? [] : ['replaced' => $replaced]),
        ], $now);
    }
}
