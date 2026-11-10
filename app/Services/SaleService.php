<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\RoleGuard;
use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Journal;
use App\Domain\Accounting\PeriodLock;
use App\Domain\BusinessDate;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\ForbiddenError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Domain\Inventory\Costing;
use App\Domain\Inventory\StockState;
use App\Domain\JsCompat;
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
use App\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Completing a sale: the most important transaction in the application. A
 * port of the old sale.service.ts.
 *
 * ONE transaction validates items, stock and credit, writes the sale and its
 * lines (price AND cost snapshotted), removes stock at its exact cost,
 * records the tender, posts ONE balanced journal entry and writes the audit.
 * There is no state in which stock left the shelf without the books saying so.
 *
 * createSale input: ['businessDate', 'customerId'?, 'termsDays'?, 'items' => list of
 * ['productId', 'qty', 'unitPrice'?, 'discount'?, 'alt'?, 'altFactor'?], 'invoiceDiscount'?,
 * 'tenders' => list of ['paymentAccountId', 'amount', 'reference'?], 'note'?, 'occurredAt'? => Carbon,
 * 'isDemo'?, 'clientRef'?, 'allowPriceOverride'?, 'allowExpiredStock'?, 'expiryAsAt'?,
 * 'arrivedBy'?, 'overrideReason'?].
 */
final class SaleService
{
    public const SALE_PAYMENT_KINDS = ['CASH', 'MOBILE_MONEY', 'BANK', 'OTHER'];

    public const SALE_SORTS = ['date', 'amount', 'profit', 'customer', 'receipt'];

    /** How often a duplicate submission may lose a deadlock before it gives up (each round replays if the first one landed). */
    private const DUPLICATE_ROUNDS = 8;

    /**
     * What a sale's returns put back on the customer's account: each return's
     * value less any cash refunded with it. It belongs to the sale the goods
     * were bought on. {sale} is the SQL for that sale's id.
     */
    private const CREDITED_SQL = '(SELECT COALESCE(SUM(-`r`.`total_minor` + (
            SELECT COALESCE(SUM(`rp`.`amount_minor`), 0) FROM `sale_payments` `rp` WHERE `rp`.`sale_id` = `r`.`id`
        )), 0) FROM `sales` `r` WHERE `r`.`returns_sale_id` = {sale} AND `r`.`status` = \'POSTED\')';

    /**
     * What a sale still owes, as SQL on the outer `sales` row, so "unpaid"
     * narrows the rows before the limit. A voided sale and its mirror owe
     * nothing; a return owes nothing while its sale stands (its credit is on
     * the sale).
     */
    private static function outstandingSql(): string
    {
        return "(CASE WHEN `sales`.`status` = 'VOIDED' OR `sales`.`voids_sale_id` IS NOT NULL THEN 0
            WHEN EXISTS (SELECT 1 FROM `sales` `original`
                         WHERE `original`.`id` = `sales`.`returns_sale_id` AND `original`.`status` <> 'VOIDED') THEN 0
            ELSE `sales`.`total_minor`
              - (SELECT COALESCE(SUM(`sp`.`amount_minor`), 0) FROM `sale_payments` `sp` WHERE `sp`.`sale_id` = `sales`.`id`)
              - (SELECT COALESCE(SUM(`a`.`amount_minor`), 0)
                 FROM `customer_payment_allocations` `a`
                 JOIN `customer_payments` `p` ON `p`.`id` = `a`.`payment_id` AND `p`.`status` = 'POSTED'
                 WHERE `a`.`sale_id` = `sales`.`id`)
              - ".self::creditedSql('`sales`.`id`').'
        END)';
    }

    private static function creditedSql(string $saleId): string
    {
        return str_replace('{sale}', $saleId, self::CREDITED_SQL);
    }

    /**
     * @return array{saleId: int, receiptNo: string, total: int, cogs: int, change: int, outstanding: int, journalEntryId: int}
     */
    public static function createSale(array $input, Actor $actor): array
    {
        if (($input['items'] ?? []) === []) {
            throw new ValidationError('Add at least one item to the sale.');
        }

        $clientRef = $input['clientRef'] ?? null;
        if ($clientRef === null || DB::transactionLevel() > 0) {
            return Tx::run(fn (): array => self::writeSale($input, $actor));
        }

        // The same cart submitted twice at once. The locking lookup inside makes
        // the second wait for the first, or lose a deadlock to it; either way the
        // next round finds the stored sale and replays it. Never a second sale,
        // and never a "someone else saved it" error for what is one sale.
        for ($round = 1; ; $round++) {
            try {
                return Tx::run(fn (): array => self::writeSale($input, $actor));
            } catch (QueryException $e) {
                // Deadlocks only: Tx::run already retried a 1205 lock-wait timeout, and another wait would just hold the till.
                if (($e->errorInfo[1] ?? null) !== 1213 || $round >= self::DUPLICATE_ROUNDS) {
                    throw $e;
                }
            } catch (ConflictError $e) {
                if (! str_contains((string) ($e->details['duplicate'] ?? ''), 'uq_sales_client_ref') || $round >= self::DUPLICATE_ROUNDS) {
                    throw $e;
                }
            }
            usleep(random_int(2_000, 20_000));
        }
    }

    private static function writeSale(array $input, Actor $actor): array
    {
        $occurredAt = $input['occurredAt'] ?? Clock::now();
        $clientRef = $input['clientRef'] ?? null;

        // Has this cart already been rung up? Looked up with a lock before
        // anything else, so a concurrent duplicate waits here for the first.
        if ($clientRef !== null) {
            $existing = DB::table('sales')->where('client_ref', $clientRef)->lockForUpdate()->first();
            if ($existing !== null) {
                return self::replayOf($existing, $input);
            }
        }

        InventoryService::lockProducts(array_column($input['items'], 'productId'));

        $settings = DB::table('business_settings')->where('id', 1)->first() ?? throw new NotFoundError('Business settings', 1);

        ['resolved' => $resolved, 'taxProfile' => $taxProfile, 'totals' => $totals, 'tender' => $tender] = self::priceSale($input, $settings);

        $customerId = self::checkCredit($input, $tender);

        // --- write the sale ---------------------------------------------
        // Lock order (Tx 3a): the tender accounts, shared, so archiving one waits for this sale.
        $tenderAccounts = PaymentAccountService::lockTenderAccounts($input['tenders']);
        JournalService::readLockDate();
        $receiptNo = SequenceService::next(DocType::Receipt);

        // Unpaid becomes an invoice with its own number and due date. Settled at
        // the counter gets neither, or the invoice sequence would show gaps.
        $isCredit = $tender['outstanding'] !== 0 && $customerId !== null;
        $termsDays = $isCredit ? ($input['termsDays'] ?? (int) ($settings->default_terms_days ?? 30)) : null;
        $dueDate = $termsDays === null ? null : BusinessDate::dueDateFor($input['businessDate'], $termsDays);
        $invoiceNo = $isCredit ? SequenceService::next(DocType::Invoice) : null;

        $at = Clock::stamp($occurredAt);
        $isDemo = $input['isDemo'] ?? false;
        $saleId = (int) DB::table('sales')->insertGetId([
            'receipt_no' => $receiptNo,
            'invoice_no' => $invoiceNo,
            'terms_days' => $termsDays,
            'due_date' => $dueDate,
            'business_date' => $input['businessDate'],
            'occurred_at' => $at,
            'customer_id' => $customerId,
            // Net of tax however the shelves are priced, so subtotal − discount + tax = total.
            'subtotal_minor' => $totals->subtotalExTax,
            'discount_minor' => $totals->invoiceDiscountExTax,
            'tax_minor' => $totals->tax,
            'total_minor' => $totals->total,
            'tax_inclusive' => (bool) $settings->tax_inclusive,
            'cogs_minor' => 0,
            'status' => 'POSTED',
            'client_ref' => $clientRef,
            'note' => $input['note'] ?? null,
            'created_by' => $actor->id,
            'is_demo' => $isDemo,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        ['cogs' => $cogs, 'posted' => $posted, 'expiredSold' => $expiredSold] = self::writeSaleBody($saleId, $receiptNo, $input, $resolved, $taxProfile, $totals, $tender, $settings, $tenderAccounts, $occurredAt, $actor, $isDemo);

        // A sale put on another day than the one it was rung up says so.
        AuditService::write(
            AuditAction::Create,
            'sale',
            "{$receiptNo}: ".count($input['items']).' item(s), total '.Money::format($totals->total, $settings->currency_code)
                .($input['businessDate'] !== Clock::dateOf($occurredAt) ? ', dated '.InventoryService::formatDate($input['businessDate']) : ''),
            $saleId,
            $actor,
            [
                'businessDate' => $input['businessDate'],
                'totalMinor' => $totals->total,
                'cogsMinor' => $cogs,
                'outstandingMinor' => $tender['outstanding'],
                'customerId' => $customerId,
                'entryNo' => $posted['entryNo'],
            ],
            $occurredAt,
        );

        self::auditExpiredSold($saleId, $receiptNo, $expiredSold, $input, $actor, $occurredAt);

        return [
            'saleId' => $saleId,
            'receiptNo' => $receiptNo,
            'total' => $totals->total,
            'cogs' => $cogs,
            'change' => $tender['change'],
            'outstanding' => $tender['outstanding'],
            'journalEntryId' => $posted['entryId'],
        ];
    }

    /**
     * The products, prices, totals and tender of a sale, read from the
     * (already locked) products. Shared by create and edit.
     *
     * @return array{resolved: list<array>, taxProfile: array, totals: object, tender: array}
     */
    private static function priceSale(array $input, object $settings): array
    {
        // --- resolve products and prices ---------------------------------
        $resolved = [];
        foreach ($input['items'] as $item) {
            $product = DB::table('products')->where('id', $item['productId'])->first() ?? throw new NotFoundError('Stock item', $item['productId']);
            if (! $product->is_active) {
                throw new ValidationError("\"{$product->name}\" is archived and cannot be sold.");
            }
            $lineUnit = UnitsService::resolveLineUnit($product, $item['qty'], $item['alt'] ?? null, $item['altFactor'] ?? null);
            $resolved[] = ['request' => $item, 'product' => $product, 'lineUnit' => $lineUnit, 'unitPrice' => $item['unitPrice'] ?? $lineUnit['listSellingPrice']];
        }

        // --- may this sale depart from the shop's prices? -----------------
        // Against the price read a moment ago, never against the caller's.
        if (($input['allowPriceOverride'] ?? false) !== true) {
            foreach ($resolved as $index => $entry) {
                $listPrice = $entry['lineUnit']['listSellingPrice'];
                $name = $entry['product']->name;
                if ($entry['unitPrice'] !== $listPrice) {
                    $per = $entry['lineUnit']['entryUnit'] !== null ? " a {$entry['lineUnit']['entryUnit']}" : '';
                    throw new ForbiddenError(
                        "change the price of \"{$name}\"",
                        "Only a supervisor can change a price. \"{$name}\" sells for ".Money::format($listPrice, $settings->currency_code)
                            ."{$per}. Sell it at that, or ask someone who can approve the change.",
                    );
                }
                if (($entry['request']['discount'] ?? 0) !== 0) {
                    throw new ForbiddenError('discount line '.($index + 1), 'Only a supervisor can give a discount. Remove it, or ask someone who can approve it.');
                }
            }
            if (($input['invoiceDiscount'] ?? 0) !== 0) {
                throw new ForbiddenError('discount the whole sale', 'Only a supervisor can give a discount. Remove it, or ask someone who can approve it.');
            }
        }

        // Read once inside the transaction, so a rate changed mid-sale cannot apply to half of it.
        $taxProfile = TaxService::profile();

        $totals = SaleCalculator::calculate(
            array_map(fn (array $entry): array => [
                'productId' => (int) $entry['product']->id,
                'qty' => $entry['request']['qty'],
                'unitPrice' => $entry['unitPrice'],
                ...(isset($entry['request']['discount']) ? ['discount' => $entry['request']['discount']] : []),
            ], $resolved),
            $input['invoiceDiscount'] ?? 0,
            $taxProfile['components'],
            $taxProfile['inclusive'],
        );

        $tender = SaleCalculator::tender($totals->total, $input['tenders']);

        return compact('resolved', 'taxProfile', 'totals', 'tender');
    }

    /** Who the sale is to, held to the credit rules: an unpaid balance needs a customer, inside their limit. */
    private static function checkCredit(array $input, array $tender): ?int
    {
        // --- credit rules -----------------------------------------------
        $customerId = $input['customerId'] ?? null;
        if ($tender['outstanding'] !== 0 && $customerId === null) {
            throw new ValidationError('This sale is not fully paid. Choose a customer so the balance can be recorded against them.');
        }
        if ($customerId !== null) {
            // Locked (Tx order: after products), so two tills cannot both fit under one limit.
            $customer = CustomerService::lockCustomer($customerId) ?? throw new NotFoundError('Customer', $customerId);
            if (! $customer->is_active) {
                throw new ValidationError("\"{$customer->name}\" is archived and cannot be given credit.");
            }
            if ($tender['outstanding'] !== 0) {
                $currentBalance = CustomerService::getCustomerBalance($customerId);
                $limit = $customer->credit_limit_minor === null ? null : (int) $customer->credit_limit_minor;
                if (SaleCalculator::exceedsCreditLimit($limit, $currentBalance, $tender['outstanding'])) {
                    throw new ValidationError("This would take {$customer->name} over their credit limit.", [
                        'currentBalance' => $currentBalance, 'limit' => $limit, 'additional' => $tender['outstanding'],
                    ]);
                }
            }
        }

        return $customerId;
    }

    /**
     * Its own row, findable by action: who let expired stock go out, and which
     * crate. A new sale and an edited one alike.
     *
     * @param  list<string>  $expiredSold
     */
    private static function auditExpiredSold(int $saleId, string $receiptNo, array $expiredSold, array $input, Actor $actor, Carbon $at): void
    {
        if ($expiredSold === []) {
            return;
        }
        AuditService::write(
            AuditAction::Create,
            'expiry_override',
            "{$receiptNo}: sold expired stock from ".implode(', ', $expiredSold),
            $saleId,
            $actor,
            [
                'batchRefs' => $expiredSold,
                'businessDate' => $input['businessDate'],
                ...(isset($input['overrideReason']) ? ['reason' => $input['overrideReason']] : []),
            ],
            $at,
        );
    }

    /**
     * Everything a sale writes under its header: the taxes charged, the lines
     * with their stock and cost, the tender, and ONE balanced entry. Shared by
     * create and edit, so an edited sale posts exactly what a new one would.
     *
     * $keepCost (edit only, D34): per product, the quantity and cost the sale
     * took before it was edited. Up to that quantity leaves at that cost, so
     * an edit keeps what the goods cost on the sale's own day; anything more
     * is costed at today's average, as a new sale would be.
     *
     * @param  array<int, array{qty: int, cost: int}>  $keepCost
     * @return array{cogs: int, posted: array{entryId: int, entryNo: string}, expiredSold: list<string>, residual: int}
     */
    private static function writeSaleBody(int $saleId, string $receiptNo, array $input, array $resolved, array $taxProfile, object $totals, array $tender, object $settings, array $tenderAccounts, Carbon $occurredAt, Actor $actor, bool $isDemo, array $keepCost = []): array
    {
        $residual = 0;
        $customerId = $input['customerId'] ?? null;
        $at = Clock::stamp($occurredAt);
        TaxService::writeSaleTaxes($saleId, $totals->taxLines, $taxProfile['componentIdByCode'], $occurredAt);

        // --- lines, stock and COGS --------------------------------------
        $allowNegative = (bool) $settings->allow_negative_stock;
        $cogsParts = [];
        $expiredSold = [];
        foreach ($resolved as $index => $entry) {
            $line = $totals->lines[$index] ?? throw new ValidationError('Sale line mismatch.');
            $unitCost = 0;
            $totalCost = 0;
            if ($entry['product']->track_inventory) {
                $productId = (int) $entry['product']->id;
                $qty = $entry['lineUnit']['baseQty'];
                $keptAt = null;
                if (($keepCost[$productId]['qty'] ?? 0) > 0) {
                    $budget = $keepCost[$productId];
                    $take = min($qty, $budget['qty']);
                    $kept = Money::mulDiv($budget['cost'], $take, $budget['qty']);
                    $keepCost[$productId] = ['qty' => Quantity::subtract($budget['qty'], $take), 'cost' => Money::subtract($budget['cost'], $kept)];
                    $keptAt = $kept;
                    if ($qty > $take) {
                        // The extra units, at the average of what else is on the shelf.
                        $shelf = DB::table('products')->where('id', $productId)->first(['qty_on_hand_milli', 'stock_value_minor', 'cost_price_minor']);
                        $rest = new StockState(Quantity::subtract((int) $shelf->qty_on_hand_milli, $take), Money::subtract((int) $shelf->stock_value_minor, $kept));
                        $keptAt = Money::add($kept, Costing::stockOut($rest, Quantity::subtract($qty, $take), (int) $shelf->cost_price_minor, true)->totalCost);
                    }
                }
                // First-expiry-first-out; expired stock only with the right, and only when nothing else is left.
                $movement = InventoryService::recordStockMovement([
                    'productId' => $productId,
                    'direction' => 'OUT',
                    'qty' => $qty,
                    ...($keptAt === null ? [] : ['totalCost' => Money::atLeastZero($keptAt)]),
                    'movementType' => MovementType::Sale,
                    'sourceType' => 'SALE',
                    'sourceId' => $saleId,
                    'sourceRef' => $receiptNo,
                    'businessDate' => $input['businessDate'],
                    'occurredAt' => $occurredAt,
                    'userId' => $actor->id,
                    'allowNegative' => $allowNegative,
                    'isDemo' => $isDemo,
                    'batch' => [
                        'kind' => 'PICK',
                        'allowExpired' => ($input['allowExpiredStock'] ?? false) === true,
                        ...(isset($input['expiryAsAt']) ? ['expiryAsAt' => $input['expiryAsAt']] : []),
                        ...(isset($input['arrivedBy']) ? ['arrivedBy' => $input['arrivedBy']] : []),
                    ],
                ]);
                $unitCost = $movement['unitCost'];
                $totalCost = $movement['totalCost'];
                $residual = Money::add($residual, $movement['residual']);
                array_push($expiredSold, ...$movement['expiredTaken']);
            }
            $cogsParts[] = $totalCost;

            DB::table('sale_items')->insert([
                'sale_id' => $saleId,
                'line_no' => $index + 1,
                'product_id' => (int) $entry['product']->id,
                // Snapshot: a receipt reprinted later shows what was actually sold.
                'product_name' => $entry['product']->name,
                'unit' => $entry['product']->unit,
                'qty_milli' => $entry['lineUnit']['baseQty'],
                'entry_unit' => $entry['lineUnit']['entryUnit'],
                'entry_qty_milli' => $entry['lineUnit']['entryQtyMilli'],
                'entry_factor_milli' => $entry['lineUnit']['entryFactorMilli'],
                'unit_price_minor' => $entry['unitPrice'],
                'discount_minor' => $line['discount'],
                'line_total_minor' => $line['lineTotal'],
                'unit_cost_minor' => $unitCost,
                'total_cost_minor' => $totalCost,
                'created_at' => $at,
            ]);
        }
        $cogs = Money::sum($cogsParts);
        DB::table('sales')->where('id', $saleId)->update(['cogs_minor' => $cogs]);

        // --- tender: applied in order up to the total; the excess is change, never revenue
        $remaining = $tender['applied'];
        $applied = [];
        foreach ($input['tenders'] as $request) {
            if ($remaining <= 0) {
                break;
            }
            if ($request['amount'] <= 0) {
                continue;
            }
            $account = $tenderAccounts[$request['paymentAccountId']] ?? throw new NotFoundError('Payment account', $request['paymentAccountId']);
            if (! $account->is_active) {
                throw new ValidationError("Payment account \"{$account->name}\" is not active.");
            }
            $amount = min($request['amount'], $remaining);
            DB::table('sale_payments')->insert([
                'sale_id' => $saleId,
                'payment_account_id' => $request['paymentAccountId'],
                'amount_minor' => $amount,
                'reference' => $request['reference'] ?? null,
                'created_at' => $at,
            ]);
            $applied[] = ['account' => $account, 'amount' => $amount];
            $remaining = Money::subtract($remaining, $amount);
        }

        // --- the journal entry -------------------------------------------
        $lines = [];
        foreach ($applied as $entry) {
            $lines[] = Journal::debit((int) $entry['account']->gl_account_id, $entry['amount'], [
                'paymentAccountId' => (int) $entry['account']->id,
                'description' => "{$receiptNo} received ({$entry['account']->name})",
            ]);
        }
        if ($tender['outstanding'] !== 0) {
            $lines[] = Journal::debit(JournalService::accountIdByCode(ChartOfAccounts::ACCOUNTS_RECEIVABLE), $tender['outstanding'], [
                'description' => "{$receiptNo} on credit",
                ...($customerId !== null ? ['customerId' => $customerId] : []),
            ]);
        }
        // Discounts are contra-revenue, net of tax, so gross sales stay visible.
        if ($totals->totalDiscountExTax !== 0) {
            $lines[] = Journal::debit(JournalService::accountIdByCode(ChartOfAccounts::SALES_DISCOUNTS), $totals->totalDiscountExTax, ['description' => "{$receiptNo} discount"]);
        }
        $grossRevenue = Money::add(Money::subtract($totals->total, $totals->tax), $totals->totalDiscountExTax);
        $lines[] = Journal::credit(JournalService::accountIdByCode(ChartOfAccounts::SALES_REVENUE), $grossRevenue, ['description' => "{$receiptNo} sale"]);
        // Each tax to its own account: NHIL, GETFund and VAT are separate obligations.
        foreach ($totals->taxLines as $taxLine) {
            if ($taxLine->amount === 0) {
                continue;
            }
            $lines[] = Journal::credit(TaxService::accountFor(null, $taxLine->component->code), $taxLine->amount, ['description' => "{$receiptNo} {$taxLine->component->name}"]);
        }
        if ($cogs !== 0) {
            $lines[] = Journal::debit(JournalService::accountIdByCode(ChartOfAccounts::COST_OF_GOODS_SOLD), $cogs, ['description' => "{$receiptNo} cost of goods"]);
            $lines[] = Journal::credit(JournalService::accountIdByCode(ChartOfAccounts::INVENTORY), $cogs, ['description' => "{$receiptNo} stock released"]);
        }

        $posted = JournalService::post(
            $input['businessDate'],
            JournalSourceType::Sale,
            $lines,
            sourceId: $saleId,
            memo: "{$receiptNo} sale",
            isDemo: $isDemo,
            occurredAt: $occurredAt,
            actor: $actor,
        );
        DB::table('sales')->where('id', $saleId)->update(['journal_entry_id' => $posted['entryId'], 'updated_at' => $at]);

        return ['cogs' => $cogs, 'posted' => $posted, 'expiredSold' => $expiredSold, 'residual' => $residual];
    }

    /**
     * The answer to a cart already rung up, from the stored document. Change
     * is not a fact of the sale, so it comes from the tender just sent.
     */
    private static function replayOf(object $existing, array $input): array
    {
        $total = (int) $existing->total_minor;
        $tendered = (int) DB::table('sale_payments')->where('sale_id', $existing->id)->sum('amount_minor');

        return [
            'saleId' => (int) $existing->id,
            'receiptNo' => $existing->receipt_no,
            'total' => $total,
            'cogs' => (int) $existing->cogs_minor,
            'change' => SaleCalculator::tender($total, $input['tenders'])['change'],
            'outstanding' => $existing->status === 'VOIDED' ? 0 : Money::subtract($total, $tendered),
            'journalEntryId' => (int) ($existing->journal_entry_id ?? 0),
        ];
    }

    /**
     * Void a sale by writing its mirror image, dated today. Stock goes back
     * at exactly the cost it left at, into the batches it came from.
     *
     * @return array{reversalSaleId: int, receiptNo: string}
     */
    public static function voidSale(int $saleId, string $reason, Actor $actor, ?Carbon $now = null): array
    {
        $why = JsCompat::trim($reason);
        if (mb_strlen($why) < 3) {
            throw new ValidationError('Give a reason for voiding this sale.');
        }
        $now ??= Clock::now();

        return Tx::run(function () use ($saleId, $why, $actor, $now): array {
            // Lock order (Tx): products, the customer whose receivable changes, then the sale.
            InventoryService::lockProducts(DB::table('sale_items')->where('sale_items.sale_id', $saleId)->pluck('product_id')->all());
            $customerId = DB::table('sales')->where('id', $saleId)->value('customer_id');
            if ($customerId !== null) {
                CustomerService::lockCustomer((int) $customerId);
            }
            $original = DB::table('sales')->where('id', $saleId)->lockForUpdate()->first() ?? throw new NotFoundError('Sale', $saleId);
            if ($original->status === 'VOIDED') {
                throw new ConflictError('That sale has already been voided.');
            }
            if ($original->voids_sale_id !== null) {
                throw new ConflictError('A voiding entry cannot itself be voided.');
            }

            $items = DB::table('sale_items')->where('sale_id', $saleId)->orderBy('id')->lockForUpdate()->get();

            // A void mirrors the whole sale, tender included, so goods already
            // back would be reversed and refunded twice.
            if ($items->contains(fn (object $item): bool => (int) $item->returned_qty_milli > 0)) {
                throw new ConflictError('Goods from this sale have already been returned, so it cannot be voided. Return anything still left instead.');
            }
            if (self::settledByPayments($saleId) > 0) {
                throw new ConflictError('This sale has customer payments recorded against it. Void those payments first.');
            }

            PaymentAccountService::lockAccountsOfEntry($original->journal_entry_id);
            JournalService::readLockDate();
            $businessDate = Clock::dateOf($now);
            $at = Clock::stamp($now);
            $receiptNo = SequenceService::next(DocType::SaleReturn);

            $reversalId = (int) DB::table('sales')->insertGetId([
                'receipt_no' => $receiptNo,
                // A correction: not a return and not a sale.
                'kind' => 'VOID',
                'business_date' => $businessDate,
                'occurred_at' => $at,
                'customer_id' => $original->customer_id,
                'subtotal_minor' => Money::negate((int) $original->subtotal_minor),
                'discount_minor' => Money::negate((int) $original->discount_minor),
                'tax_minor' => Money::negate((int) $original->tax_minor),
                'total_minor' => Money::negate((int) $original->total_minor),
                'cogs_minor' => Money::negate((int) $original->cogs_minor),
                'tax_inclusive' => (bool) $original->tax_inclusive,
                'status' => 'POSTED',
                'voids_sale_id' => $saleId,
                'note' => "Void of {$original->receipt_no}: {$why}",
                'created_by' => $actor->id,
                'is_demo' => (bool) $original->is_demo,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            // Each tax the original charged, given back as charged, not at today's rates.
            $originalTaxes = TaxService::readSaleTaxes($saleId);
            TaxService::writeSaleTaxes(
                $reversalId,
                $originalTaxes->map(fn (object $row): TaxLine => new TaxLine(
                    new TaxComponent($row->code, $row->name, (int) $row->rate_bp, TaxBasis::from($row->basis), false),
                    Money::negate((int) $row->amount_minor),
                ))->all(),
                $originalTaxes->whereNotNull('component_id')->mapWithKeys(fn (object $row): array => [$row->code => (int) $row->component_id])->all(),
                $now,
            );

            // Which crates the sale emptied, so the void refills those and no others.
            $soldFrom = [];
            foreach ($items as $item) {
                $soldFrom[(int) $item->product_id] ??= InventoryService::readBatchSplits('SALE', $saleId, (int) $item->product_id);
            }

            $residual = 0;
            foreach ($items->values() as $index => $item) {
                $productId = (int) $item->product_id;
                if (DB::table('products')->where('id', $productId)->value('track_inventory')) {
                    $cameFrom = array_shift($soldFrom[$productId]);
                    $movement = InventoryService::recordStockMovement([
                        'productId' => $productId,
                        'direction' => 'IN',
                        'qty' => (int) $item->qty_milli,
                        // Back at the ORIGINAL cost, from the line snapshot.
                        'totalCost' => (int) $item->total_cost_minor,
                        'movementType' => MovementType::SaleReturn,
                        'sourceType' => 'SALE_VOID',
                        'sourceId' => $reversalId,
                        'sourceRef' => $receiptNo,
                        'businessDate' => $businessDate,
                        'occurredAt' => $now,
                        'userId' => $actor->id,
                        'note' => "Void of {$original->receipt_no}",
                        'isDemo' => (bool) $original->is_demo,
                        ...($cameFrom === null || $cameFrom === [] ? [] : ['batch' => ['kind' => 'SOURCE', 'allocations' => $cameFrom]]),
                    ]);
                    // D26: the goods back onto a shelf that went below zero since.
                    $residual = Money::add($residual, $movement['residual']);
                }

                DB::table('sale_items')->insert([
                    'sale_id' => $reversalId,
                    'line_no' => $index + 1,
                    'product_id' => $productId,
                    'product_name' => $item->product_name,
                    'unit' => $item->unit,
                    'qty_milli' => -(int) $item->qty_milli,
                    'entry_unit' => $item->entry_unit,
                    'entry_qty_milli' => $item->entry_qty_milli === null ? null : -(int) $item->entry_qty_milli,
                    'entry_factor_milli' => $item->entry_factor_milli,
                    'unit_price_minor' => $item->unit_price_minor,
                    'discount_minor' => $item->discount_minor,
                    'line_total_minor' => Money::negate((int) $item->line_total_minor),
                    'unit_cost_minor' => $item->unit_cost_minor,
                    'total_cost_minor' => Money::negate((int) $item->total_cost_minor),
                    'created_at' => $at,
                ]);
            }

            // The tender, mirrored, so the cash and MoMo accounts are put back too.
            foreach (DB::table('sale_payments')->where('sale_id', $saleId)->orderBy('id')->get() as $tenderRow) {
                DB::table('sale_payments')->insert([
                    'sale_id' => $reversalId,
                    'payment_account_id' => $tenderRow->payment_account_id,
                    'amount_minor' => Money::negate((int) $tenderRow->amount_minor),
                    'reference' => "Void of {$original->receipt_no}",
                    'created_at' => $at,
                ]);
            }

            if ($original->journal_entry_id !== null) {
                $reversed = JournalService::reverse(
                    (int) $original->journal_entry_id,
                    $businessDate,
                    JournalSourceType::SaleReturn,
                    $reversalId,
                    "Void of {$original->receipt_no}: {$why}",
                    occurredAt: $now,
                    actor: $actor,
                );
                DB::table('sales')->where('id', $reversalId)->update(['journal_entry_id' => $reversed['entryId'], 'updated_at' => $at]);
            }
            PurchaseService::postCostingResidual($residual, $businessDate, $receiptNo, $reversalId, "Stock costing difference on voiding {$original->receipt_no}", $now, $actor, JournalSourceType::SaleReturn, (bool) $original->is_demo);

            DB::table('sales')->where('id', $saleId)->update([
                'status' => 'VOIDED',
                'voided_at' => $at,
                'void_reason' => $why,
                'voided_by_sale_id' => $reversalId,
                'updated_at' => $at,
            ]);
            AuditService::write(AuditAction::Void, 'sale', "Voided {$original->receipt_no} with {$receiptNo}", $saleId, $actor, [
                'reason' => $why,
                'reversalSaleId' => $reversalId,
            ], $now);

            return ['reversalSaleId' => $reversalId, 'receiptNo' => $receiptNo];
        });
    }

    /**
     * Owner decision D34: correct a saved sale in place, under the same
     * receipt number. On the day it was recorded, its goods go back at the
     * cost they left at, into the crates they came from, and its entry is
     * reversed; then the corrected sale is written exactly as a new one would
     * be, priced as the owner typed it. What it said before is kept in the
     * audit log.
     *
     * Refused while anything leans on it: goods returned against it, or
     * customer payments set against it.
     *
     * Input: as createSale (no clientRef).
     *
     * @return array{saleId: int, receiptNo: string, total: int, cogs: int, change: int, outstanding: int, journalEntryId: int}
     */
    public static function editSale(int $saleId, array $input, string $why, Actor $actor, ?Carbon $now = null): array
    {
        RoleGuard::assertActorIsOwner($actor, 'edit a saved sale');
        if (($input['items'] ?? []) === []) {
            throw new ValidationError('Add at least one item to the sale.');
        }
        $why = JsCompat::trim($why);
        if (mb_strlen($why) < 3) {
            throw new ValidationError('Say why this sale is being edited.');
        }
        $now ??= Clock::now();
        // The owner may price and discount, and sell the last of an expired crate, as on the till.
        $input = [...$input, 'allowPriceOverride' => true, 'allowExpiredStock' => true];
        unset($input['clientRef']);
        // As on the till: a backdated sale takes only stock that had arrived by its own day, judged for expiry as of now.
        $today = Clock::dateOf($now);
        if (($input['businessDate'] ?? $today) !== $today) {
            $input += ['expiryAsAt' => $today, 'arrivedBy' => $input['businessDate']];
        }

        return Tx::run(function () use ($saleId, $input, $why, $actor, $now): array {
            // Lock order (Tx): every product either version touches, both customers, then the sale.
            InventoryService::lockProducts([
                ...DB::table('sale_items')->where('sale_items.sale_id', $saleId)->pluck('product_id')->map(fn ($id): int => (int) $id)->all(),
                ...array_column($input['items'], 'productId'),
            ]);
            $customers = array_unique(array_filter([(int) ($input['customerId'] ?? 0), (int) DB::table('sales')->where('id', $saleId)->value('customer_id')]));
            sort($customers);
            foreach ($customers as $customerId) {
                CustomerService::lockCustomer($customerId);
            }
            $original = DB::table('sales')->where('id', $saleId)->lockForUpdate()->first() ?? throw new NotFoundError('Sale', $saleId);
            $no = $original->receipt_no;
            if ($original->status === 'VOIDED') {
                throw new ConflictError("{$no} has been voided, so it cannot be edited.");
            }
            if ($original->kind !== 'SALE' || $original->voids_sale_id !== null) {
                throw new ConflictError("{$no} is not a sale, so it cannot be edited.");
            }
            TaxService::assertUnchangedSince('sale_taxes', 'sale_id', $saleId, (bool) $original->tax_inclusive, $no);

            $items = DB::table('sale_items')->where('sale_id', $saleId)->orderBy('id')->lockForUpdate()->get();
            $return = DB::table('sales')->where('returns_sale_id', $saleId)->where('status', 'POSTED')->value('receipt_no');
            if ($return !== null || $items->contains(fn (object $item): bool => (int) $item->returned_qty_milli > 0)) {
                throw new ConflictError("Goods from {$no} have been returned".($return === null ? '' : " ({$return})").', so it cannot be edited. Void the return first, or void the sale and ring it up again.');
            }
            if (self::settledByPayments($saleId) > 0) {
                throw new ConflictError("{$no} has customer payments set against it, so it cannot be edited. Void those payments first.");
            }

            PaymentAccountService::lockAccountsOfEntry($original->journal_entry_id);
            $lockedBefore = JournalService::readLockDate();
            PeriodLock::assertOpen((string) $original->business_date, $lockedBefore);
            PeriodLock::assertOpen($input['businessDate'], $lockedBefore);

            $settings = DB::table('business_settings')->where('id', 1)->first() ?? throw new NotFoundError('Business settings', 1);
            $before = self::snapshot($saleId);
            $originalDate = (string) $original->business_date;
            $isDemo = (bool) $original->is_demo;

            // 1. On its own day, the goods go back at the cost they left at, into the crates they came from.
            $soldFrom = [];
            foreach ($items as $item) {
                $soldFrom[(int) $item->product_id] ??= InventoryService::readBatchSplits('SALE', $saleId, (int) $item->product_id);
            }
            $residual = 0;
            $keepCost = [];
            $sold = DB::table('stock_ledger')->where('stock_ledger.source_type', 'SALE')->where('stock_ledger.source_id', $saleId)
                ->where('stock_ledger.id', '>', InventoryService::lastEditMark('SALE', $saleId))->pluck('product_id')->map(fn ($id): int => (int) $id)->countBy()->all();
            foreach ($items as $item) {
                $productId = (int) $item->product_id;
                // A line that never left the shelf has nothing to put back.
                if (($sold[$productId] ?? 0) === 0) {
                    continue;
                }
                $sold[$productId]--;
                $keepCost[$productId] = [
                    'qty' => Quantity::add($keepCost[$productId]['qty'] ?? 0, (int) $item->qty_milli),
                    'cost' => Money::add($keepCost[$productId]['cost'] ?? 0, (int) $item->total_cost_minor),
                ];
                $cameFrom = array_shift($soldFrom[$productId]);
                $movement = InventoryService::recordStockMovement([
                    'productId' => $productId,
                    'direction' => 'IN',
                    'qty' => (int) $item->qty_milli,
                    'totalCost' => (int) $item->total_cost_minor,
                    'movementType' => MovementType::SaleReturn,
                    'sourceType' => 'SALE_VOID',
                    'sourceId' => $saleId,
                    'sourceRef' => $no,
                    'businessDate' => $originalDate,
                    'occurredAt' => $now,
                    'userId' => $actor->id,
                    'note' => "Edit of {$no}",
                    'isDemo' => $isDemo,
                    ...($cameFrom === null || $cameFrom === [] ? [] : ['batch' => ['kind' => 'SOURCE', 'allocations' => $cameFrom]]),
                ]);
                $residual = Money::add($residual, $movement['residual']);
            }
            if ($original->journal_entry_id !== null) {
                JournalService::reverse((int) $original->journal_entry_id, $originalDate, JournalSourceType::Sale, $saleId, "Edit of {$no}: {$why}", occurredAt: $now, actor: $actor);
            }
            PurchaseService::postCostingResidual($residual, $originalDate, $no, $saleId, "Stock costing difference on editing {$no}", $now, $actor, JournalSourceType::Sale, $isDemo);
            // The old lines, taxes and tender live on, whole, in the audit row below.
            $replaced = AuditService::rowsBeingReplaced('sale_id', $saleId, 'sale_items', 'sale_taxes', 'sale_payments');
            DB::table('sale_items')->where('sale_id', $saleId)->delete();
            DB::table('sale_taxes')->where('sale_id', $saleId)->delete();
            DB::table('sale_payments')->where('sale_id', $saleId)->delete();

            // 2. The sale as it should have been, checked against the customer's balance without the old one.
            ['resolved' => $resolved, 'taxProfile' => $taxProfile, 'totals' => $totals, 'tender' => $tender] = self::priceSale($input, $settings);
            $customerId = self::checkCredit($input, $tender);
            $tenderAccounts = PaymentAccountService::lockTenderAccounts($input['tenders']);
            // The goods already on it leave at what they cost on its own day, not today's average.
            ['cogs' => $cogs, 'posted' => $posted, 'residual' => $emptied, 'expiredSold' => $expiredSold] = self::writeSaleBody($saleId, $no, $input, $resolved, $taxProfile, $totals, $tender, $settings, $tenderAccounts, $now, $actor, $isDemo, $keepCost);
            PurchaseService::postCostingResidual($emptied, $input['businessDate'], $no, $saleId, "Stock costing difference on {$no}", $now, $actor, JournalSourceType::Sale, $isDemo);

            // An unpaid sale is an invoice: it keeps the number it has, or takes the next one.
            $isCredit = $tender['outstanding'] !== 0 && $customerId !== null;
            $termsDays = $isCredit ? ($input['termsDays'] ?? ($original->terms_days === null ? (int) ($settings->default_terms_days ?? 30) : (int) $original->terms_days)) : null;
            $at = Clock::stamp($now);
            DB::table('sales')->where('id', $saleId)->update([
                'invoice_no' => $isCredit && $original->invoice_no === null ? SequenceService::next(DocType::Invoice) : $original->invoice_no,
                'terms_days' => $termsDays,
                'due_date' => $termsDays === null ? null : BusinessDate::dueDateFor($input['businessDate'], $termsDays),
                'business_date' => $input['businessDate'],
                'customer_id' => $customerId,
                'subtotal_minor' => $totals->subtotalExTax,
                'discount_minor' => $totals->invoiceDiscountExTax,
                'tax_minor' => $totals->tax,
                'total_minor' => $totals->total,
                'tax_inclusive' => (bool) $settings->tax_inclusive,
                'note' => $input['note'] ?? null,
                'edited_at' => $at,
                'updated_at' => $at,
            ]);
            AuditService::write(AuditAction::Update, 'sale', "Edited {$no}: {$why}", $saleId, $actor, [
                'edit' => true,
                'reason' => $why,
                'before' => $before,
                'after' => self::snapshot($saleId),
                'replaced' => $replaced,
            ], $now);
            self::auditExpiredSold($saleId, $no, $expiredSold, $input, $actor, $now);

            return [
                'saleId' => $saleId,
                'receiptNo' => $no,
                'total' => $totals->total,
                'cogs' => $cogs,
                'change' => $tender['change'],
                'outstanding' => $tender['outstanding'],
                'journalEntryId' => $posted['entryId'],
            ];
        });
    }

    /** What a sale says, in the edit history's shape (see StockAdjustmentService::snapshot). */
    private static function snapshot(int $saleId): array
    {
        $sale = DB::table('sales')->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sales.id', $saleId)->first(['sales.*', 'customers.name as customer_name']);
        $paid = DB::table('sale_payments')->join('payment_accounts', 'payment_accounts.id', '=', 'sale_payments.payment_account_id')
            ->where('sale_payments.sale_id', $saleId)->orderBy('sale_payments.id')
            ->get(['payment_accounts.name', 'sale_payments.amount_minor'])
            ->map(fn (object $p): string => "{$p->name} ".Money::format((int) $p->amount_minor))->implode(', ');

        return [
            'date' => (string) $sale->business_date,
            'fields' => [
                'Customer' => $sale->customer_name ?? 'Walk-in customer',
                'Paid' => $paid === '' ? 'Nothing, on credit' : $paid,
                'Discount' => (int) $sale->discount_minor === 0 ? null : Money::format((int) $sale->discount_minor),
                'Note' => $sale->note,
            ],
            'lines' => DB::table('sale_items')->where('sale_id', $saleId)->orderBy('line_no')->get()
                ->map(fn (object $i): array => [
                    'label' => "{$i->product_name}: ".Quantity::formatWithUnit((int) $i->qty_milli, $i->unit).' at '.Money::format((int) $i->unit_price_minor),
                    'amount' => (int) $i->line_total_minor,
                ])->all(),
            'total' => (int) $sale->total_minor,
        ];
    }

    /** Customer payments still standing against one sale. */
    private static function settledByPayments(int $saleId): int
    {
        return (int) DB::table('customer_payment_allocations')
            ->join('customer_payments', 'customer_payments.id', '=', 'customer_payment_allocations.payment_id')
            ->where('customer_payment_allocations.sale_id', $saleId)
            ->where('customer_payments.status', 'POSTED')
            ->sum('customer_payment_allocations.amount_minor');
    }

    // --- reads --------------------------------------------------------------

    /**
     * What is still owed on one sale: total less tender, later payments and
     * goods credited back. Negative when a paid sale's goods came back on
     * account. A void, its mirror, and a return whose sale stands owe nothing.
     */
    public static function getSaleOutstanding(int $saleId): int
    {
        $sale = DB::table('sales')->where('id', $saleId)->first() ?? throw new NotFoundError('Sale', $saleId);
        if ($sale->status === 'VOIDED' || $sale->voids_sale_id !== null) {
            return 0;
        }
        if ($sale->returns_sale_id !== null && DB::table('sales')->where('id', $sale->returns_sale_id)->value('status') !== 'VOIDED') {
            return 0;
        }

        $tendered = (int) DB::table('sale_payments')->where('sale_id', $saleId)->sum('amount_minor');

        return Money::subtract((int) $sale->total_minor, $tendered + self::settledByPayments($saleId) + self::creditedOnAccount($saleId));
    }

    /** Goods from this sale that came back on account. */
    public static function creditedOnAccount(int $saleId): int
    {
        return (int) DB::selectOne('SELECT '.self::creditedSql('?').' AS c', [$saleId])->c;
    }

    /**
     * Outstanding for every posted sale in one pass, with the same definition
     * as getSaleOutstanding. Mirrors are never in here (found by
     * voids_sale_id, not kind). With asAt, a sale still good that day counts,
     * and only payments and returns dated by then.
     *
     * @param  array{customerId?: int, asAt?: string}  $options
     * @return array<int, int> outstanding by sale id
     */
    public static function getOutstandingBySale(array $options = []): array
    {
        $customerId = $options['customerId'] ?? null;
        $asAt = $options['asAt'] ?? null;

        // A walk-in sale can never owe (createSale and the return both refuse
        // credit without a customer), so the whole shop means customer sales only.
        $ofScope = fn (Builder $q): Builder => $customerId !== null ? $q->where('sales.customer_id', $customerId) : $q->whereNotNull('sales.customer_id');
        $documents = $ofScope(DB::table('sales')->whereNull('sales.voids_sale_id'));
        if ($asAt === null) {
            $documents->where('sales.status', 'POSTED');
        } else {
            $documents->where('sales.business_date', '<=', $asAt)
                ->where(fn (Builder $q) => $q->where('sales.status', 'POSTED')->orWhereRaw(
                    'NOT EXISTS (SELECT 1 FROM `sales` `mirror` WHERE `mirror`.`voids_sale_id` = `sales`.`id` AND `mirror`.`business_date` <= ?)',
                    [$asAt],
                ));
        }
        $rows = $documents->orderBy('sales.id')->get(['sales.id', 'sales.total_minor', 'sales.returns_sale_id']);

        // The same sales (a return carries its sale's customer), not every sale ever.
        $ofCustomer = fn (string $column): \Closure => fn (Builder $q) => $q->whereIn($column, $ofScope(DB::table('sales')->select('sales.id')));

        // Tender comes with the sale itself, so it needs no cut-off of its own.
        $tendered = DB::table('sale_payments')->where($ofCustomer('sale_payments.sale_id'))->groupBy('sale_payments.sale_id')
            ->selectRaw('`sale_payments`.`sale_id` AS id, COALESCE(SUM(`sale_payments`.`amount_minor`), 0) AS t')->pluck('t', 'id')->all();
        $settledQuery = DB::table('customer_payment_allocations')
            ->join('customer_payments', 'customer_payments.id', '=', 'customer_payment_allocations.payment_id')
            ->where($ofCustomer('customer_payment_allocations.sale_id'));
        if ($asAt === null) {
            $settledQuery->where('customer_payments.status', 'POSTED');
        } else {
            // D32: as it stood that day. A payment voided later still counts; the
            // void's date is its reversal entry's (voided_at for one with no entry).
            $settledQuery->where('customer_payments.business_date', '<=', $asAt)->whereRaw(
                "(`customer_payments`.`status` = 'POSTED' OR COALESCE((SELECT `v`.`entry_date` FROM `journal_entries` `v`
                    WHERE `v`.`reverses_entry_id` = `customer_payments`.`journal_entry_id` ORDER BY `v`.`id` LIMIT 1), DATE(`customer_payments`.`voided_at`)) > ?)",
                [$asAt],
            );
        }
        $settled = $settledQuery->groupBy('customer_payment_allocations.sale_id')
            ->selectRaw('`customer_payment_allocations`.`sale_id` AS id, COALESCE(SUM(`customer_payment_allocations`.`amount_minor`), 0) AS t')->pluck('t', 'id')->all();

        $counted = [];
        foreach ($rows as $row) {
            $counted[(int) $row->id] = true;
        }

        $credited = [];
        foreach ($rows as $row) {
            $of = $row->returns_sale_id === null ? null : (int) $row->returns_sale_id;
            if ($of === null || ! isset($counted[$of])) {
                continue;
            }
            $credited[$of] = ($credited[$of] ?? 0) - (int) $row->total_minor + (int) ($tendered[$row->id] ?? 0);
        }

        $outstanding = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            if ($row->returns_sale_id !== null && isset($counted[(int) $row->returns_sale_id])) {
                $outstanding[$id] = 0;

                continue;
            }
            $outstanding[$id] = Money::of((int) $row->total_minor - (int) ($tendered[$id] ?? 0) - (int) ($settled[$id] ?? 0) - ($credited[$id] ?? 0));
        }

        return $outstanding;
    }

    /**
     * Every sale filter as one clause, shared by the list, the count and the
     * totals, so the figures above a table describe that table.
     *
     * Query keys: from, to, customerId, productId, categoryId, paymentAccountId, paymentKind,
     * staffId, status, kind, paymentState ('paid'|'unpaid'), unpaidOnly, search, minAmount,
     * maxAmount, sort, direction, limit, offset.
     */
    private static function applyConditions(Builder $q, array $query): void
    {
        if (isset($query['from'])) {
            $q->where('sales.business_date', '>=', $query['from']);
        }
        if (isset($query['to'])) {
            $q->where('sales.business_date', '<=', $query['to']);
        }
        if (isset($query['customerId'])) {
            $q->where('sales.customer_id', $query['customerId']);
        }
        if (isset($query['staffId'])) {
            $q->where('sales.created_by', $query['staffId']);
        }
        if (isset($query['status'])) {
            $q->where('sales.status', $query['status']);
        }
        if (isset($query['kind'])) {
            $q->where('sales.kind', $query['kind']);
        }
        if (isset($query['productId'])) {
            $q->whereRaw('EXISTS (SELECT 1 FROM `sale_items` WHERE `sale_items`.`sale_id` = `sales`.`id` AND `sale_items`.`product_id` = ?)', [$query['productId']]);
        }
        if (isset($query['categoryId'])) {
            $q->whereRaw('EXISTS (SELECT 1 FROM `sale_items` JOIN `products` ON `products`.`id` = `sale_items`.`product_id`
                WHERE `sale_items`.`sale_id` = `sales`.`id` AND `products`.`category_id` = ?)', [$query['categoryId']]);
        }
        if (isset($query['paymentAccountId'])) {
            $q->whereRaw('EXISTS (SELECT 1 FROM `sale_payments` `sp` WHERE `sp`.`sale_id` = `sales`.`id` AND `sp`.`payment_account_id` = ?)', [$query['paymentAccountId']]);
        }
        if (isset($query['paymentKind'])) {
            $q->whereRaw('EXISTS (SELECT 1 FROM `sale_payments` `sp` JOIN `payment_accounts` `pa` ON `pa`.`id` = `sp`.`payment_account_id`
                WHERE `sp`.`sale_id` = `sales`.`id` AND `pa`.`kind` = ?)', [$query['paymentKind']]);
        }

        $state = $query['paymentState'] ?? (($query['unpaidOnly'] ?? false) ? 'unpaid' : null);
        if ($state === 'unpaid') {
            $q->whereRaw(self::outstandingSql().' > 0');
        } elseif ($state === 'paid') {
            $q->whereRaw(self::outstandingSql().' <= 0');
        }

        // The absolute total, so a void sits in the same bracket as its sale.
        if (isset($query['minAmount'])) {
            $q->whereRaw('ABS(`sales`.`total_minor`) >= ?', [$query['minAmount']]);
        }
        if (isset($query['maxAmount'])) {
            $q->whereRaw('ABS(`sales`.`total_minor`) <= ?', [$query['maxAmount']]);
        }

        $search = JsCompat::trim($query['search'] ?? '');
        if ($search !== '') {
            $term = CatalogService::containsPattern(mb_strtolower($search));
            $q->whereRaw("(
                LOWER(`sales`.`receipt_no`) LIKE ?
                OR LOWER(COALESCE(`sales`.`invoice_no`, '')) LIKE ?
                OR EXISTS (SELECT 1 FROM `customers` `sc` WHERE `sc`.`id` = `sales`.`customer_id`
                           AND (LOWER(`sc`.`name`) LIKE ? OR LOWER(COALESCE(`sc`.`phone`, '')) LIKE ?))
                OR EXISTS (SELECT 1 FROM `sale_items` `si` LEFT JOIN `products` `sp2` ON `sp2`.`id` = `si`.`product_id`
                           WHERE `si`.`sale_id` = `sales`.`id`
                             AND (LOWER(`si`.`product_name`) LIKE ? OR LOWER(COALESCE(`sp2`.`sku`, '')) LIKE ? OR LOWER(COALESCE(`sp2`.`barcode`, '')) LIKE ?))
            )", array_fill(0, 7, $term));
        }
    }

    private static function applyOrder(Builder $q, array $query): void
    {
        $dir = ($query['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        match ($query['sort'] ?? 'date') {
            'amount' => $q->orderByRaw("`sales`.`total_minor` {$dir}")->orderByRaw('`sales`.`id` DESC'),
            'profit' => $q->orderByRaw("(`sales`.`total_minor` - `sales`.`tax_minor` - `sales`.`cogs_minor`) {$dir}")->orderByRaw('`sales`.`id` DESC'),
            'customer' => $q->orderByRaw("LOWER(COALESCE(`customers`.`name`, 'zzzz')) {$dir}")->orderByRaw('`sales`.`id` DESC'),
            'receipt' => $q->orderByRaw("`sales`.`receipt_no` {$dir}")->orderByRaw('`sales`.`id` DESC'),
            // The trading day the column shows; the id keeps a day in the order it was rung up.
            default => $q->orderByRaw("`sales`.`business_date` {$dir}")->orderByRaw("`sales`.`id` {$dir}"),
        };
    }

    /** @return list<array> */
    public static function listSales(array $query = []): array
    {
        $q = DB::table('sales')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('users', 'users.id', '=', 'sales.created_by');
        self::applyConditions($q, $query);
        self::applyOrder($q, $query);

        $rows = $q->limit(min($query['limit'] ?? 100, 500))->offset($query['offset'] ?? 0)->selectRaw(
            '`sales`.`id`, `sales`.`receipt_no`, `sales`.`invoice_no`, `sales`.`kind`, `sales`.`business_date`, `sales`.`occurred_at`,
             `sales`.`customer_id`, `customers`.`name` AS customer_name, `sales`.`total_minor`, `sales`.`discount_minor`, `sales`.`tax_minor`,
             `sales`.`cogs_minor`, `sales`.`status`, `sales`.`voids_sale_id`, `sales`.`created_by`, `users`.`display_name` AS staff_name,
             (SELECT COUNT(*) FROM `sale_items` WHERE `sale_items`.`sale_id` = `sales`.`id`) AS item_count, '
            .self::outstandingSql().' AS outstanding_minor',
        )->get();

        return $rows->map(fn (object $row): array => [
            ...Row::camel($row),
            'itemCount' => (int) $row->item_count,
            'outstandingMinor' => (int) $row->outstanding_minor,
            'profitMinor' => SaleCalculator::saleGrossProfit((int) $row->total_minor, (int) $row->tax_minor, (int) $row->cogs_minor),
        ])->all();
    }

    /** How many sales match, ignoring the page. */
    public static function countSales(array $query = []): int
    {
        $q = DB::table('sales');
        self::applyConditions($q, $query);

        return $q->count();
    }

    /**
     * Totals for exactly the sales a filter selects, voided ones included,
     * because that is what the ledger says happened. count is narrower: sales
     * rung up, not corrections.
     *
     * @return array{count: int, quantity: int, revenue: int, discount: int, cogs: int, grossProfit: int, outstanding: int}
     */
    public static function getFilteredSalesSummary(array $query = []): array
    {
        $q = DB::table('sales');
        self::applyConditions($q, $query);
        // Quantity through a correlated subquery: a join would multiply each total by its line count.
        $row = $q->selectRaw("COALESCE(SUM(CASE WHEN `sales`.`kind` = 'SALE' THEN 1 ELSE 0 END), 0) AS cnt,
            COALESCE(SUM(`sales`.`total_minor`), 0) AS revenue,
            COALESCE(SUM(`sales`.`discount_minor`), 0) AS discount,
            COALESCE(SUM(`sales`.`tax_minor`), 0) AS tax,
            COALESCE(SUM(`sales`.`cogs_minor`), 0) AS cogs,
            COALESCE(SUM(".self::outstandingSql().'), 0) AS outstanding,
            COALESCE(SUM((SELECT COALESCE(SUM(`si`.`qty_milli`), 0) FROM `sale_items` `si` WHERE `si`.`sale_id` = `sales`.`id`)), 0) AS quantity')->first();

        return [
            'count' => (int) $row->cnt,
            'quantity' => (int) $row->quantity,
            'revenue' => (int) $row->revenue,
            'discount' => (int) $row->discount,
            'cogs' => (int) $row->cogs,
            'grossProfit' => SaleCalculator::saleGrossProfit((int) $row->revenue, (int) $row->tax, (int) $row->cogs),
            'outstanding' => (int) $row->outstanding,
        ];
    }

    public static function getSale(int $saleId): array
    {
        $sale = DB::table('sales')->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sales.id', $saleId)
            ->first(['sales.*', 'customers.name AS customer_name', 'customers.phone AS customer_phone'])
            ?? throw new NotFoundError('Sale', $saleId);

        return [
            ...Row::camel($sale),
            'items' => DB::table('sale_items')->where('sale_id', $saleId)->orderBy('id')->get()->map(Row::camel(...))->all(),
            'tenders' => DB::table('sale_payments')
                ->join('payment_accounts', 'payment_accounts.id', '=', 'sale_payments.payment_account_id')
                ->where('sale_payments.sale_id', $saleId)
                ->orderBy('sale_payments.id')
                ->get(['sale_payments.id', 'sale_payments.amount_minor', 'sale_payments.reference', 'payment_accounts.name AS account_name', 'payment_accounts.kind AS account_kind'])
                ->map(Row::camel(...))->all(),
            // What each tax charged ON THE DAY, read back rather than recomputed.
            'taxes' => TaxService::readSaleTaxes($saleId)->map(Row::camel(...))->all(),
            'outstandingMinor' => self::getSaleOutstanding($saleId),
            // Goods that came back on account, which an invoice must not call paid.
            'creditedMinor' => self::creditedOnAccount($saleId),
            'profitMinor' => SaleCalculator::saleGrossProfit((int) $sale->total_minor, (int) $sale->tax_minor, (int) $sale->cogs_minor),
        ];
    }

    /**
     * The payment lines a receipt and an invoice print, from a getSale() result:
     * change given at the till, what has been paid since (returns credited to the
     * account are not money received), what is still due, and any credit in hand.
     *
     * @return array{change: int, alreadyPaid: int, amountDue: int, inCredit: int, settled: bool}
     */
    public static function documentPayments(array $sale): array
    {
        $total = (int) $sale['totalMinor'];
        $outstanding = (int) $sale['outstandingMinor'];
        $tendered = Money::sum(array_map(fn (array $t): int => (int) $t['amountMinor'], $sale['tenders']));

        return [
            'change' => Money::atLeastZero(Money::subtract($tendered, $total)),
            'alreadyPaid' => Money::subtract(Money::subtract($total, (int) $sale['creditedMinor']), $outstanding),
            'amountDue' => Money::atLeastZero($outstanding),
            'inCredit' => Money::atLeastZero(Money::negate($outstanding)),
            'settled' => $outstanding <= 0,
        ];
    }

    /**
     * Totals for a period: every sale document dated in it, voided ones too,
     * so the figure agrees with the P&L; count is sales rung up.
     *
     * @return array{count: int, total: int, cogs: int, grossProfit: int}
     */
    public static function getSalesSummary(string $from, string $to): array
    {
        $row = DB::table('sales')
            ->where('sales.business_date', '>=', $from)
            ->where('sales.business_date', '<=', $to)
            ->selectRaw("COALESCE(SUM(CASE WHEN `sales`.`kind` = 'SALE' THEN 1 ELSE 0 END), 0) AS cnt,
                COALESCE(SUM(`sales`.`total_minor`), 0) AS total,
                COALESCE(SUM(`sales`.`tax_minor`), 0) AS tax,
                COALESCE(SUM(`sales`.`cogs_minor`), 0) AS cogs")
            ->first();

        return [
            'count' => (int) $row->cnt,
            'total' => (int) $row->total,
            'cogs' => (int) $row->cogs,
            'grossProfit' => SaleCalculator::saleGrossProfit((int) $row->total, (int) $row->tax, (int) $row->cogs),
        ];
    }

    /**
     * Each line of a receipt mapped to the batch split it was picked from.
     * Lines whose product does not track stock moved nothing and take nothing.
     *
     * @return array<int, list<array>> by sale_items.id
     */
    public static function batchSplitBySaleItem(int $saleId): array
    {
        $lines = DB::table('sale_items')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sale_items.sale_id', $saleId)
            ->orderBy('sale_items.line_no')
            ->get(['sale_items.id', 'sale_items.product_id', 'products.track_inventory']);

        $queues = [];
        $byLine = [];
        foreach ($lines as $line) {
            if (! $line->track_inventory) {
                continue;
            }
            $productId = (int) $line->product_id;
            $queues[$productId] ??= InventoryService::readBatchSplits('SALE', $saleId, $productId);
            $split = array_shift($queues[$productId]);
            if ($split !== null && $split !== []) {
                $byLine[(int) $line->id] = $split;
            }
        }

        return $byLine;
    }
}
