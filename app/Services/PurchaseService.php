<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Domain\Accounting\PeriodLock;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Domain\JsCompat;
use App\Domain\Money;
use App\Domain\Purchases\PurchaseCalculator;
use App\Domain\Tax\TaxComponent;
use App\Domain\Tax\TaxLine;
use App\Enums\AuditAction;
use App\Enums\DocType;
use App\Enums\JournalSourceType;
use App\Enums\MovementType;
use App\Enums\TaxBasis;
use App\Support\Clock;
use App\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recording a purchase: the mirror of a sale. A port of the old
 * purchase.service.ts.
 *
 * ONE transaction validates the supplier and the lines, writes the purchase
 * and its lines, adds stock at the price ACTUALLY PAID (re-averaging the
 * cost), records what was paid at the time, posts ONE balanced entry
 * (Inventory / Cash / Accounts Payable) and writes the audit.
 *
 * createPurchase input: ['supplierId', 'businessDate', 'invoiceNo'?, 'items' => list of
 * ['productId', 'qty', 'unitCost', 'discount'?, 'expiryDate'?, 'alt'?, 'altFactor'?],
 * 'invoiceDiscount'?, 'tenders' => list of ['paymentAccountId', 'amount', 'reference'?],
 * 'note'?, 'occurredAt'? => Carbon, 'isDemo'?].
 */
final class PurchaseService
{
    public const PURCHASE_SORTS = ['date', 'amount', 'supplier', 'outstanding', 'reference'];

    public const PURCHASE_PAYMENT_KINDS = ['CASH', 'MOBILE_MONEY', 'BANK', 'OTHER'];

    /**
     * What a purchase's returns took off the supplier's account: each return's
     * value less any cash the supplier refunded. {purchase} is that purchase's id.
     */
    private const CREDITED_SQL = '(SELECT COALESCE(SUM(-`r`.`total_minor` + (
            SELECT COALESCE(SUM(`rp`.`amount_minor`), 0) FROM `purchase_payments` `rp` WHERE `rp`.`purchase_id` = `r`.`id`
        )), 0) FROM `purchases` `r` WHERE `r`.`returns_purchase_id` = {purchase} AND `r`.`status` = \'POSTED\')';

    /** Supplier payments still standing against a purchase. {purchase} is its id. */
    private const SETTLED_SQL = '(SELECT COALESCE(SUM(`a`.`amount_minor`), 0)
            FROM `supplier_payment_allocations` `a`
            JOIN `supplier_payments` `p` ON `p`.`id` = `a`.`payment_id` AND `p`.`status` = \'POSTED\'
            WHERE `a`.`purchase_id` = {purchase})';

    private static function creditedSql(string $purchaseId): string
    {
        return str_replace('{purchase}', $purchaseId, self::CREDITED_SQL);
    }

    /**
     * What a delivery still owes, as SQL on the outer `purchases` row, so
     * "outstanding" narrows before the limit. A void and its mirror owe
     * nothing; a return owes nothing while its purchase stands.
     */
    private static function outstandingSql(): string
    {
        return "(CASE WHEN `purchases`.`status` = 'VOIDED' OR `purchases`.`voids_purchase_id` IS NOT NULL THEN 0
            WHEN EXISTS (SELECT 1 FROM `purchases` `original`
                         WHERE `original`.`id` = `purchases`.`returns_purchase_id` AND `original`.`status` <> 'VOIDED') THEN 0
            ELSE `purchases`.`total_minor`
              - (SELECT COALESCE(SUM(`pp`.`amount_minor`), 0) FROM `purchase_payments` `pp` WHERE `pp`.`purchase_id` = `purchases`.`id`)
              - ".str_replace('{purchase}', '`purchases`.`id`', self::SETTLED_SQL).'
              - '.self::creditedSql('`purchases`.`id`').'
        END)';
    }

    /** What has been paid on a delivery: at the time, plus supplier payments since. */
    private static function paidSql(): string
    {
        return '((SELECT COALESCE(SUM(`pp`.`amount_minor`), 0) FROM `purchase_payments` `pp` WHERE `pp`.`purchase_id` = `purchases`.`id`)
            + '.str_replace('{purchase}', '`purchases`.`id`', self::SETTLED_SQL).')';
    }

    /**
     * @return array{purchaseId: int, purchaseNo: string, total: int, paid: int, outstanding: int, journalEntryId: ?int}
     */
    public static function createPurchase(array $input, Actor $actor): array
    {
        if (($input['items'] ?? []) === []) {
            throw new ValidationError('Add at least one product to the purchase.');
        }

        return Tx::run(function () use ($input, $actor): array {
            $occurredAt = $input['occurredAt'] ?? Clock::now();
            $at = Clock::stamp($occurredAt);
            $isDemo = $input['isDemo'] ?? false;

            // Lock order (Tx): products, then the supplier.
            InventoryService::lockProducts(array_column($input['items'], 'productId'));
            $settings = DB::table('business_settings')->where('id', 1)->first() ?? throw new NotFoundError('Business settings', 1);
            $supplier = SupplierService::lockSupplier($input['supplierId']) ?? throw new NotFoundError('Supplier', $input['supplierId']);
            if (! $supplier->is_active) {
                throw new ValidationError("\"{$supplier->name}\" is archived and cannot be purchased from.");
            }

            // --- line arithmetic ---------------------------------------------
            $lines = [];
            foreach ($input['items'] as $item) {
                $product = DB::table('products')->where('id', $item['productId'])->first() ?? throw new NotFoundError('Product', $item['productId']);
                // A form opened before the product was archived, or replaced, must not put a delivery there.
                if (! $product->is_active) {
                    throw new ValidationError("\"{$product->name}\" is archived and cannot be bought. Reload the form and pick the product again.");
                }
                $discount = $item['discount'] ?? 0;
                $lineTotal = PurchaseCalculator::lineTotal($item['unitCost'], $item['qty'], $discount);
                $lineUnit = UnitsService::resolveLineUnit($product, $item['qty'], $item['alt'] ?? null, $item['altFactor'] ?? null);
                $lines[] = ['item' => $item, 'product' => $product, 'lineUnit' => $lineUnit, 'discount' => $discount, 'lineTotal' => $lineTotal];
            }
            $lineTotals = array_column($lines, 'lineTotal');

            // Priced net with tax on top, whatever the shop's own shelves do.
            $invoiceDiscount = $input['invoiceDiscount'] ?? 0;
            $taxProfile = TaxService::profile();
            $totals = PurchaseCalculator::totals($lineTotals, $invoiceDiscount, $taxProfile['components']);
            $taxLines = $totals['breakdown']->lines;
            $total = $totals['total'];

            // Tax that cannot be reclaimed is part of what the goods cost.
            $nonRecoverableTax = TaxService::nonRecoverableTotal($taxLines);
            $tender = PurchaseCalculator::tender($total, array_column($input['tenders'], 'amount'));

            // --- write the purchase -------------------------------------------
            // Lock order (Tx 3a): the tender accounts, shared, so archiving one waits for this delivery.
            $tenderAccounts = PaymentAccountService::lockTenderAccounts($input['tenders']);
            JournalService::readLockDate();
            $purchaseNo = SequenceService::next(DocType::Purchase);
            $purchaseId = (int) DB::table('purchases')->insertGetId([
                'purchase_no' => $purchaseNo,
                'kind' => 'PURCHASE',
                'supplier_id' => $input['supplierId'],
                'business_date' => $input['businessDate'],
                'occurred_at' => $at,
                'invoice_no' => $input['invoiceNo'] ?? null,
                'subtotal_minor' => $totals['subtotal'],
                'discount_minor' => $invoiceDiscount,
                'tax_minor' => $totals['tax'],
                'total_minor' => $total,
                'status' => 'POSTED',
                'note' => $input['note'] ?? null,
                'created_by' => $actor->id,
                'is_demo' => $isDemo,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            // What was paid, component by component, with whether each was reclaimable ON THE DAY.
            TaxService::writePurchaseTaxes($purchaseId, $taxLines, $taxProfile['componentIdByCode'], $occurredAt);

            // --- stock in: the goods value, discount spread and levies added ---
            $lineCosts = PurchaseCalculator::lineCosts($lineTotals, $invoiceDiscount, $nonRecoverableTax);
            $inventoryParts = [];
            foreach ($lines as $index => $line) {
                if ($line['product']->track_inventory) {
                    // A dated line opens its own batch; an undated one pools where undated stock always went.
                    $expiryDate = $line['item']['expiryDate'] ?? null;
                    InventoryService::recordStockMovement([
                        'productId' => (int) $line['product']->id,
                        'direction' => 'IN',
                        'qty' => $line['lineUnit']['baseQty'],
                        'totalCost' => $lineCosts[$index],
                        'movementType' => MovementType::Purchase,
                        'sourceType' => 'PURCHASE',
                        'sourceId' => $purchaseId,
                        'sourceRef' => $purchaseNo,
                        'businessDate' => $input['businessDate'],
                        'occurredAt' => $occurredAt,
                        'userId' => $actor->id,
                        'isDemo' => $isDemo,
                        ...($expiryDate === null ? [] : ['batch' => ['kind' => 'NEW', 'expiryDate' => $expiryDate, 'supplierId' => $input['supplierId']]]),
                    ]);
                    $inventoryParts[] = $lineCosts[$index];
                }

                DB::table('purchase_items')->insert([
                    'purchase_id' => $purchaseId,
                    'line_no' => $index + 1,
                    'product_id' => (int) $line['product']->id,
                    'product_name' => $line['product']->name,
                    'unit' => $line['product']->unit,
                    'qty_milli' => $line['lineUnit']['baseQty'],
                    'entry_unit' => $line['lineUnit']['entryUnit'],
                    'entry_qty_milli' => $line['lineUnit']['entryQtyMilli'],
                    'entry_factor_milli' => $line['lineUnit']['entryFactorMilli'],
                    'unit_cost_minor' => $line['item']['unitCost'],
                    'discount_minor' => $line['discount'],
                    'line_total_minor' => $line['lineTotal'],
                    'created_at' => $at,
                ]);
            }
            $inventoryValue = Money::sum($inventoryParts);

            // Goods not stock-tracked are an expense now; their levies too, or the entry would not balance.
            $nonInventoryValue = Money::subtract(Money::sum([$totals['net'], $nonRecoverableTax]), $inventoryValue);

            // --- tender ---------------------------------------------------------
            $journal = [];
            foreach ($input['tenders'] as $request) {
                if ($request['amount'] <= 0) {
                    continue;
                }
                $account = $tenderAccounts[$request['paymentAccountId']] ?? throw new NotFoundError('Payment account', $request['paymentAccountId']);
                if (! $account->is_active) {
                    throw new ValidationError("Payment account \"{$account->name}\" is not active.");
                }
                DB::table('purchase_payments')->insert([
                    'purchase_id' => $purchaseId,
                    'payment_account_id' => $request['paymentAccountId'],
                    'amount_minor' => $request['amount'],
                    'reference' => $request['reference'] ?? null,
                    'created_at' => $at,
                ]);
                $journal[] = Journal::credit((int) $account->gl_account_id, $request['amount'], [
                    'paymentAccountId' => (int) $request['paymentAccountId'],
                    'description' => "{$purchaseNo} paid ({$account->name})",
                ]);
            }

            // --- the journal entry ------------------------------------------------
            if ($inventoryValue !== 0) {
                $journal[] = Journal::debit(JournalService::accountIdByCode(ChartOfAccounts::INVENTORY), $inventoryValue, ['description' => "{$purchaseNo} stock received"]);
            }
            if ($nonInventoryValue !== 0) {
                $journal[] = Journal::debit(JournalService::accountIdByCode('6900'), $nonInventoryValue, ['description' => "{$purchaseNo} non-stock items"]);
            }
            // Only the reclaimable part is an asset, each in the account it is set off in.
            foreach ($taxLines as $taxLine) {
                if (! $taxLine->component->isRecoverable || $taxLine->amount === 0) {
                    continue;
                }
                $componentId = $taxProfile['componentIdByCode'][$taxLine->component->code] ?? null;
                $journal[] = Journal::debit(TaxService::accountFor($componentId, $taxLine->component->code), $taxLine->amount, ['description' => "{$purchaseNo} {$taxLine->component->name} reclaimable"]);
            }
            if ($tender['outstanding'] !== 0) {
                $journal[] = Journal::credit(JournalService::accountIdByCode(ChartOfAccounts::ACCOUNTS_PAYABLE), $tender['outstanding'], [
                    'supplierId' => (int) $input['supplierId'],
                    'description' => "{$purchaseNo} on credit",
                ]);
            }

            // A delivery worth nothing posts nothing, but the books lock still applies.
            $posted = null;
            if ($journal === []) {
                PeriodLock::assertOpen($input['businessDate'], JournalService::readLockDate());
            } else {
                $posted = JournalService::post(
                    $input['businessDate'],
                    JournalSourceType::Purchase,
                    $journal,
                    sourceId: $purchaseId,
                    memo: "{$purchaseNo} — purchase from {$supplier->name}",
                    isDemo: $isDemo,
                    occurredAt: $occurredAt,
                    actor: $actor,
                );
                DB::table('purchases')->where('id', $purchaseId)->update(['journal_entry_id' => $posted['entryId'], 'updated_at' => $at]);
            }

            AuditService::write(
                AuditAction::Create,
                'purchase',
                "{$purchaseNo}: ".count($input['items'])." line(s) from {$supplier->name}, total ".Money::format($total, $settings->currency_code),
                $purchaseId,
                $actor,
                [
                    'totalMinor' => $total,
                    'paidMinor' => $tender['tendered'],
                    'outstandingMinor' => $tender['outstanding'],
                    'supplierId' => $input['supplierId'],
                    'entryNo' => $posted['entryNo'] ?? null,
                ],
                $occurredAt,
            );

            return [
                'purchaseId' => $purchaseId,
                'purchaseNo' => $purchaseNo,
                'total' => $total,
                'paid' => $tender['tendered'],
                'outstanding' => $tender['outstanding'],
                'journalEntryId' => $posted['entryId'] ?? null,
            ];
        });
    }

    /**
     * Void a purchase by writing its mirror image, dated today. Stock leaves at
     * the cost it arrived at, from the batches it filled; the payable or
     * payment is reversed; the original is kept exactly as recorded.
     *
     * @return array{reversalPurchaseId: int, purchaseNo: string}
     */
    public static function voidPurchase(int $purchaseId, string $reason, Actor $actor, ?Carbon $now = null): array
    {
        $why = JsCompat::trim($reason);
        if (mb_strlen($why) < 3) {
            throw new ValidationError('Give a reason for voiding this purchase.');
        }
        $now ??= Clock::now();

        return Tx::run(function () use ($purchaseId, $why, $actor, $now): array {
            // Lock order (Tx): products, the supplier whose payable changes, then the purchase and its lines.
            InventoryService::lockProducts(DB::table('purchase_items')->where('purchase_items.purchase_id', $purchaseId)->pluck('product_id')->all());
            $supplierId = DB::table('purchases')->where('id', $purchaseId)->value('supplier_id');
            if ($supplierId !== null) {
                SupplierService::lockSupplier((int) $supplierId);
            }
            $original = DB::table('purchases')->where('id', $purchaseId)->lockForUpdate()->first() ?? throw new NotFoundError('Purchase', $purchaseId);
            if ($original->status === 'VOIDED') {
                throw new ConflictError('That purchase has already been voided.');
            }
            if ($original->kind !== 'PURCHASE') {
                throw new ConflictError('Only a purchase can be voided this way.');
            }

            $items = DB::table('purchase_items')->where('purchase_id', $purchaseId)->orderBy('id')->lockForUpdate()->get();

            // Goods already sent back would leave the shelf and the books twice.
            // Checked before payments, or the shop is told to void a real payment
            // for a void that cannot run.
            if ($items->contains(fn (object $item): bool => (int) $item->returned_qty_milli > 0)) {
                throw new ConflictError('Goods from this purchase have already been returned, so it cannot be voided. Return anything still left instead.');
            }
            if (self::settledByPayments($purchaseId) > 0) {
                throw new ConflictError('This purchase has payments recorded against it. Void those payments first.');
            }

            PaymentAccountService::lockAccountsOfEntry($original->journal_entry_id);
            JournalService::readLockDate();
            $businessDate = Clock::dateOf($now);
            $at = Clock::stamp($now);
            $purchaseNo = SequenceService::next(DocType::PurchaseReturn);
            $allowNegative = (bool) DB::table('business_settings')->where('id', 1)->value('allow_negative_stock');

            $reversalId = (int) DB::table('purchases')->insertGetId([
                'purchase_no' => $purchaseNo,
                'kind' => 'VOID',
                'supplier_id' => $original->supplier_id,
                'business_date' => $businessDate,
                'occurred_at' => $at,
                'invoice_no' => $original->invoice_no,
                'subtotal_minor' => Money::negate((int) $original->subtotal_minor),
                'discount_minor' => Money::negate((int) $original->discount_minor),
                'tax_minor' => Money::negate((int) $original->tax_minor),
                'total_minor' => Money::negate((int) $original->total_minor),
                'status' => 'POSTED',
                'voids_purchase_id' => $purchaseId,
                'note' => "Void of {$original->purchase_no}: {$why}",
                'created_by' => $actor->id,
                'is_demo' => (bool) $original->is_demo,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            // Mirror what was paid, so a tax return nets the void back out.
            $originalTaxes = TaxService::readPurchaseTaxes($purchaseId);
            TaxService::writePurchaseTaxes(
                $reversalId,
                $originalTaxes->map(fn (object $row): TaxLine => new TaxLine(
                    new TaxComponent($row->code, $row->name, (int) $row->rate_bp, TaxBasis::from($row->basis), (bool) $row->is_recoverable),
                    Money::negate((int) $row->amount_minor),
                ))->all(),
                $originalTaxes->whereNotNull('component_id')->mapWithKeys(fn (object $row): array => [$row->code => (int) $row->component_id])->all(),
                $now,
            );

            // The goods leave at the cost they arrived at, read back from the
            // ledger (the discount and levies were spread into it), and from the
            // crates this delivery filled, consumed in the order they were made.
            $wentInAt = [];
            foreach (DB::table('stock_ledger')->where('source_type', 'PURCHASE')->where('source_id', $purchaseId)->orderBy('id')->get(['product_id', 'total_cost_minor']) as $row) {
                $wentInAt[(int) $row->product_id][] = (int) $row->total_cost_minor;
            }
            $wentInTo = [];
            foreach ($items as $item) {
                $wentInTo[(int) $item->product_id] ??= InventoryService::readBatchSplits('PURCHASE', $purchaseId, (int) $item->product_id);
            }

            $residual = 0;
            foreach ($items->values() as $index => $item) {
                $productId = (int) $item->product_id;
                if (DB::table('products')->where('id', $productId)->value('track_inventory')) {
                    $wentInAt[$productId] ??= [];
                    $cost = array_shift($wentInAt[$productId]);
                    $batches = array_shift($wentInTo[$productId]);
                    $movement = InventoryService::recordStockMovement([
                        'productId' => $productId,
                        'direction' => 'OUT',
                        'qty' => (int) $item->qty_milli,
                        ...($cost === null ? [] : ['totalCost' => $cost]),
                        ...($batches === null || $batches === [] ? [] : ['batch' => ['kind' => 'SOURCE', 'allocations' => $batches]]),
                        'movementType' => MovementType::PurchaseReturn,
                        'sourceType' => 'PURCHASE_VOID',
                        'sourceId' => $reversalId,
                        'sourceRef' => $purchaseNo,
                        'businessDate' => $businessDate,
                        'occurredAt' => $now,
                        'userId' => $actor->id,
                        'allowNegative' => $allowNegative,
                        'note' => "Void of {$original->purchase_no}",
                        'isDemo' => (bool) $original->is_demo,
                    ]);
                    $residual = Money::add($residual, $movement['residual']);
                }

                DB::table('purchase_items')->insert([
                    'purchase_id' => $reversalId,
                    'line_no' => $index + 1,
                    'product_id' => $productId,
                    'product_name' => $item->product_name,
                    'unit' => $item->unit,
                    'qty_milli' => -(int) $item->qty_milli,
                    'entry_unit' => $item->entry_unit,
                    'entry_qty_milli' => $item->entry_qty_milli === null ? null : -(int) $item->entry_qty_milli,
                    'entry_factor_milli' => $item->entry_factor_milli,
                    'unit_cost_minor' => $item->unit_cost_minor,
                    'discount_minor' => $item->discount_minor,
                    'line_total_minor' => Money::negate((int) $item->line_total_minor),
                    'created_at' => $at,
                ]);
            }

            foreach (DB::table('purchase_payments')->where('purchase_id', $purchaseId)->orderBy('id')->get() as $tenderRow) {
                DB::table('purchase_payments')->insert([
                    'purchase_id' => $reversalId,
                    'payment_account_id' => $tenderRow->payment_account_id,
                    'amount_minor' => Money::negate((int) $tenderRow->amount_minor),
                    'reference' => "Void of {$original->purchase_no}",
                    'created_at' => $at,
                ]);
            }

            if ($original->journal_entry_id !== null) {
                $reversed = JournalService::reverse(
                    (int) $original->journal_entry_id,
                    $businessDate,
                    JournalSourceType::PurchaseReturn,
                    $reversalId,
                    "Void of {$original->purchase_no}: {$why}",
                    occurredAt: $now,
                    actor: $actor,
                );
                DB::table('purchases')->where('id', $reversalId)->update(['journal_entry_id' => $reversed['entryId'], 'updated_at' => $at]);
            }

            // Value the shelf could not keep, as its own entry: the reversal says
            // what the original said, backwards; this says what averaging cost.
            if ($residual !== 0) {
                $cogs = JournalService::accountIdByCode(ChartOfAccounts::COST_OF_GOODS_SOLD);
                $inventory = JournalService::accountIdByCode(ChartOfAccounts::INVENTORY);
                JournalService::post(
                    $businessDate,
                    JournalSourceType::PurchaseReturn,
                    $residual > 0
                        ? [
                            Journal::debit($cogs, $residual, ['description' => "{$purchaseNo} costing difference"]),
                            Journal::credit($inventory, $residual, ['description' => "{$purchaseNo} stock value released"]),
                        ]
                        : [
                            Journal::debit($inventory, Money::negate($residual), ['description' => "{$purchaseNo} stock value restored"]),
                            Journal::credit($cogs, Money::negate($residual), ['description' => "{$purchaseNo} costing difference"]),
                        ],
                    sourceId: $reversalId,
                    memo: "Stock costing difference on voiding {$original->purchase_no}",
                    occurredAt: $now,
                    actor: $actor,
                );
            }

            DB::table('purchases')->where('id', $purchaseId)->update([
                'status' => 'VOIDED',
                'voided_at' => $at,
                'void_reason' => $why,
                'voided_by_purchase_id' => $reversalId,
                'updated_at' => $at,
            ]);
            AuditService::write(AuditAction::Void, 'purchase', "Voided {$original->purchase_no} with {$purchaseNo}", $purchaseId, $actor, ['reason' => $why], $now);

            return ['reversalPurchaseId' => $reversalId, 'purchaseNo' => $purchaseNo];
        });
    }

    /** Supplier payments still standing against one purchase. */
    private static function settledByPayments(int $purchaseId): int
    {
        return (int) DB::selectOne('SELECT '.str_replace('{purchase}', '?', self::SETTLED_SQL).' AS s', [$purchaseId])->s;
    }

    // --- reads --------------------------------------------------------------

    /**
     * What is still owed on one purchase: total less payments and goods
     * credited back. Negative when the supplier owes the shop. A void, its
     * mirror, and a return whose purchase stands owe nothing.
     */
    public static function getPurchaseOutstanding(int $purchaseId): int
    {
        $purchase = DB::table('purchases')->where('id', $purchaseId)->first() ?? throw new NotFoundError('Purchase', $purchaseId);
        if ($purchase->status === 'VOIDED' || $purchase->voids_purchase_id !== null) {
            return 0;
        }
        if ($purchase->returns_purchase_id !== null && DB::table('purchases')->where('id', $purchase->returns_purchase_id)->value('status') !== 'VOIDED') {
            return 0;
        }

        $paid = (int) DB::table('purchase_payments')->where('purchase_id', $purchaseId)->sum('amount_minor');

        return Money::subtract((int) $purchase->total_minor, $paid + self::settledByPayments($purchaseId) + self::creditedByReturns($purchaseId));
    }

    /** Goods from this purchase that went back on account. */
    public static function creditedByReturns(int $purchaseId): int
    {
        return (int) DB::selectOne('SELECT '.self::creditedSql('?').' AS c', [$purchaseId])->c;
    }

    /**
     * Outstanding for every posted purchase in one pass, with the same
     * definition as getPurchaseOutstanding. With asAt, a purchase still good
     * that day counts, and only payments dated by then.
     *
     * @param  array{supplierId?: int, asAt?: string}  $options
     * @return array<int, int> outstanding by purchase id
     */
    public static function getOutstandingByPurchase(array $options = []): array
    {
        $supplierId = $options['supplierId'] ?? null;
        $asAt = $options['asAt'] ?? null;

        $documents = DB::table('purchases')->whereNull('purchases.voids_purchase_id');
        if ($supplierId !== null) {
            $documents->where('purchases.supplier_id', $supplierId);
        }
        if ($asAt === null) {
            $documents->where('purchases.status', 'POSTED');
        } else {
            $documents->where('purchases.business_date', '<=', $asAt)
                ->where(fn (Builder $q) => $q->where('purchases.status', 'POSTED')->orWhereRaw(
                    'NOT EXISTS (SELECT 1 FROM `purchases` `mirror` WHERE `mirror`.`voids_purchase_id` = `purchases`.`id` AND `mirror`.`business_date` <= ?)',
                    [$asAt],
                ));
        }
        $rows = $documents->orderBy('purchases.id')->get(['purchases.id', 'purchases.total_minor', 'purchases.returns_purchase_id']);

        // One supplier's purchases (and their returns, which carry the same supplier), not the whole shop.
        $ofSupplier = fn (string $column): \Closure => fn (Builder $q) => $q->whereIn($column, DB::table('purchases')->select('purchases.id')->where('purchases.supplier_id', $supplierId));

        $paid = DB::table('purchase_payments')->when($supplierId !== null, $ofSupplier('purchase_payments.purchase_id'))->groupBy('purchase_payments.purchase_id')
            ->selectRaw('`purchase_payments`.`purchase_id` AS id, COALESCE(SUM(`purchase_payments`.`amount_minor`), 0) AS t')->pluck('t', 'id')->all();
        $settledQuery = DB::table('supplier_payment_allocations')
            ->join('supplier_payments', 'supplier_payments.id', '=', 'supplier_payment_allocations.payment_id')
            ->where('supplier_payments.status', 'POSTED')
            ->when($supplierId !== null, $ofSupplier('supplier_payment_allocations.purchase_id'));
        if ($asAt !== null) {
            $settledQuery->where('supplier_payments.business_date', '<=', $asAt);
        }
        $settled = $settledQuery->groupBy('supplier_payment_allocations.purchase_id')
            ->selectRaw('`supplier_payment_allocations`.`purchase_id` AS id, COALESCE(SUM(`supplier_payment_allocations`.`amount_minor`), 0) AS t')->pluck('t', 'id')->all();

        $counted = [];
        foreach ($rows as $row) {
            $counted[(int) $row->id] = true;
        }

        $credited = [];
        foreach ($rows as $row) {
            $of = $row->returns_purchase_id === null ? null : (int) $row->returns_purchase_id;
            if ($of === null || ! isset($counted[$of])) {
                continue;
            }
            $credited[$of] = ($credited[$of] ?? 0) - (int) $row->total_minor + (int) ($paid[$row->id] ?? 0);
        }

        $outstanding = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            if ($row->returns_purchase_id !== null && isset($counted[(int) $row->returns_purchase_id])) {
                $outstanding[$id] = 0;

                continue;
            }
            $outstanding[$id] = Money::of((int) $row->total_minor - (int) ($paid[$id] ?? 0) - (int) ($settled[$id] ?? 0) - ($credited[$id] ?? 0));
        }

        return $outstanding;
    }

    /**
     * Every purchase filter as one clause, shared by the list, the count and
     * the totals.
     *
     * Query keys: from, to, supplierId, productId, categoryId, paymentAccountId, paymentKind,
     * status, kind, paymentState ('paid'|'partial'|'outstanding'|'unpaid'), unpaidOnly, search,
     * minAmount, maxAmount, sort, direction, limit, offset.
     */
    private static function applyConditions(Builder $q, array $query): void
    {
        if (isset($query['from'])) {
            $q->where('purchases.business_date', '>=', $query['from']);
        }
        if (isset($query['to'])) {
            $q->where('purchases.business_date', '<=', $query['to']);
        }
        if (isset($query['supplierId'])) {
            $q->where('purchases.supplier_id', $query['supplierId']);
        }
        if (isset($query['status'])) {
            $q->where('purchases.status', $query['status']);
        }
        if (isset($query['kind'])) {
            $q->where('purchases.kind', $query['kind']);
        }
        if (isset($query['productId'])) {
            $q->whereRaw('EXISTS (SELECT 1 FROM `purchase_items` WHERE `purchase_items`.`purchase_id` = `purchases`.`id` AND `purchase_items`.`product_id` = ?)', [$query['productId']]);
        }
        if (isset($query['categoryId'])) {
            $q->whereRaw('EXISTS (SELECT 1 FROM `purchase_items` JOIN `products` ON `products`.`id` = `purchase_items`.`product_id`
                WHERE `purchase_items`.`purchase_id` = `purchases`.`id` AND `products`.`category_id` = ?)', [$query['categoryId']]);
        }
        if (isset($query['paymentAccountId'])) {
            $q->whereRaw('EXISTS (SELECT 1 FROM `purchase_payments` `pp` WHERE `pp`.`purchase_id` = `purchases`.`id` AND `pp`.`payment_account_id` = ?)', [$query['paymentAccountId']]);
        }
        if (isset($query['paymentKind'])) {
            $q->whereRaw('EXISTS (SELECT 1 FROM `purchase_payments` `pp` JOIN `payment_accounts` `pa` ON `pa`.`id` = `pp`.`payment_account_id`
                WHERE `pp`.`purchase_id` = `purchases`.`id` AND `pa`.`kind` = ?)', [$query['paymentKind']]);
        }

        $state = $query['paymentState'] ?? (($query['unpaidOnly'] ?? false) ? 'outstanding' : null);
        if ($state === 'outstanding' || $state === 'unpaid') {
            $q->whereRaw(self::outstandingSql().' > 0');
        } elseif ($state === 'paid') {
            $q->whereRaw(self::outstandingSql().' <= 0');
        } elseif ($state === 'partial') {
            $q->whereRaw(self::outstandingSql().' > 0 AND '.self::paidSql().' > 0');
        }

        // The absolute total, so a void sits in the same bracket as its purchase.
        if (isset($query['minAmount'])) {
            $q->whereRaw('ABS(`purchases`.`total_minor`) >= ?', [$query['minAmount']]);
        }
        if (isset($query['maxAmount'])) {
            $q->whereRaw('ABS(`purchases`.`total_minor`) <= ?', [$query['maxAmount']]);
        }

        $search = JsCompat::trim($query['search'] ?? '');
        if ($search !== '') {
            $term = CatalogService::containsPattern(mb_strtolower($search));
            $q->whereRaw("(
                LOWER(`purchases`.`purchase_no`) LIKE ?
                OR LOWER(COALESCE(`purchases`.`invoice_no`, '')) LIKE ?
                OR EXISTS (SELECT 1 FROM `suppliers` `ps` WHERE `ps`.`id` = `purchases`.`supplier_id`
                           AND (LOWER(`ps`.`name`) LIKE ? OR LOWER(COALESCE(`ps`.`phone`, '')) LIKE ?))
                OR EXISTS (SELECT 1 FROM `purchase_items` `pi` LEFT JOIN `products` `pp2` ON `pp2`.`id` = `pi`.`product_id`
                           WHERE `pi`.`purchase_id` = `purchases`.`id`
                             AND (LOWER(`pi`.`product_name`) LIKE ? OR LOWER(COALESCE(`pp2`.`sku`, '')) LIKE ?))
            )", array_fill(0, 6, $term));
        }
    }

    private static function applyOrder(Builder $q, array $query): void
    {
        $dir = ($query['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        match ($query['sort'] ?? 'date') {
            'amount' => $q->orderByRaw("`purchases`.`total_minor` {$dir}")->orderByRaw('`purchases`.`id` DESC'),
            'outstanding' => $q->orderByRaw(self::outstandingSql()." {$dir}")->orderByRaw('`purchases`.`id` DESC'),
            'supplier' => $q->orderByRaw("LOWER(COALESCE(`suppliers`.`name`, 'zzzz')) {$dir}")->orderByRaw('`purchases`.`id` DESC'),
            'reference' => $q->orderByRaw("`purchases`.`purchase_no` {$dir}")->orderByRaw('`purchases`.`id` DESC'),
            // The delivery date the row shows, not the moment it was keyed in.
            default => $q->orderByRaw("`purchases`.`business_date` {$dir}")->orderByRaw("`purchases`.`id` {$dir}"),
        };
    }

    /** @return list<array> */
    public static function listPurchases(array $query = []): array
    {
        $q = DB::table('purchases')->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id');
        self::applyConditions($q, $query);
        self::applyOrder($q, $query);

        $rows = $q->limit(min($query['limit'] ?? 100, 500))->offset($query['offset'] ?? 0)->selectRaw(
            '`purchases`.`id`, `purchases`.`purchase_no`, `purchases`.`kind`, `purchases`.`business_date`, `purchases`.`occurred_at`,
             `purchases`.`invoice_no`, `purchases`.`supplier_id`, `suppliers`.`name` AS supplier_name, `purchases`.`total_minor`, `purchases`.`status`,
             (SELECT COUNT(*) FROM `purchase_items` WHERE `purchase_items`.`purchase_id` = `purchases`.`id`) AS item_count, '
            .self::outstandingSql().' AS outstanding_minor, '.self::paidSql().' AS paid_minor',
        )->get();

        return $rows->map(fn (object $row): array => [
            ...Row::camel($row),
            'itemCount' => (int) $row->item_count,
            'outstandingMinor' => (int) $row->outstanding_minor,
            'paidMinor' => (int) $row->paid_minor,
        ])->all();
    }

    /** How many purchases match, ignoring the page. */
    public static function countPurchases(array $query = []): int
    {
        $q = DB::table('purchases');
        self::applyConditions($q, $query);

        return $q->count();
    }

    /**
     * Totals for exactly the purchases a filter selects, from the same clause
     * as the table. count is deliveries, not their returns or voids.
     *
     * @return array{count: int, total: int, paid: int, outstanding: int}
     */
    public static function getFilteredPurchasesSummary(array $query = []): array
    {
        $q = DB::table('purchases');
        self::applyConditions($q, $query);
        $row = $q->selectRaw("COALESCE(SUM(CASE WHEN `purchases`.`kind` = 'PURCHASE' THEN 1 ELSE 0 END), 0) AS cnt,
            COALESCE(SUM(`purchases`.`total_minor`), 0) AS total,
            COALESCE(SUM(".self::paidSql().'), 0) AS paid,
            COALESCE(SUM('.self::outstandingSql().'), 0) AS outstanding')->first();

        return ['count' => (int) $row->cnt, 'total' => (int) $row->total, 'paid' => (int) $row->paid, 'outstanding' => (int) $row->outstanding];
    }

    public static function getPurchase(int $purchaseId): array
    {
        $purchase = DB::table('purchases')->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.id', $purchaseId)
            ->first(['purchases.*', 'suppliers.name AS supplier_name', 'suppliers.phone AS supplier_phone'])
            ?? throw new NotFoundError('Purchase', $purchaseId);

        // Which crate each line filled, shown only where somebody dated it.
        $splits = self::batchSplitByPurchaseItem($purchaseId);
        $batchIds = [];
        foreach ($splits as $split) {
            array_push($batchIds, ...array_column($split, 'batchId'));
        }
        $dates = $batchIds === [] ? collect() : DB::table('product_batches')->whereIn('id', array_unique($batchIds))->pluck('expiry_date', 'id');

        $items = DB::table('purchase_items')->where('purchase_id', $purchaseId)->orderBy('id')->get()
            ->map(fn (object $line): array => [
                ...Row::camel($line),
                'batches' => array_values(array_filter(array_map(
                    fn (array $a): array => ['batchRef' => $a['batchRef'], 'expiryDate' => $dates[$a['batchId']] ?? null],
                    $splits[(int) $line->id] ?? [],
                ), fn (array $b): bool => $b['expiryDate'] !== null)),
            ])->all();

        return [
            ...Row::camel($purchase),
            'items' => $items,
            'tenders' => DB::table('purchase_payments')
                ->join('payment_accounts', 'payment_accounts.id', '=', 'purchase_payments.payment_account_id')
                ->where('purchase_payments.purchase_id', $purchaseId)
                ->orderBy('purchase_payments.id')
                ->get(['purchase_payments.id', 'purchase_payments.amount_minor', 'purchase_payments.reference', 'payment_accounts.name AS account_name'])
                ->map(Row::camel(...))->all(),
            'outstandingMinor' => self::getPurchaseOutstanding($purchaseId),
        ];
    }

    /** @return array{count: int, total: int} posted purchase documents dated in a period */
    public static function getPurchasesSummary(string $from, string $to): array
    {
        $row = DB::table('purchases')
            ->where('purchases.business_date', '>=', $from)
            ->where('purchases.business_date', '<=', $to)
            ->where('purchases.status', 'POSTED')
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(`purchases`.`total_minor`), 0) AS total')
            ->first();

        return ['count' => (int) $row->cnt, 'total' => (int) $row->total];
    }

    /** Unpaid purchases for a supplier, oldest first: what the payment form offers. @return list<array> */
    public static function getOpenPurchases(int $supplierId): array
    {
        $outstanding = self::getOutstandingByPurchase(['supplierId' => $supplierId]);

        return DB::table('purchases')->where('purchases.supplier_id', $supplierId)->where('purchases.status', 'POSTED')
            ->orderBy('purchases.business_date')->orderBy('purchases.id')
            ->get(['purchases.id', 'purchases.purchase_no', 'purchases.business_date', 'purchases.total_minor'])
            ->map(fn (object $row): array => [...Row::camel($row), 'outstandingMinor' => $outstanding[(int) $row->id] ?? 0])
            ->filter(fn (array $row): bool => $row['outstandingMinor'] > 0)
            ->values()
            ->all();
    }

    /**
     * Each line of a delivery mapped to the batch split it produced. Lines
     * whose product does not track stock moved nothing and take nothing.
     *
     * @return array<int, list<array>> by purchase_items.id
     */
    public static function batchSplitByPurchaseItem(int $purchaseId): array
    {
        $lines = DB::table('purchase_items')
            ->join('products', 'products.id', '=', 'purchase_items.product_id')
            ->where('purchase_items.purchase_id', $purchaseId)
            ->orderBy('purchase_items.line_no')
            ->get(['purchase_items.id', 'purchase_items.product_id', 'products.track_inventory']);

        $queues = [];
        $byLine = [];
        foreach ($lines as $line) {
            if (! $line->track_inventory) {
                continue;
            }
            $productId = (int) $line->product_id;
            $queues[$productId] ??= InventoryService::readBatchSplits('PURCHASE', $purchaseId, $productId);
            $split = array_shift($queues[$productId]);
            if ($split !== null && $split !== []) {
                $byLine[(int) $line->id] = $split;
            }
        }

        return $byLine;
    }
}
