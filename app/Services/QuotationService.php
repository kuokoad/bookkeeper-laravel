<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Domain\JsCompat;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Domain\Sales\SaleCalculator;
use App\Domain\Sales\SaleTotals;
use App\Enums\AuditAction;
use App\Enums\DocType;
use App\Support\Clock;
use App\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Quotations: offering a price, and later honouring it. A port of the old
 * quotation.service.ts.
 *
 * Nothing here posts or moves stock, except convertQuotation, which does it
 * through SaleService::createSale so a converted quote is an ordinary sale.
 * Every figure comes from SaleCalculator, as at the till.
 *
 * Input: ['businessDate'?, 'validUntil', 'customerName', 'customerId'?, 'customerPhone'?,
 * 'reference'?, 'lines' => list of ['productId', 'qty', 'unitPrice'?, 'discount'?, 'alt'?, 'altFactor'?],
 * 'quoteDiscount'?, 'notes'?].
 */
final class QuotationService
{
    public const QUOTATION_SORTS = ['date', 'total', 'validUntil'];

    private const SORT_COLUMNS = ['date' => 'quotations.business_date', 'total' => 'quotations.total_minor', 'validUntil' => 'quotations.valid_until'];

    /**
     * Resolve the lines and compute every figure, exactly as the till does.
     *
     * @return array{resolved: list<array>, totals: SaleTotals, taxInclusive: bool}
     */
    private static function priceLines(array $input): array
    {
        if (($input['lines'] ?? []) === []) {
            throw new ValidationError('Add at least one item to the quote.');
        }

        $resolved = [];
        foreach ($input['lines'] as $line) {
            $product = DB::table('products')->where('id', $line['productId'])->first() ?? throw new NotFoundError('Stock item', $line['productId']);
            if (! $product->is_active) {
                throw new ValidationError("\"{$product->name}\" is archived and cannot be quoted.");
            }
            $lineUnit = UnitsService::resolveLineUnit($product, $line['qty'], $line['alt'] ?? null, $line['altFactor'] ?? null);
            $resolved[] = ['request' => $line, 'product' => $product, 'lineUnit' => $lineUnit, 'unitPrice' => $line['unitPrice'] ?? $lineUnit['listSellingPrice']];
        }

        $profile = TaxService::profile();
        $totals = SaleCalculator::calculate(
            array_map(fn (array $e): array => [
                'productId' => (int) $e['product']->id,
                'qty' => $e['request']['qty'],
                'unitPrice' => $e['unitPrice'],
                ...(isset($e['request']['discount']) ? ['discount' => $e['request']['discount']] : []),
            ], $resolved),
            $input['quoteDiscount'] ?? 0,
            $profile['components'],
            $profile['inclusive'],
        );

        return ['resolved' => $resolved, 'totals' => $totals, 'taxInclusive' => $profile['inclusive']];
    }

    private static function assertDates(string $businessDate, string $validUntil): void
    {
        if ($validUntil < $businessDate) {
            throw new ValidationError('A quote cannot expire before the day it is issued.', ['businessDate' => $businessDate, 'validUntil' => $validUntil]);
        }
    }

    private static function writeItems(int $quotationId, array $priced, string $at): void
    {
        foreach ($priced['resolved'] as $index => $entry) {
            DB::table('quotation_items')->insert([
                'quotation_id' => $quotationId,
                'line_no' => $index + 1,
                'product_id' => (int) $entry['product']->id,
                // Snapshotted, so a reprint shows what was offered even after a rename.
                'product_name' => $entry['product']->name,
                'unit' => $entry['product']->unit,
                'qty_milli' => $entry['lineUnit']['baseQty'],
                'entry_unit' => $entry['lineUnit']['entryUnit'],
                'entry_qty_milli' => $entry['lineUnit']['entryQtyMilli'],
                'entry_factor_milli' => $entry['lineUnit']['entryFactorMilli'],
                'unit_price_minor' => $entry['unitPrice'],
                'discount_minor' => $entry['request']['discount'] ?? 0,
                'line_total_minor' => $priced['totals']->lines[$index]['lineTotal'],
                'created_at' => $at,
            ]);
        }
    }

    private static function figures(array $input, array $priced): array
    {
        return [
            'customer_id' => $input['customerId'] ?? null,
            'customer_phone' => $input['customerPhone'] ?? null,
            'reference' => $input['reference'] ?? null,
            'subtotal_minor' => $priced['totals']->subtotalExTax,
            'discount_minor' => $priced['totals']->invoiceDiscountExTax,
            'quote_discount_minor' => $input['quoteDiscount'] ?? 0,
            'tax_minor' => $priced['totals']->tax,
            'total_minor' => $priced['totals']->total,
            'tax_inclusive' => $priced['taxInclusive'],
            'notes' => $input['notes'] ?? null,
        ];
    }

    /** @return array{quotationId: int, quoteNo: string, total: int} */
    public static function createQuotation(array $input, Actor $actor): array
    {
        $name = JsCompat::trim($input['customerName'] ?? '');
        if ($name === '') {
            throw new ValidationError('Say who the quote is for.');
        }

        return Tx::run(function () use ($input, $name, $actor): array {
            $businessDate = $input['businessDate'] ?? Clock::today();
            self::assertDates($businessDate, $input['validUntil']);
            $priced = self::priceLines($input);
            $now = Clock::now();
            $at = Clock::stamp($now);
            $quoteNo = SequenceService::next(DocType::Quote);

            $id = (int) DB::table('quotations')->insertGetId([
                'quote_no' => $quoteNo,
                'business_date' => $businessDate,
                'valid_until' => $input['validUntil'],
                'customer_name' => $name,
                ...self::figures($input, $priced),
                'status' => 'OPEN',
                'created_by' => $actor->id,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            self::writeItems($id, $priced, $at);

            AuditService::write(AuditAction::Create, 'quotations', "Quote {$quoteNo} for {$name}, valid to {$input['validUntil']}", $id, $actor, null, $now);

            return ['quotationId' => $id, 'quoteNo' => $quoteNo, 'total' => $priced['totals']->total];
        });
    }

    /** Replace an open quote's contents. An offer not yet accepted has no history to protect. */
    public static function updateQuotation(int $quotationId, array $input, Actor $actor): void
    {
        $name = JsCompat::trim($input['customerName'] ?? '');
        if ($name === '') {
            throw new ValidationError('Say who the quote is for.');
        }

        Tx::run(function () use ($quotationId, $input, $name, $actor): void {
            $existing = DB::table('quotations')->where('id', $quotationId)->lockForUpdate()->first() ?? throw new NotFoundError('Quotation', $quotationId);
            if ($existing->status !== 'OPEN') {
                throw new ValidationError($existing->status === 'CONVERTED'
                    ? "Quote {$existing->quote_no} has already become a sale and can no longer be changed."
                    : "Quote {$existing->quote_no} was cancelled and can no longer be changed.");
            }

            $businessDate = $input['businessDate'] ?? $existing->business_date;
            self::assertDates($businessDate, $input['validUntil']);
            $priced = self::priceLines($input);
            $now = Clock::now();
            $at = Clock::stamp($now);

            DB::table('quotation_items')->where('quotation_id', $quotationId)->delete();
            DB::table('quotations')->where('id', $quotationId)->update([
                'business_date' => $businessDate,
                'valid_until' => $input['validUntil'],
                'customer_name' => $name,
                ...self::figures($input, $priced),
                'updated_at' => $at,
            ]);
            self::writeItems($quotationId, $priced, $at);

            AuditService::write(AuditAction::Update, 'quotations', "Quote {$existing->quote_no} changed", $quotationId, $actor, null, $now);
        });
    }

    public static function cancelQuotation(int $quotationId, string $reason, Actor $actor): void
    {
        $trimmed = JsCompat::trim($reason);
        if ($trimmed === '') {
            throw new ValidationError('Say why the quote is being cancelled.');
        }

        Tx::run(function () use ($quotationId, $trimmed, $actor): void {
            $existing = DB::table('quotations')->where('id', $quotationId)->lockForUpdate()->first() ?? throw new NotFoundError('Quotation', $quotationId);
            if ($existing->status !== 'OPEN') {
                throw new ValidationError("Quote {$existing->quote_no} is not open.");
            }
            $now = Clock::now();
            $at = Clock::stamp($now);
            DB::table('quotations')->where('id', $quotationId)->update(['status' => 'CANCELLED', 'cancelled_at' => $at, 'cancel_reason' => $trimmed, 'updated_at' => $at]);
            AuditService::write(AuditAction::Archive, 'quotations', "Quote {$existing->quote_no} cancelled: {$trimmed}", $quotationId, $actor, null, $now);
        });
    }

    // --- reads --------------------------------------------------------------

    public static function getQuotation(int $quotationId): array
    {
        $quote = DB::table('quotations')->where('id', $quotationId)->first() ?? throw new NotFoundError('Quotation', $quotationId);

        return [
            ...Row::camel($quote),
            'items' => DB::table('quotation_items')->where('quotation_id', $quotationId)->orderBy('line_no')->get()->map(Row::camel(...))->all(),
        ];
    }

    /**
     * Filters: status, expired, from, to, customerId, search, sort, direction, limit, offset.
     * One clause for the list and the count.
     */
    private static function applyConditions(Builder $q, array $filters, string $asAt): void
    {
        if (isset($filters['status'])) {
            $q->where('quotations.status', $filters['status']);
        }
        if (($filters['expired'] ?? false) === true) {
            $q->where('quotations.status', 'OPEN')->where('quotations.valid_until', '<=', $asAt);
        }
        if (isset($filters['from'])) {
            $q->where('quotations.business_date', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $q->where('quotations.business_date', '<=', $filters['to']);
        }
        if (isset($filters['customerId'])) {
            $q->where('quotations.customer_id', $filters['customerId']);
        }
        $search = JsCompat::trim($filters['search'] ?? '');
        if ($search !== '') {
            $term = CatalogService::containsPattern(mb_strtolower($search));
            $q->where(fn (Builder $w) => $w
                ->whereRaw('LOWER(`quotations`.`quote_no`) LIKE ?', [$term])
                ->orWhereRaw('LOWER(`quotations`.`customer_name`) LIKE ?', [$term])
                ->orWhereRaw("LOWER(COALESCE(`quotations`.`reference`, '')) LIKE ?", [$term]));
        }
    }

    /** @return list<array> */
    public static function listQuotations(array $filters = [], ?string $asAt = null): array
    {
        $q = DB::table('quotations');
        self::applyConditions($q, $filters, $asAt ?? Clock::today());
        // A sort key reaches an ORDER BY, so it is never a string off the wire.
        $column = self::SORT_COLUMNS[$filters['sort'] ?? 'date'] ?? self::SORT_COLUMNS['date'];
        $q->orderBy($column, ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc')->orderByDesc('quotations.id');
        if (isset($filters['limit'])) {
            $q->limit($filters['limit']);
        }
        if (isset($filters['offset'])) {
            $q->offset($filters['offset']);
        }

        return $q->get(['quotations.*'])->map(Row::camel(...))->all();
    }

    public static function countQuotations(array $filters = [], ?string $asAt = null): int
    {
        $q = DB::table('quotations');
        self::applyConditions($q, $filters, $asAt ?? Clock::today());

        return $q->count();
    }

    /**
     * Turn an accepted quote into a sale, at the quoted prices: a price
     * override the owner approved when issuing it. An expired quote needs a
     * reason; a changed carton size or unit sends it back for editing. One
     * transaction: if the sale fails, the quote is still open.
     *
     * Input: ['businessDate'?, 'customerId'?, 'createCustomer'?, 'tenders', 'termsDays'?,
     * 'overrideReason'?, 'isDemo'?].
     *
     * @return array{saleId: int, receiptNo: string, total: int}
     */
    public static function convertQuotation(int $quotationId, array $input, Actor $actor): array
    {
        return Tx::run(function () use ($quotationId, $input, $actor): array {
            // Lock order (Tx): the products the sale will move, the customer it bills, then the quote.
            InventoryService::lockProducts(DB::table('quotation_items')->where('quotation_items.quotation_id', $quotationId)->pluck('product_id')->all());
            $billed = $input['customerId'] ?? DB::table('quotations')->where('id', $quotationId)->value('customer_id');
            if ($billed !== null) {
                CustomerService::lockCustomer((int) $billed);
            }
            // Decided on the locked row: a second till waiting here sees the first one's CONVERTED.
            $locked = DB::table('quotations')->where('id', $quotationId)->lockForUpdate()->first() ?? throw new NotFoundError('Quotation', $quotationId);
            if ($locked->status === 'CONVERTED') {
                throw new ValidationError("Quote {$locked->quote_no} has already become a sale. Open that sale rather than converting again.");
            }
            if ($locked->status === 'CANCELLED') {
                throw new ValidationError("Quote {$locked->quote_no} was cancelled and cannot be converted.");
            }
            $quote = self::getQuotation($quotationId);

            $businessDate = $input['businessDate'] ?? Clock::today();
            $override = JsCompat::trim($input['overrideReason'] ?? '');
            if ($quote['validUntil'] < $businessDate && $override === '') {
                throw new ValidationError(
                    "Quote {$quote['quoteNo']} was only valid to {$quote['validUntil']}. "
                        .'Prices may have moved since. Say why it is still being honoured, or issue a new quote.',
                    ['validUntil' => $quote['validUntil'], 'asAt' => $businessDate],
                );
            }

            // A carton quoted at one size is not sold at another; names count too.
            $sizeOf = fn (?int $factor, string $unit, ?string $altUnit): string => $altUnit === null || $factor === null
                ? "sold only by the {$unit}"
                : Quantity::format($factor)." {$unit} a {$altUnit}";
            foreach ($quote['items'] as $item) {
                if ($item['entryUnit'] === null) {
                    continue;
                }
                $now = DB::table('products')->where('id', $item['productId'])->first(['unit', 'alt_unit', 'alt_factor_milli']);
                $factor = $now?->alt_factor_milli === null ? null : (int) $now->alt_factor_milli;
                if ($now === null || $factor === null || $factor !== $item['entryFactorMilli'] || $now->unit !== $item['unit'] || $now->alt_unit !== $item['entryUnit']) {
                    throw new ConflictError(
                        "\"{$item['productName']}\" was quoted at ".$sizeOf($item['entryFactorMilli'], $item['unit'], $item['entryUnit']).'; '
                            .'it is now '.($now !== null ? $sizeOf($factor, $now->unit, $now->alt_unit) : 'gone').'. '
                            ."Edit quote {$quote['quoteNo']} and check its price before converting it.",
                        [
                            'quoted' => ['unit' => $item['unit'], 'altUnit' => $item['entryUnit'], 'factorMilli' => $item['entryFactorMilli']],
                            'current' => $now === null ? null : ['unit' => $now->unit, 'altUnit' => $now->alt_unit, 'altFactorMilli' => $factor],
                        ],
                    );
                }
            }

            // --- the tax it was quoted with -----------------------------------------
            // The sale is taxed with today's setup. Re-priced from the quote's own lines and prices, any
            // difference is the tax: the customer would pay a total the printed quote never showed.
            $lines = array_map(fn (array $item): array => [
                'productId' => $item['productId'],
                'qty' => $item['entryQtyMilli'] ?? $item['qtyMilli'],
                'alt' => $item['entryUnit'] !== null,
                'altFactor' => $item['entryFactorMilli'],
                'unitPrice' => $item['unitPriceMinor'],
                'discount' => $item['discountMinor'],
            ], $quote['items']);
            $repriced = self::priceLines(['lines' => $lines, 'quoteDiscount' => $quote['quoteDiscountMinor']])['totals']->total;
            if ($repriced !== (int) $locked->total_minor) {
                throw new ConflictError(
                    "The tax settings have changed since quote {$quote['quoteNo']} was issued: it would now come to "
                        .Money::format($repriced).', not the '.Money::format((int) $locked->total_minor).' quoted. Edit the quote to re-price it, then convert it.',
                    ['quoted' => (int) $locked->total_minor, 'now' => $repriced],
                );
            }

            // --- who is being billed -------------------------------------------
            $customerId = $input['customerId'] ?? $quote['customerId'] ?? null;
            if (($input['createCustomer'] ?? false) === true && $customerId === null) {
                $at = Clock::stamp(Clock::now());
                $customerId = (int) DB::table('customers')->insertGetId([
                    'name' => $quote['customerName'],
                    'phone' => $quote['customerPhone'],
                    'created_by' => $actor->id,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }

            $sale = SaleService::createSale([
                'businessDate' => $businessDate,
                'customerId' => $customerId,
                // As quoted: "2 Carton" stays 2 Carton, at the size checked above.
                'items' => $lines,
                // The discount as typed, not the net figure stored beside it.
                ...($quote['quoteDiscountMinor'] !== 0 ? ['invoiceDiscount' => $quote['quoteDiscountMinor']] : []),
                'tenders' => $input['tenders'],
                ...(isset($input['termsDays']) ? ['termsDays' => $input['termsDays']] : []),
                'note' => "From quote {$quote['quoteNo']}",
                ...(($input['isDemo'] ?? false) === true ? ['isDemo' => true] : []),
                // The prices came from a quote the owner issued.
                'allowPriceOverride' => true,
            ], $actor);

            $now = Clock::now();
            $at = Clock::stamp($now);
            DB::table('quotations')->where('id', $quotationId)->update([
                'status' => 'CONVERTED',
                'converted_sale_id' => $sale['saleId'],
                'converted_at' => $at,
                ...($customerId !== null ? ['customer_id' => $customerId] : []),
                ...($override !== '' ? ['override_reason' => $override] : []),
                'updated_at' => $at,
            ]);
            AuditService::write(
                AuditAction::Update,
                'quotations',
                "Quote {$quote['quoteNo']} converted to sale {$sale['receiptNo']}".($override !== '' ? " (expired {$quote['validUntil']}, honoured: {$override})" : ''),
                $quotationId,
                $actor,
                null,
                $now,
            );

            return ['saleId' => $sale['saleId'], 'receiptNo' => $sale['receiptNo'], 'total' => $sale['total']];
        });
    }

    /** Whether a quote's promise has run out, as at a given day. */
    public static function isExpired(array $quote, string $asAt): bool
    {
        return $quote['status'] === 'OPEN' && $quote['validUntil'] < $asAt;
    }
}
