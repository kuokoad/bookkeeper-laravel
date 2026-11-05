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
use App\Domain\Tax\TaxComponents;
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
 * come back so the same goods cannot come back twice. A port of the customer
 * half of the old returns.service.ts; the supplier half arrives with
 * purchases in Phase 5 and shares formatQtyLabel, chargedTaxLines and the
 * refund-cap shape.
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
        // Two requests for one line would each pass the returnable check against the same figure.
        // TODO(Phase 5): createSupplierReturn needs this same guard.
        $itemIds = array_map(fn (array $r): int => (int) $r['itemId'], $input['items']);
        if (count($itemIds) !== count(array_unique($itemIds))) {
            throw new ValidationError('Each item can appear only once in a return.');
        }

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
            $lockedById = $originalLines->keyBy(fn (object $l): int => (int) $l->id);
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
                $resolved[] = [
                    'request' => $request,
                    'item' => $item,
                    'revenueShare' => Money::mulDiv($net, $request['qty'], (int) $item->qty_milli),
                    'costShare' => Money::mulDiv((int) $item->total_cost_minor, $request['qty'], (int) $item->qty_milli),
                ];
            }

            $totalRevenue = Money::sum(array_column($resolved, 'revenueShare'));
            $totalCost = Money::sum(array_column($resolved, 'costShare'));
            if ($totalRevenue === 0 && $totalCost === 0) {
                throw new ValidationError('This return has no value to record.');
            }

            // The tax goes back with the goods, component by component, from what
            // the ORIGINAL sale charged, apportioned by how much comes back.
            $saleNetRevenue = Money::sum($netByItemId);
            [$charged, $componentIds] = self::chargedTaxLines(TaxService::readSaleTaxes($originalSaleId));
            $returnedLines = $charged === [] || $saleNetRevenue === 0 ? [] : TaxComponents::shareOf($charged, $totalRevenue, $saleNetRevenue);
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
            foreach ($resolved as $index => $line) {
                $item = $line['item'];
                $tracked = (bool) DB::table('products')->where('id', $item->product_id)->value('track_inventory');
                if ($tracked && $line['costShare'] !== 0) {
                    $cameFrom = $soldFrom[(int) $item->id] ?? null;
                    InventoryService::recordStockMovement([
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
                $account = DB::table('payment_accounts')->where('id', $refund['paymentAccountId'])->first() ?? throw new NotFoundError('Payment account', $refund['paymentAccountId']);
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

    /** Value of goods customers brought back in a period. */
    public static function getSalesReturnsTotal(string $from, string $to): int
    {
        return (int) DB::table('sales')
            ->where('sales.kind', 'RETURN')
            ->where('sales.business_date', '>=', $from)
            ->where('sales.business_date', '<=', $to)
            ->where('sales.status', 'POSTED')
            ->selectRaw('COALESCE(SUM(-`sales`.`total_minor`), 0) AS t')
            ->value('t');
    }
}
