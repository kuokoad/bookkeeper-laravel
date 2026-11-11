<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Domain\Sales\SaleCalculator;
use App\Domain\Tax\TaxComponent;
use App\Domain\Tax\TaxLine;
use App\Enums\AuditAction;
use App\Enums\DocType;
use App\Enums\JournalSourceType;
use App\Enums\MovementType;
use App\Enums\TaxBasis;
use App\Support\Clock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Returns: goods coming back. A return is a NEW document linked to the
 * original, never an edit of it, and each line tracks how much has already
 * come back so the same goods cannot come back twice. A port of the old
 * returns.service.ts, both directions: a customer bringing goods back and the
 * shop sending them back to a supplier.
 *
 * Input: ['businessDate', 'items' => list of ['itemId', 'qty'],
 * 'refunds'? => list of ['paymentAccountId', 'amount'], 'reason'?, 'occurredAt'? => Carbon, 'isDemo'?].
 */
final class ReturnsService
{
    /**
     * A customer brings goods back. Stock comes in at the cost it left at,
     * revenue comes off through Sales Returns, the tax goes back with the
     * goods, and the customer is made whole in cash (never more than they
     * paid beyond what they keep) or on their account.
     *
     * @return array{returnSaleId: int, receiptNo: string, refunded: int, creditApplied: int}
     */
    public static function createCustomerReturn(int $originalSaleId, array $input, Actor $actor): array
    {
        if (($input['items'] ?? []) === []) {
            throw new ValidationError('Choose at least one item to return.');
        }
        self::assertEachItemOnce($input['items']);

        return Tx::run(function () use ($originalSaleId, $input, $actor): array {
            $occurredAt = $input['occurredAt'] ?? Clock::now();
            $at = Clock::stamp($occurredAt);
            $isDemo = $input['isDemo'] ?? false;

            // Lock order (Tx): products, the customer, then the sale and its lines.
            InventoryService::lockProducts(DB::table('sale_items')->where('sale_items.sale_id', $originalSaleId)->pluck('product_id')->all());
            $customerId = DB::table('sales')->where('id', $originalSaleId)->value('customer_id');
            if ($customerId !== null) {
                CustomerService::lockCustomer((int) $customerId);
            }
            $original = DB::table('sales')->where('id', $originalSaleId)->lockForUpdate()->first() ?? throw new NotFoundError('Sale', $originalSaleId);
            if ($original->status === 'VOIDED') {
                throw new ConflictError('That sale was voided, so there is nothing to return.');
            }
            if ($original->kind !== 'SALE') {
                throw new ConflictError('Returns can only be made against an original sale.');
            }
            $originalLines = DB::table('sale_items')->where('sale_id', $originalSaleId)->orderBy('line_no')->lockForUpdate()->get();

            // Tx 3a: the refund accounts, shared, so archiving one waits for this return.
            $refundAccounts = PaymentAccountService::lockTenderAccounts($input['refunds'] ?? []);
            JournalService::readLockDate();
            $receiptNo = SequenceService::next(DocType::SaleReturn);

            // The invoice discount, re-shared exactly as at the sale, measured the
            // way the line totals are (under inclusive prices they hold tax).
            $invoiceDiscount = $original->tax_inclusive
                ? Money::subtract(Money::sum($originalLines->pluck('line_total_minor')->map(fn ($v): int => (int) $v)), (int) $original->total_minor)
                : (int) $original->discount_minor;
            $lineTotals = $originalLines->map(fn (object $l): int => (int) $l->line_total_minor)->all();
            $shares = $invoiceDiscount === 0 ? array_fill(0, count($lineTotals), 0) : Money::allocate($invoiceDiscount, $lineTotals);
            $netByItemId = [];
            foreach ($originalLines->values() as $index => $line) {
                $netByItemId[(int) $line->id] = Money::subtract((int) $line->line_total_minor, $shares[$index] ?? 0);
            }

            // --- each line against what is still returnable, as locked ------
            // Shares are cumulative (D25, as the supplier side): what has come back
            // so far, after less before, so partial returns add up to a full one.
            $lockedById = $originalLines->keyBy(fn (object $l): int => (int) $l->id);
            $returnedBefore = $lockedById->map(fn (object $l): int => (int) $l->returned_qty_milli)->all();
            $returnedAfter = $returnedBefore;
            $resolved = [];
            foreach ($input['items'] as $request) {
                $item = $lockedById->get((int) $request['itemId']);
                if ($item === null) {
                    throw DB::table('sale_items')->where('id', $request['itemId'])->exists()
                        ? new ValidationError('That item is not part of this sale.')
                        : new NotFoundError('Sale item', $request['itemId']);
                }
                if ($request['qty'] <= 0) {
                    throw new ValidationError('Return quantity must be greater than zero.');
                }
                $returnable = (int) $item->qty_milli - (int) $item->returned_qty_milli;
                if ($request['qty'] > $returnable) {
                    throw new ValidationError('Only '.self::formatQtyLabel($returnable)." of {$item->product_name} can still be returned.", [
                        'returnable' => $returnable, 'requested' => $request['qty'],
                    ]);
                }
                // Revenue from the line's NET value, cost from its snapshot.
                $net = $netByItemId[(int) $item->id] ?? (int) $item->line_total_minor;
                $before = (int) $item->returned_qty_milli;
                $after = $before + $request['qty'];
                $returnedAfter[(int) $item->id] = $after;
                $resolved[] = [
                    'request' => $request,
                    'item' => $item,
                    'revenueShare' => self::cumulativeShare($net, $before, $after, (int) $item->qty_milli),
                    'costShare' => self::cumulativeShare((int) $item->total_cost_minor, $before, $after, (int) $item->qty_milli),
                ];
            }

            $totalRevenue = Money::sum(array_column($resolved, 'revenueShare'));
            $totalCost = Money::sum(array_column($resolved, 'costShare'));
            if ($totalRevenue === 0 && $totalCost === 0) {
                throw new ValidationError('This return has no value to record.');
            }

            // The tax goes back with the goods, component by component, from what
            // the ORIGINAL sale charged, apportioned by how much comes back.
            // Each component on its own running share of the net returned, never a
            // split of a rounded total, so no piece can step a component backwards.
            $saleNetRevenue = Money::sum($netByItemId);
            $measure = fn (array $returned): int => Money::sum($lockedById->map(fn (object $l): int => Money::mulDiv($netByItemId[(int) $l->id], $returned[(int) $l->id], (int) $l->qty_milli))->all());
            $basisBefore = $measure($returnedBefore);
            $basisAfter = $measure($returnedAfter);
            [$charged, $componentIds] = self::chargedTaxLines(TaxService::readSaleTaxes($originalSaleId));
            $returnedLines = $charged === [] || $saleNetRevenue === 0 ? [] : array_map(
                fn (TaxLine $l): TaxLine => $l->withAmount(self::cumulativeShare($l->amount, $basisBefore, $basisAfter, $saleNetRevenue)),
                $charged,
            );
            $taxShare = (int) $original->tax_minor === 0 ? 0 : Money::sum(array_map(fn (TaxLine $l): int => $l->amount, $returnedLines));

            // Inclusive prices already hold the tax; exclusive ones carry it on top.
            $returnedValue = $original->tax_inclusive ? $totalRevenue : Money::add($totalRevenue, $taxShare);
            $revenueReversed = $original->tax_inclusive ? Money::subtract($totalRevenue, $taxShare) : $totalRevenue;

            // Read before the return exists, or it would be measured against itself;
            // after the customer and sale locks, so no other return or payment is mid-way.
            // Money paid onto the account still came in, so the lesser owed counts.
            $outstandingOnOriginal = SaleService::getSaleOutstanding($originalSaleId);
            $owedBeforeReturn = $original->customer_id === null
                ? $outstandingOnOriginal
                : Money::min($outstandingOnOriginal, CustomerService::getCustomerBalance((int) $original->customer_id));

            $returnId = (int) DB::table('sales')->insertGetId([
                'receipt_no' => $receiptNo,
                'kind' => 'RETURN',
                'returns_sale_id' => $originalSaleId,
                'customer_id' => $original->customer_id,
                'business_date' => $input['businessDate'],
                'occurred_at' => $at,
                // Mirror figures, net of tax, so subtotal − discount + tax = total holds here too.
                'subtotal_minor' => Money::negate($revenueReversed),
                'discount_minor' => 0,
                'tax_minor' => Money::negate($taxShare),
                'total_minor' => Money::negate($returnedValue),
                'tax_inclusive' => (bool) $original->tax_inclusive,
                'cogs_minor' => Money::negate($totalCost),
                'status' => 'POSTED',
                'note' => $input['reason'] ?? "Return against {$original->receipt_no}",
                'created_by' => $actor->id,
                'is_demo' => $isDemo,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $returnedIds = [];
            foreach ($returnedLines as $index => $line) {
                if ($componentIds[$index] !== null) {
                    $returnedIds[$line->component->code] = $componentIds[$index];
                }
            }
            TaxService::writeSaleTaxes($returnId, array_map(fn (TaxLine $l): TaxLine => $l->withAmount(Money::negate($l->amount)), $returnedLines), $returnedIds, $occurredAt);

            // --- stock back in, at the original cost, into the crates it came from
            $soldFrom = SaleService::batchSplitBySaleItem($originalSaleId);
            $residual = 0;
            foreach ($resolved as $index => $line) {
                $item = $line['item'];
                $tracked = (bool) DB::table('products')->where('id', $item->product_id)->value('track_inventory');
                // D27: always back on the shelf, even at no cost (stockIn takes a zero).
                if ($tracked) {
                    $cameFrom = self::notYetReturned($soldFrom[(int) $item->id] ?? null, 'sales', 'returns_sale_id', $originalSaleId, 'SALE_RETURN', (int) $item->product_id, $line['request']['qty']);
                    $movement = InventoryService::recordStockMovement([
                        'productId' => (int) $item->product_id,
                        'direction' => 'IN',
                        'qty' => $line['request']['qty'],
                        'totalCost' => $line['costShare'],
                        ...($cameFrom === null ? [] : ['batch' => ['kind' => 'SOURCE', 'allocations' => $cameFrom]]),
                        'movementType' => MovementType::SaleReturn,
                        'sourceType' => 'SALE_RETURN',
                        'sourceId' => $returnId,
                        'sourceRef' => $receiptNo,
                        'businessDate' => $input['businessDate'],
                        'occurredAt' => $occurredAt,
                        'userId' => $actor->id,
                        'note' => "Return against {$original->receipt_no}",
                        'isDemo' => $isDemo,
                    ]);
                    // D26: goods back onto a shelf that was below zero.
                    $residual = Money::add($residual, $movement['residual']);
                }

                DB::table('sale_items')->insert([
                    'sale_id' => $returnId,
                    'line_no' => $index + 1,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'unit' => $item->unit,
                    'qty_milli' => -$line['request']['qty'],
                    // The return is in base units, so its price is too.
                    'unit_price_minor' => Quantity::perBaseUnitPrice((int) $item->unit_price_minor, $item->entry_factor_milli === null ? null : (int) $item->entry_factor_milli),
                    'discount_minor' => 0,
                    'line_total_minor' => Money::negate($line['revenueShare']),
                    'unit_cost_minor' => $item->unit_cost_minor,
                    'total_cost_minor' => Money::negate($line['costShare']),
                    'created_at' => $at,
                ]);

                // Mark the original line so the same goods cannot come back twice.
                DB::table('sale_items')->where('id', $item->id)->update(['returned_qty_milli' => (int) $item->returned_qty_milli + $line['request']['qty']]);
            }

            // --- how the customer is made whole --------------------------------
            $refunds = $input['refunds'] ?? [];
            $refundTotal = Money::sum(array_column($refunds, 'amount'));
            $refundable = SaleCalculator::refundableOnReturn($returnedValue, $owedBeforeReturn);
            if ($refundTotal > $refundable) {
                throw new ValidationError(
                    $refundable === 0
                        ? 'Nothing can be refunded in cash: the customer has not paid for more than the goods they are keeping. Put the return on their account instead.'
                        : 'At most '.Money::toDecimalString($refundable).' can be refunded in cash, which is what the customer paid beyond the goods they are keeping. Put the rest on their account.',
                    ['returnedValue' => $returnedValue, 'refundable' => $refundable, 'refundTotal' => $refundTotal],
                );
            }

            $creditApplied = Money::subtract($returnedValue, $refundTotal);
            if ($creditApplied > $outstandingOnOriginal && $original->customer_id === null) {
                throw new ValidationError('This sale was paid in full, so the return must be refunded rather than credited.', ['creditApplied' => $creditApplied]);
            }

            $lines = [];
            if ($revenueReversed !== 0) {
                $lines[] = Journal::debit(JournalService::accountIdByCode(ChartOfAccounts::SALES_RETURNS), $revenueReversed, ['description' => "{$receiptNo} goods returned"]);
            }
            // Each tax out of the account it went into.
            foreach ($returnedLines as $index => $line) {
                if ($line->amount === 0) {
                    continue;
                }
                $lines[] = Journal::debit(TaxService::accountFor($componentIds[$index], $line->component->code), $line->amount, ['description' => "{$receiptNo} {$line->component->name} on goods returned"]);
            }
            foreach ($refunds as $refund) {
                if ($refund['amount'] <= 0) {
                    continue;
                }
                $account = $refundAccounts[$refund['paymentAccountId']] ?? throw new NotFoundError('Payment account', $refund['paymentAccountId']);
                if (! $account->is_active) {
                    throw new ValidationError("Payment account \"{$account->name}\" is not active.");
                }
                DB::table('sale_payments')->insert([
                    'sale_id' => $returnId,
                    'payment_account_id' => $refund['paymentAccountId'],
                    'amount_minor' => Money::negate($refund['amount']),
                    'reference' => "Refund for {$original->receipt_no}",
                    'created_at' => $at,
                ]);
                $lines[] = Journal::credit((int) $account->gl_account_id, $refund['amount'], [
                    'paymentAccountId' => (int) $refund['paymentAccountId'],
                    'description' => "{$receiptNo} refunded ({$account->name})",
                ]);
            }
            if ($creditApplied !== 0) {
                $lines[] = Journal::credit(JournalService::accountIdByCode(ChartOfAccounts::ACCOUNTS_RECEIVABLE), $creditApplied, [
                    ...($original->customer_id !== null ? ['customerId' => (int) $original->customer_id] : []),
                    'description' => "{$receiptNo} credited to account",
                ]);
            }
            // Stock going back reverses the cost of goods sold.
            if ($totalCost !== 0) {
                $lines[] = Journal::debit(JournalService::accountIdByCode(ChartOfAccounts::INVENTORY), $totalCost, ['description' => "{$receiptNo} stock returned"]);
                $lines[] = Journal::credit(JournalService::accountIdByCode(ChartOfAccounts::COST_OF_GOODS_SOLD), $totalCost, ['description' => "{$receiptNo} cost reversed"]);
            }

            $posted = JournalService::post(
                $input['businessDate'],
                JournalSourceType::SaleReturn,
                $lines,
                sourceId: $returnId,
                memo: "{$receiptNo} — return against {$original->receipt_no}",
                isDemo: $isDemo,
                occurredAt: $occurredAt,
                actor: $actor,
            );
            DB::table('sales')->where('id', $returnId)->update(['journal_entry_id' => $posted['entryId'], 'updated_at' => $at]);
            PurchaseService::postCostingResidual($residual, $input['businessDate'], $receiptNo, $returnId, "Stock costing difference on {$receiptNo}", $occurredAt, $actor, JournalSourceType::SaleReturn, $isDemo);

            AuditService::write(AuditAction::Create, 'sale_return', "{$receiptNo}: return against {$original->receipt_no}", $returnId, $actor, [
                'revenueMinor' => $totalRevenue,
                'costMinor' => $totalCost,
                'refundedMinor' => $refundTotal,
                'creditedMinor' => $creditApplied,
                'entryNo' => $posted['entryNo'],
            ], $occurredAt);

            return ['returnSaleId' => $returnId, 'receiptNo' => $receiptNo, 'refunded' => $refundTotal, 'creditApplied' => $creditApplied];
        });
    }

    /**
     * The shop sends goods back. They leave at the price the SUPPLIER charged,
     * not the blended average, from the crate that supplier delivered; the tax
     * reclaim goes back with them; and the shop is made whole in cash (never
     * more than it paid beyond what it keeps) or off what it owes.
     *
     * @return array{returnPurchaseId: int, purchaseNo: string, refunded: int, creditApplied: int}
     */
    public static function createSupplierReturn(int $originalPurchaseId, array $input, Actor $actor): array
    {
        if (($input['items'] ?? []) === []) {
            throw new ValidationError('Choose at least one item to return.');
        }
        self::assertEachItemOnce($input['items']);

        return Tx::run(function () use ($originalPurchaseId, $input, $actor): array {
            $occurredAt = $input['occurredAt'] ?? Clock::now();
            $at = Clock::stamp($occurredAt);
            $isDemo = $input['isDemo'] ?? false;

            // Lock order (Tx): products, the supplier, then the purchase and its lines.
            InventoryService::lockProducts(DB::table('purchase_items')->where('purchase_items.purchase_id', $originalPurchaseId)->pluck('product_id')->all());
            $supplierId = DB::table('purchases')->where('id', $originalPurchaseId)->value('supplier_id');
            if ($supplierId !== null) {
                SupplierService::lockSupplier((int) $supplierId);
            }
            $original = DB::table('purchases')->where('id', $originalPurchaseId)->lockForUpdate()->first() ?? throw new NotFoundError('Purchase', $originalPurchaseId);
            if ($original->status === 'VOIDED') {
                throw new ConflictError('That purchase was voided, so there is nothing to return.');
            }
            if ($original->kind !== 'PURCHASE') {
                throw new ConflictError('Returns can only be made against an original purchase.');
            }
            $lockedById = DB::table('purchase_items')->where('purchase_id', $originalPurchaseId)->orderBy('line_no')->lockForUpdate()->get()
                ->keyBy(fn (object $l): int => (int) $l->id);

            // Tx 3a: the refund accounts, shared, so archiving one waits for this return.
            $refundAccounts = PaymentAccountService::lockTenderAccounts($input['refunds'] ?? []);
            $allowNegative = (bool) DB::table('business_settings')->where('id', 1)->value('allow_negative_stock');
            JournalService::readLockDate();
            $purchaseNo = SequenceService::next(DocType::PurchaseReturn);

            // What each line cost the shop, as a void reads it (D2): the stock
            // ledger's figure, invoice discount and folded levies spread in.
            $goodsCost = PurchaseService::goodsCostByItem($original, $lockedById);

            // --- each line against what is still returnable, as locked ----------
            // Shares are cumulative (what has come back so far, after less
            // before), so partial returns add up to exactly a full one.
            $resolved = [];
            $returnedBefore = $lockedById->map(fn (object $l): int => (int) $l->returned_qty_milli)->all();
            $returnedAfter = $returnedBefore;
            foreach ($input['items'] as $request) {
                $item = $lockedById->get((int) $request['itemId']);
                if ($item === null) {
                    throw DB::table('purchase_items')->where('id', $request['itemId'])->exists()
                        ? new ValidationError('That item is not part of this purchase.')
                        : new NotFoundError('Purchase item', $request['itemId']);
                }
                if ($request['qty'] <= 0) {
                    throw new ValidationError('Return quantity must be greater than zero.');
                }
                $before = (int) $item->returned_qty_milli;
                $returnable = (int) $item->qty_milli - $before;
                if ($request['qty'] > $returnable) {
                    throw new ValidationError('Only '.self::formatQtyLabel($returnable)." of {$item->product_name} can still be returned.");
                }
                $after = $before + $request['qty'];
                $returnedAfter[(int) $item->id] = $after;
                $resolved[] = [
                    'request' => $request,
                    'item' => $item,
                    'tracked' => $goodsCost[(int) $item->id]['tracked'],
                    'costShare' => self::cumulativeShare($goodsCost[(int) $item->id]['cost'], $before, $after, (int) $item->qty_milli),
                    'valueShare' => self::cumulativeShare((int) $item->line_total_minor, $before, $after, (int) $item->qty_milli),
                ];
            }

            $totalCost = Money::sum(array_column($resolved, 'costShare'));
            $stockCost = Money::sum(array_column(array_filter($resolved, fn (array $l): bool => $l['tracked']), 'costShare'));
            $sourceBatches = PurchaseService::batchSplitByPurchaseItem($originalPurchaseId);

            // The tax goes back with the goods (D1), component by component, from
            // what the ORIGINAL purchase carried, on the line values it was worked
            // from. Recoverability is carried across: it is a fact of the day bought.
            $measure = fn (array $returned): int => Money::sum($lockedById->map(fn (object $l): int => Money::mulDiv((int) $l->line_total_minor, $returned[(int) $l->id], (int) $l->qty_milli))->all());
            $basis = Money::sum($lockedById->map(fn (object $l): int => (int) $l->line_total_minor)->all());
            $basisBefore = $measure($returnedBefore);
            $basisAfter = $measure($returnedAfter);
            $paidRows = TaxService::readPurchaseTaxes($originalPurchaseId)->values();
            $returnedLines = [];
            $returnedIds = [];
            $legacyTax = 0;
            if ($paidRows->isNotEmpty() && $basis !== 0) {
                // Each component on its own running share, never a split of a
                // rounded total, so no piece can step a component backwards.
                foreach ($paidRows as $row) {
                    $returnedLines[] = new TaxLine(
                        new TaxComponent($row->code, $row->name, (int) $row->rate_bp, TaxBasis::from($row->basis), (bool) $row->is_recoverable),
                        self::cumulativeShare((int) $row->amount_minor, $basisBefore, $basisAfter, $basis),
                    );
                    if ($row->component_id !== null) {
                        $returnedIds[$row->code] = (int) $row->component_id;
                    }
                }
            } elseif ((int) $original->tax_minor !== 0 && $basis !== 0) {
                // A delivery with no tax rows (old data): its tax back off VAT Payable, as before.
                // Safe to treat as reclaimed: the old app wrote tax rows from its components commit
                // (ba771f7) on, and before it debited the single tax to 2100 in full, never folded.
                $legacyTax = self::cumulativeShare((int) $original->tax_minor, $basisBefore, $basisAfter, $basis);
            }
            $taxShare = Money::add($legacyTax, Money::sum(array_map(fn (TaxLine $l): int => $l->amount, $returnedLines)));
            // A levy that was not reclaimable is already inside the goods' cost.
            $recoverableShare = Money::add($legacyTax, TaxService::recoverableTotal($returnedLines));

            // What the supplier owes back: what the goods cost and the tax reclaimed on them.
            $returnedValue = Money::add($totalCost, $recoverableShare);
            if ($returnedValue === 0) {
                throw new ValidationError('This return has no value to record.');
            }
            $returnedSubtotal = Money::sum(array_column($resolved, 'valueShare'));

            // Read before the return exists, after the supplier and purchase locks.
            $outstandingOnOriginal = PurchaseService::getPurchaseOutstanding($originalPurchaseId);
            $owedBeforeReturn = $original->supplier_id === null
                ? $outstandingOnOriginal
                : Money::min($outstandingOnOriginal, SupplierService::getSupplierBalance((int) $original->supplier_id));

            $returnId = (int) DB::table('purchases')->insertGetId([
                'purchase_no' => $purchaseNo,
                'kind' => 'RETURN',
                'returns_purchase_id' => $originalPurchaseId,
                'supplier_id' => $original->supplier_id,
                'business_date' => $input['businessDate'],
                'occurred_at' => $at,
                'invoice_no' => $original->invoice_no,
                // Mirror figures, so subtotal − discount + tax = total holds here too.
                'subtotal_minor' => Money::negate($returnedSubtotal),
                // Derived so the identity holds, not prorated: can differ by a pesewa on a partial return.
                'discount_minor' => Money::negate(Money::subtract($returnedSubtotal, Money::subtract($returnedValue, $taxShare))),
                'tax_minor' => Money::negate($taxShare),
                'total_minor' => Money::negate($returnedValue),
                'status' => 'POSTED',
                'note' => $input['reason'] ?? "Return against {$original->purchase_no}",
                'created_by' => $actor->id,
                'is_demo' => $isDemo,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            TaxService::writePurchaseTaxes($returnId, array_map(fn (TaxLine $l): TaxLine => $l->withAmount(Money::negate($l->amount)), $returnedLines), $returnedIds, $occurredAt);

            $residual = 0;
            foreach ($resolved as $index => $line) {
                $item = $line['item'];
                if ($line['tracked']) {
                    // From the crate THIS supplier delivered, at what it cost the shop.
                    $cameFrom = self::notYetReturned($sourceBatches[(int) $item->id] ?? null, 'purchases', 'returns_purchase_id', $originalPurchaseId, 'PURCHASE_RETURN', (int) $item->product_id, $line['request']['qty']);
                    $movement = InventoryService::recordStockMovement([
                        'productId' => (int) $item->product_id,
                        'direction' => 'OUT',
                        'qty' => $line['request']['qty'],
                        'totalCost' => $line['costShare'],
                        ...($cameFrom === null ? [] : ['batch' => ['kind' => 'SOURCE', 'allocations' => $cameFrom]]),
                        'movementType' => MovementType::PurchaseReturn,
                        'sourceType' => 'PURCHASE_RETURN',
                        'sourceId' => $returnId,
                        'sourceRef' => $purchaseNo,
                        'businessDate' => $input['businessDate'],
                        'occurredAt' => $occurredAt,
                        'userId' => $actor->id,
                        'allowNegative' => $allowNegative,
                        'note' => "Return to supplier against {$original->purchase_no}",
                        'isDemo' => $isDemo,
                    ]);
                    $residual = Money::add($residual, $movement['residual']);
                }

                DB::table('purchase_items')->insert([
                    'purchase_id' => $returnId,
                    'line_no' => $index + 1,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'unit' => $item->unit,
                    'qty_milli' => -$line['request']['qty'],
                    'unit_cost_minor' => Quantity::perBaseUnitPrice((int) $item->unit_cost_minor, $item->entry_factor_milli === null ? null : (int) $item->entry_factor_milli),
                    'discount_minor' => 0,
                    'line_total_minor' => Money::negate($line['valueShare']),
                    'created_at' => $at,
                ]);
                DB::table('purchase_items')->where('id', $item->id)->update(['returned_qty_milli' => (int) $item->returned_qty_milli + $line['request']['qty']]);
            }

            // --- how the shop is made whole -------------------------------------
            $refunds = $input['refunds'] ?? [];
            $refundTotal = Money::sum(array_column($refunds, 'amount'));
            // A supplier hands back only money the shop actually paid them.
            $refundable = SaleCalculator::refundableOnReturn($returnedValue, $owedBeforeReturn);
            if ($refundTotal > $refundable) {
                throw new ValidationError(
                    $refundable === 0
                        ? 'Nothing can be refunded in cash: this delivery has not been paid for beyond the goods being kept. Take the return off what you owe instead.'
                        : 'At most '.Money::toDecimalString($refundable).' can be refunded in cash, which is what you paid beyond the goods being kept. Take the rest off what you owe.',
                    ['returnedValue' => $returnedValue, 'refundable' => $refundable, 'refundTotal' => $refundTotal],
                );
            }
            $creditApplied = Money::subtract($returnedValue, $refundTotal);

            // Each part back where the purchase put it: stock, non-stock expense,
            // and each reclaimed tax out of its own account.
            $lines = [];
            if ($stockCost !== 0) {
                $lines[] = Journal::credit(JournalService::accountIdByCode(ChartOfAccounts::INVENTORY), $stockCost, ['description' => "{$purchaseNo} stock returned to supplier"]);
            }
            if ($totalCost !== $stockCost) {
                $lines[] = Journal::credit(JournalService::accountIdByCode('6900'), Money::subtract($totalCost, $stockCost), ['description' => "{$purchaseNo} non-stock items returned"]);
            }
            foreach ($returnedLines as $line) {
                if (! $line->component->isRecoverable || $line->amount === 0) {
                    continue;
                }
                $lines[] = Journal::credit(TaxService::accountFor($returnedIds[$line->component->code] ?? null, $line->component->code), $line->amount, ['description' => "{$purchaseNo} {$line->component->name} on goods returned"]);
            }
            if ($legacyTax !== 0) {
                $lines[] = Journal::credit(JournalService::accountIdByCode(ChartOfAccounts::TAX_PAYABLE), $legacyTax, ['description' => "{$purchaseNo} tax on goods returned"]);
            }
            foreach ($refunds as $refund) {
                if ($refund['amount'] <= 0) {
                    continue;
                }
                $account = $refundAccounts[$refund['paymentAccountId']] ?? throw new NotFoundError('Payment account', $refund['paymentAccountId']);
                if (! $account->is_active) {
                    throw new ValidationError("Payment account \"{$account->name}\" is not active.");
                }
                DB::table('purchase_payments')->insert([
                    'purchase_id' => $returnId,
                    'payment_account_id' => $refund['paymentAccountId'],
                    'amount_minor' => Money::negate($refund['amount']),
                    'reference' => "Refund for {$original->purchase_no}",
                    'created_at' => $at,
                ]);
                $lines[] = Journal::debit((int) $account->gl_account_id, $refund['amount'], [
                    'paymentAccountId' => (int) $refund['paymentAccountId'],
                    'description' => "{$purchaseNo} refunded by supplier ({$account->name})",
                ]);
            }
            if ($creditApplied !== 0) {
                $lines[] = Journal::debit(JournalService::accountIdByCode(ChartOfAccounts::ACCOUNTS_PAYABLE), $creditApplied, [
                    ...($original->supplier_id !== null ? ['supplierId' => (int) $original->supplier_id] : []),
                    'description' => "{$purchaseNo} credited against what we owe",
                ]);
            }

            $posted = JournalService::post(
                $input['businessDate'],
                JournalSourceType::PurchaseReturn,
                $lines,
                sourceId: $returnId,
                memo: "{$purchaseNo} — return against {$original->purchase_no}",
                isDemo: $isDemo,
                occurredAt: $occurredAt,
                actor: $actor,
            );
            DB::table('purchases')->where('id', $returnId)->update(['journal_entry_id' => $posted['entryId'], 'updated_at' => $at]);
            PurchaseService::postCostingResidual($residual, $input['businessDate'], $purchaseNo, $returnId, "Stock costing difference on returning {$original->purchase_no}", $occurredAt, $actor, JournalSourceType::PurchaseReturn, $isDemo);

            AuditService::write(AuditAction::Create, 'purchase_return', "{$purchaseNo}: return to supplier against {$original->purchase_no}", $returnId, $actor, [
                'valueMinor' => $totalCost,
                'refundedMinor' => $refundTotal,
                'creditedMinor' => $creditApplied,
                'entryNo' => $posted['entryNo'],
            ], $occurredAt);

            return ['returnPurchaseId' => $returnId, 'purchaseNo' => $purchaseNo, 'refunded' => $refundTotal, 'creditApplied' => $creditApplied];
        });
    }

    /** This return's part of $amount: the share up to $after out of $of, less the share up to $before. */
    private static function cumulativeShare(int $amount, int $before, int $after, int $of): int
    {
        return Money::subtract(Money::mulDiv($amount, $after, $of), Money::mulDiv($amount, $before, $of));
    }

    /** Two requests for one line would each pass the returnable check against the same figure. */
    private static function assertEachItemOnce(array $items): void
    {
        $itemIds = array_map(fn (array $r): int => (int) $r['itemId'], $items);
        if (count($itemIds) !== count(array_unique($itemIds))) {
            throw new ValidationError('Each item can appear only once in a return.');
        }
    }

    /**
     * A document's stored tax rows as domain lines (never recoverable on the
     * way back), with each row's component id alongside, by position.
     *
     * @return array{0: list<TaxLine>, 1: list<?int>}
     */
    public static function chargedTaxLines(Collection $rows): array
    {
        $lines = [];
        $ids = [];
        foreach ($rows as $row) {
            $lines[] = new TaxLine(new TaxComponent($row->code, $row->name, (int) $row->rate_bp, TaxBasis::from($row->basis), false), (int) $row->amount_minor);
            $ids[] = $row->component_id === null ? null : (int) $row->component_id;
        }

        return [$lines, $ids];
    }

    /** "1.5", "12": the old label, with no thousands grouping. */
    public static function formatQtyLabel(int $value): string
    {
        $whole = intdiv($value, Quantity::SCALE);
        $fraction = rtrim(str_pad((string) abs($value % Quantity::SCALE), 3, '0', STR_PAD_LEFT), '0');

        return $fraction !== '' ? "{$whole}.{$fraction}" : (string) $whole;
    }

    // --- reads --------------------------------------------------------------

    /** Lines from a sale that still have quantity to return. @return list<array> */
    public static function getReturnableSaleItems(int $saleId): array
    {
        return DB::table('sale_items')->where('sale_id', $saleId)->orderBy('id')->get()
            ->map(fn (object $item): array => [
                'id' => (int) $item->id,
                'productId' => (int) $item->product_id,
                'productName' => $item->product_name,
                'unit' => $item->unit,
                'qtyMilli' => (int) $item->qty_milli,
                'returnedQtyMilli' => (int) $item->returned_qty_milli,
                'returnableMilli' => (int) $item->qty_milli - (int) $item->returned_qty_milli,
                'unitPriceMinor' => Quantity::perBaseUnitPrice((int) $item->unit_price_minor, $item->entry_factor_milli === null ? null : (int) $item->entry_factor_milli),
            ])
            ->filter(fn (array $item): bool => $item['returnableMilli'] > 0)
            ->values()
            ->all();
    }

    /** Lines from a purchase that still have quantity to send back. @return list<array> */
    public static function getReturnablePurchaseItems(int $purchaseId): array
    {
        return DB::table('purchase_items')->where('purchase_id', $purchaseId)->orderBy('id')->get()
            ->map(fn (object $item): array => [
                'id' => (int) $item->id,
                'productId' => (int) $item->product_id,
                'productName' => $item->product_name,
                'unit' => $item->unit,
                'qtyMilli' => (int) $item->qty_milli,
                'returnedQtyMilli' => (int) $item->returned_qty_milli,
                'returnableMilli' => (int) $item->qty_milli - (int) $item->returned_qty_milli,
                'unitCostMinor' => Quantity::perBaseUnitPrice((int) $item->unit_cost_minor, $item->entry_factor_milli === null ? null : (int) $item->entry_factor_milli),
            ])
            ->filter(fn (array $item): bool => $item['returnableMilli'] > 0)
            ->values()
            ->all();
    }

    /**
     * What of a line's batch split earlier returns against the same document
     * have not yet taken back. Without it every partial return re-splits the
     * whole line, and three one-unit returns of a three-unit sale left one
     * batch holding more than it ever gave. Per product, since a return line
     * does not name the line it came from; if that leaves too little for this
     * return (one product on several lines), the line's own split is used.
     *
     * @param  list<array{batchId: int, batchRef: string, qtyMilli: int}>|null  $split
     * @return list<array{batchId: int, batchRef: string, qtyMilli: int}>|null
     */
    private static function notYetReturned(?array $split, string $table, string $returnsColumn, int $originalId, string $returnSourceType, int $productId, int $qty): ?array
    {
        if ($split === null || $split === []) {
            return $split;
        }
        $returned = [];
        $earlier = DB::table($table)->where("{$table}.{$returnsColumn}", $originalId)->where("{$table}.status", 'POSTED')->pluck("{$table}.id");
        foreach ($earlier as $returnId) {
            foreach (InventoryService::readBatchSplits($returnSourceType, (int) $returnId, $productId) as $movement) {
                foreach ($movement as $allocation) {
                    $returned[$allocation['batchId']] = ($returned[$allocation['batchId']] ?? 0) + $allocation['qtyMilli'];
                }
            }
        }
        $left = [];
        foreach ($split as $allocation) {
            $already = min($allocation['qtyMilli'], $returned[$allocation['batchId']] ?? 0);
            $returned[$allocation['batchId']] = ($returned[$allocation['batchId']] ?? 0) - $already;
            if ($allocation['qtyMilli'] > $already) {
                $left[] = [...$allocation, 'qtyMilli' => $allocation['qtyMilli'] - $already];
            }
        }

        return array_sum(array_column($left, 'qtyMilli')) >= $qty ? $left : $split;
    }
}
