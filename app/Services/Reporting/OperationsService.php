<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Domain\Inventory\Costing;
use App\Domain\Inventory\StockState;
use App\Domain\JsCompat;
use App\Domain\Money;
use App\Domain\Sales\SaleCalculator;
use App\Services\CatalogService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What sold, what was bought, what is on the shelf: read from the documents
 * and the stock ledger, and tying back to the P&L for the same period. A
 * port of the old reporting/operations.service.ts.
 *
 * A sales query is ['from', 'to', 'customerId'?, 'productId'?, 'categoryId'?,
 * 'paymentAccountId'?]. customerId and paymentAccountId belong to the SALE;
 * productId and categoryId narrow the line tables to matching LINES and the
 * sale-level tables to sales CONTAINING one. A purchase query is the same
 * with supplierId for customerId.
 */
final class OperationsService
{
    /**
     * Every sale document dated in the period, voided or a mirror: voids and
     * returns carry negative figures on their own day, which is what makes
     * each day tie to the P&L.
     */
    private static function salesDatedIn(Builder $q, array $query): Builder
    {
        $q->whereBetween('sales.business_date', [$query['from'], $query['to']]);
        if (isset($query['customerId'])) {
            $q->where('sales.customer_id', $query['customerId']);
        }
        if (isset($query['paymentAccountId'])) {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('sale_payments as sp')->whereColumn('sp.sale_id', 'sales.id')->where('sp.payment_account_id', $query['paymentAccountId']));
        }
        if (isset($query['productId'])) {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('sale_items as si')->whereColumn('si.sale_id', 'sales.id')->where('si.product_id', $query['productId']));
        }
        if (isset($query['categoryId'])) {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('sale_items as si')->join('products as sp_product', 'sp_product.id', '=', 'si.product_id')
                ->whereColumn('si.sale_id', 'sales.id')->where('sp_product.category_id', $query['categoryId']));
        }

        return $q;
    }

    /**
     * The matching LINES, each with its share of what its receipt earned
     * (SaleCalculator::lineEarnings over every line of the receipt, then the
     * non-matching lines dropped). Read once per page or export and handed to
     * each line table. Earnings are shared out one receipt at a time in sale
     * order; PDO still buffers the whole result, so memory grows with the lines.
     *
     * @return list<array{saleId: int, kind: string, standing: bool, businessDate: string, customerId: ?int, customerName: ?string, productId: int, productName: string, unit: string, categoryId: ?int, categoryName: ?string, qty: int, earned: int, cost: int}>
     */
    public static function earnedLines(array $query): array
    {
        $rows = self::salesDatedIn(DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id'), $query)
            ->orderBy('sale_items.sale_id')->orderBy('sale_items.line_no')
            ->selectRaw(TaxReturnService::standingAtEndSql('sales', 'voids_sale_id').' AS standing', [$query['to']])
            ->addSelect(['sale_items.sale_id', 'sales.kind', 'sales.business_date', 'sales.customer_id', 'customers.name as customer_name', 'sales.total_minor', 'sales.tax_minor',
                'sale_items.product_id', 'sale_items.product_name', 'sale_items.unit', 'products.category_id', 'categories.name as category_name',
                'sale_items.qty_milli', 'sale_items.line_total_minor', 'sale_items.total_cost_minor'])->cursor();

        $lines = [];
        $receipt = [];
        foreach ($rows as $row) {
            if ($receipt !== [] && $receipt[0]->sale_id !== $row->sale_id) {
                self::earnReceipt($receipt, $query, $lines);
                $receipt = [];
            }
            $receipt[] = $row;
        }
        if ($receipt !== []) {
            self::earnReceipt($receipt, $query, $lines);
        }

        return $lines;
    }

    /** One receipt's lines, earnings shared over all of them, the matching ones appended to $lines. */
    private static function earnReceipt(array $receipt, array $query, array &$lines): void
    {
        $first = $receipt[0];
        $earned = SaleCalculator::lineEarnings((int) $first->total_minor, (int) $first->tax_minor, array_map(fn (object $r): int => (int) $r->line_total_minor, $receipt));
        foreach ($receipt as $index => $row) {
            $categoryId = $row->category_id === null ? null : (int) $row->category_id;
            if (isset($query['productId']) && (int) $row->product_id !== $query['productId']) {
                continue;
            }
            if (isset($query['categoryId']) && $categoryId !== $query['categoryId']) {
                continue;
            }
            $lines[] = [
                'saleId' => (int) $row->sale_id,
                'kind' => $row->kind,
                'standing' => (bool) $row->standing,
                'businessDate' => (string) $row->business_date,
                'customerId' => $row->customer_id === null ? null : (int) $row->customer_id,
                'customerName' => $row->customer_name,
                'productId' => (int) $row->product_id,
                'productName' => (string) $row->product_name,
                'unit' => (string) $row->unit,
                'categoryId' => $categoryId,
                'categoryName' => $row->category_name,
                'qty' => (int) $row->qty_milli,
                'earned' => $earned[$index] ?? 0,
                'cost' => (int) $row->total_cost_minor,
            ];
        }
    }

    /**
     * Lines added up by a key, in order of first appearance; the newest line
     * gives the names. Receipts count SALE documents still standing at the
     * period end (D23).
     *
     * @return list<array{line: array, qty: int, revenue: int, cost: int, receipts: int}>
     */
    private static function tallyLines(array $lines, callable $keyOf): array
    {
        $tallies = [];
        foreach ($lines as $line) {
            $key = 'k'.$keyOf($line);
            $tally = $tallies[$key] ?? ['line' => $line, 'qty' => 0, 'revenue' => 0, 'cost' => 0, 'receipts' => []];
            $tally['line'] = $line;
            $tally['qty'] += $line['qty'];
            $tally['revenue'] = Money::add($tally['revenue'], $line['earned']);
            $tally['cost'] = Money::add($tally['cost'], $line['cost']);
            if ($line['kind'] === 'SALE' && $line['standing']) {
                $tally['receipts'][$line['saleId']] = true;
            }
            $tallies[$key] = $tally;
        }

        return array_values(array_map(fn (array $t): array => [...$t, 'receipts' => count($t['receipts'])], $tallies));
    }

    private static function byRevenue(array $a, array $b): int
    {
        return $b['revenue'] <=> $a['revenue'];
    }

    /**
     * D23 (owner decision, 2026-11-06): the sale count is D9's "as at the period end" rule, so a
     * sale counts on its day unless a void of it is dated on or before the end ($query['to'] is
     * bound); returns and void mirrors are not sales.
     */
    private static function saleCountSql(): string
    {
        return "COUNT(CASE WHEN sales.kind = 'SALE' AND ".TaxReturnService::standingAtEndSql('sales', 'voids_sale_id').' THEN 1 END)';
    }

    /** @return list<array{businessDate: string, saleCount: int, total: int, tax: int, net: int, cogs: int, profit: int}> */
    public static function getSalesByDay(array $query): array
    {
        return self::salesDatedIn(DB::table('sales'), $query)
            ->groupBy('sales.business_date')->orderBy('sales.business_date')
            ->selectRaw('sales.business_date, '.self::saleCountSql().' AS n, COALESCE(SUM(sales.total_minor), 0) AS total, COALESCE(SUM(sales.tax_minor), 0) AS tax, COALESCE(SUM(sales.cogs_minor), 0) AS cogs', [$query['to']])
            ->get()->map(function (object $r): array {
                // Tax is held for the authority: neither earnings nor profit.
                $net = Money::subtract((int) $r->total, (int) $r->tax);

                return ['businessDate' => (string) $r->business_date, 'saleCount' => (int) $r->n, 'total' => (int) $r->total, 'tax' => (int) $r->tax, 'net' => $net, 'cogs' => (int) $r->cogs, 'profit' => Money::subtract($net, (int) $r->cogs)];
            })->all();
    }

    /** @return list<array{businessDate: string, saleCount: int, revenue: int, cost: int, profit: int}> */
    public static function getSalesLinesByDay(array $query, ?array $lines = null): array
    {
        $tallies = self::tallyLines($lines ?? self::earnedLines($query), fn (array $l): string => $l['businessDate']);
        usort($tallies, fn (array $a, array $b): int => strcmp($a['line']['businessDate'], $b['line']['businessDate']));

        return array_map(fn (array $t): array => ['businessDate' => $t['line']['businessDate'], 'saleCount' => $t['receipts'], 'revenue' => $t['revenue'], 'cost' => $t['cost'], 'profit' => $t['revenue'] - $t['cost']], $tallies);
    }

    /** @return list<array{customerId: ?int, customerName: string, saleCount: int, revenue: int, cost: int, profit: int}> */
    public static function getSalesLinesByCustomer(array $query, ?array $lines = null): array
    {
        $tallies = self::tallyLines($lines ?? self::earnedLines($query), fn (array $l): string => (string) $l['customerId']);
        usort($tallies, self::byRevenue(...));

        return array_map(fn (array $t): array => [
            'customerId' => $t['line']['customerId'],
            'customerName' => $t['line']['customerName'] ?? 'Walk-in customers',
            'saleCount' => $t['receipts'],
            'revenue' => $t['revenue'],
            'cost' => $t['cost'],
            'profit' => $t['revenue'] - $t['cost'],
        ], $tallies);
    }

    /** @return list<array{productId: int, productName: string, unit: string, categoryName: ?string, qtySold: int, revenue: int, cost: int, profit: int, marginBp: ?int}> */
    public static function getSalesByProduct(array $query, ?array $lines = null): array
    {
        $tallies = self::tallyLines($lines ?? self::earnedLines($query), fn (array $l): string => (string) $l['productId']);
        usort($tallies, fn (array $a, array $b): int => self::byRevenue($a, $b) ?: $a['line']['productId'] <=> $b['line']['productId']);

        return array_map(function (array $t): array {
            $profit = $t['revenue'] - $t['cost'];

            return [
                'productId' => $t['line']['productId'],
                'productName' => $t['line']['productName'],
                'unit' => $t['line']['unit'],
                'categoryName' => $t['line']['categoryName'],
                'qtySold' => $t['qty'],
                'revenue' => $t['revenue'],
                'cost' => $t['cost'],
                'profit' => $profit,
                'marginBp' => $t['revenue'] === 0 ? null : Money::mulDiv($profit, 10_000, $t['revenue']),
            ];
        }, $tallies);
    }

    /**
     * The head of getSalesByProduct with revenue AND quantity positive, so a
     * product whose only trade was voided is never the "top seller".
     *
     * @return array{productId: int, productName: string, unit: string, qtySold: int, revenue: int}|null
     */
    public static function getTopProductByRevenue(array $query): ?array
    {
        foreach (self::getSalesByProduct($query) as $row) {
            if ($row['revenue'] > 0 && $row['qtySold'] > 0) {
                return ['productId' => $row['productId'], 'productName' => $row['productName'], 'unit' => $row['unit'], 'qtySold' => $row['qtySold'], 'revenue' => $row['revenue']];
            }
        }

        return null;
    }

    /** @return list<array{categoryId: ?int, categoryName: string, revenue: int, cost: int, profit: int}> */
    public static function getSalesByCategory(array $query, ?array $lines = null): array
    {
        $tallies = self::tallyLines($lines ?? self::earnedLines($query), fn (array $l): string => (string) $l['categoryId']);
        usort($tallies, self::byRevenue(...));

        return array_map(fn (array $t): array => [
            'categoryId' => $t['line']['categoryId'],
            'categoryName' => $t['line']['categoryName'] ?? 'Uncategorised',
            'revenue' => $t['revenue'],
            'cost' => $t['cost'],
            'profit' => $t['revenue'] - $t['cost'],
        ], $tallies);
    }

    /** @return list<array{customerId: ?int, customerName: string, saleCount: int, total: int, profit: int}> */
    public static function getSalesByCustomer(array $query): array
    {
        return self::salesDatedIn(DB::table('sales')->leftJoin('customers', 'customers.id', '=', 'sales.customer_id'), $query)
            ->groupBy('sales.customer_id')
            ->orderByRaw('SUM(sales.total_minor) DESC')->orderBy('sales.customer_id')
            ->selectRaw('sales.customer_id, MAX(customers.name) AS name, '.self::saleCountSql().' AS n, COALESCE(SUM(sales.total_minor), 0) AS total, COALESCE(SUM(sales.tax_minor), 0) AS tax, COALESCE(SUM(sales.cogs_minor), 0) AS cogs', [$query['to']])
            ->get()->map(fn (object $r): array => [
                'customerId' => $r->customer_id === null ? null : (int) $r->customer_id,
                'customerName' => $r->name ?? 'Walk-in customers',
                'saleCount' => (int) $r->n,
                'total' => (int) $r->total,
                'profit' => SaleCalculator::saleGrossProfit((int) $r->total, (int) $r->tax, (int) $r->cogs),
            ])->all();
    }

    /**
     * TENDER per payment account, not sale totals: a credit sale counts only
     * what was handed over, and a void's negative tender puts it back.
     *
     * @return list<array{paymentAccountId: int, accountName: string, kind: string, received: int}>
     */
    public static function getSalesByPaymentMethod(array $query): array
    {
        return self::salesDatedIn(DB::table('sale_payments')->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->join('payment_accounts', 'payment_accounts.id', '=', 'sale_payments.payment_account_id'), $query)
            ->groupBy('sale_payments.payment_account_id')
            ->orderByRaw('SUM(sale_payments.amount_minor) DESC')->orderBy('sale_payments.payment_account_id')
            ->selectRaw('sale_payments.payment_account_id, MAX(payment_accounts.name) AS name, MAX(payment_accounts.kind) AS kind, COALESCE(SUM(sale_payments.amount_minor), 0) AS received')
            ->get()->map(fn (object $r): array => ['paymentAccountId' => (int) $r->payment_account_id, 'accountName' => (string) $r->name, 'kind' => (string) $r->kind, 'received' => (int) $r->received])->all();
    }

    // --- purchases ---------------------------------------------------------

    /**
     * Posted purchases AND supplier returns (D22) dated in the period, each on its own date; every
     * other condition applies to both alike, so the Returned column and its total use the same filter
     * as the rows. A return is never voided (PurchaseService refuses), so POSTED is all of them.
     */
    private static function purchaseConditions(Builder $q, array $query): Builder
    {
        $q->where('purchases.status', 'POSTED')->whereIn('purchases.kind', ['PURCHASE', 'RETURN'])
            ->whereBetween('purchases.business_date', [$query['from'], $query['to']]);
        if (isset($query['supplierId'])) {
            $q->where('purchases.supplier_id', $query['supplierId']);
        }
        if (isset($query['paymentAccountId'])) {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('purchase_payments as pp')->whereColumn('pp.purchase_id', 'purchases.id')->where('pp.payment_account_id', $query['paymentAccountId']));
        }

        return $q;
    }

    private static function purchasesDatedIn(Builder $q, array $query): Builder
    {
        self::purchaseConditions($q, $query);
        if (isset($query['productId'])) {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('purchase_items as pi')->whereColumn('pi.purchase_id', 'purchases.id')->where('pi.product_id', $query['productId']));
        }
        if (isset($query['categoryId'])) {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('purchase_items as pi')->join('products as pi_product', 'pi_product.id', '=', 'pi.product_id')
                ->whereColumn('pi.purchase_id', 'purchases.id')->where('pi_product.category_id', $query['categoryId']));
        }

        return $q;
    }

    private const BOUGHT = "COALESCE(SUM(CASE WHEN purchases.kind = 'PURCHASE' THEN purchases.total_minor ELSE 0 END), 0)";

    // A return's figures are stored negative: Returned is their size.
    private const PURCHASE_FIGURES = "COUNT(CASE WHEN purchases.kind = 'PURCHASE' THEN 1 END) AS n, ".self::BOUGHT." AS total,
        COALESCE(SUM(CASE WHEN purchases.kind = 'RETURN' THEN -purchases.total_minor ELSE 0 END), 0) AS returned";

    /** @return array{purchaseCount: int, total: int, returned: int, net: int} */
    private static function purchaseFigures(object $r): array
    {
        return ['purchaseCount' => (int) $r->n, 'total' => (int) $r->total, 'returned' => (int) $r->returned, 'net' => Money::subtract((int) $r->total, (int) $r->returned)];
    }

    /** @return list<array{supplierId: ?int, supplierName: string, purchaseCount: int, total: int, returned: int, net: int}> */
    public static function getPurchasesBySupplier(array $query): array
    {
        return self::purchasesDatedIn(DB::table('purchases')->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id'), $query)
            ->groupBy('purchases.supplier_id')
            ->orderByRaw(self::BOUGHT.' DESC')->orderBy('purchases.supplier_id')
            ->selectRaw('purchases.supplier_id, MAX(suppliers.name) AS name, '.self::PURCHASE_FIGURES)
            ->get()->map(fn (object $r): array => [
                'supplierId' => $r->supplier_id === null ? null : (int) $r->supplier_id,
                'supplierName' => $r->name ?? 'Unknown',
                ...self::purchaseFigures($r),
            ])->all();
    }

    /**
     * Matching purchase LINES, per product and unit; a return's lines carry the unit of the line they
     * came back from, so they land on its row.
     *
     * @return list<array{productId: int, productName: string, unit: string, qtyBought: int, total: int, returned: int, net: int}>
     */
    public static function getPurchasesByProduct(array $query): array
    {
        $q = self::purchaseConditions(DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id'), $query);
        if (isset($query['productId'])) {
            $q->where('purchase_items.product_id', $query['productId']);
        }
        if (isset($query['categoryId'])) {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('products as pl_product')->whereColumn('pl_product.id', 'purchase_items.product_id')->where('pl_product.category_id', $query['categoryId']));
        }

        // One row per product AND the unit its lines were counted in (D7): 5 carton and 10 pcs never add up to "15".
        // shortcut: a product renamed between deliveries shows MAX(name); SQLite showed whichever row it read last.
        return $q->groupBy('purchase_items.product_id', 'purchase_items.unit')
            ->orderByRaw("SUM(CASE WHEN purchases.kind = 'PURCHASE' THEN purchase_items.line_total_minor ELSE 0 END) DESC")->orderBy('purchase_items.product_id')->orderBy('purchase_items.unit')
            ->selectRaw("purchase_items.product_id, MAX(purchase_items.product_name) AS name, purchase_items.unit,
                COALESCE(SUM(CASE WHEN purchases.kind = 'PURCHASE' THEN purchase_items.qty_milli ELSE 0 END), 0) AS qty,
                COALESCE(SUM(CASE WHEN purchases.kind = 'PURCHASE' THEN purchase_items.line_total_minor ELSE 0 END), 0) AS total,
                COALESCE(SUM(CASE WHEN purchases.kind = 'RETURN' THEN -purchase_items.line_total_minor ELSE 0 END), 0) AS returned")
            ->get()->map(fn (object $r): array => [
                'productId' => (int) $r->product_id, 'productName' => (string) $r->name, 'unit' => (string) $r->unit, 'qtyBought' => (int) $r->qty,
                'total' => (int) $r->total, 'returned' => (int) $r->returned, 'net' => Money::subtract((int) $r->total, (int) $r->returned),
            ])->all();
    }

    /**
     * The sales report's headline figures, summed over its day rows (each
     * with revenue, cost, profit and saleCount: getSalesByDay or
     * getSalesLinesByDay mapped to those names) and its payment-method rows,
     * as the old page summed them. The average is per sale; with no sales it
     * is the total (zero, or a day of returns only).
     *
     * @return array{totalSales: int, totalCost: int, totalProfit: int, saleCount: int, averageSale: int, totalTaken: int}
     */
    public static function getSalesReportTotals(array $days, array $byMethod = []): array
    {
        $totalSales = Money::sum(array_column($days, 'revenue'));
        $saleCount = array_sum(array_column($days, 'saleCount'));

        return [
            'totalSales' => $totalSales,
            'totalCost' => Money::sum(array_column($days, 'cost')),
            'totalProfit' => Money::sum(array_column($days, 'profit')),
            'saleCount' => $saleCount,
            'averageSale' => $saleCount === 0 ? $totalSales : Money::mulDiv($totalSales, 1, $saleCount),
            'totalTaken' => Money::sum(array_column($byMethod, 'received')),
        ];
    }

    /**
     * The purchase report's totals over its day rows: bought, returned (D22), net and the count.
     *
     * @param  list<array{purchaseCount: int, total: int, returned: int, net: int}>  $byDay
     * @return array{total: int, returned: int, net: int, count: int}
     */
    public static function getPurchasesReportTotals(array $byDay): array
    {
        return [
            'total' => Money::sum(array_column($byDay, 'total')),
            'returned' => Money::sum(array_column($byDay, 'returned')),
            'net' => Money::sum(array_column($byDay, 'net')),
            'count' => array_sum(array_column($byDay, 'purchaseCount')),
        ];
    }

    /** @return list<array{businessDate: string, purchaseCount: int, total: int, returned: int, net: int}> */
    public static function getPurchasesByDay(array $query): array
    {
        return self::purchasesDatedIn(DB::table('purchases'), $query)
            ->groupBy('purchases.business_date')->orderBy('purchases.business_date')
            ->selectRaw('purchases.business_date, '.self::PURCHASE_FIGURES)
            ->get()->map(fn (object $r): array => ['businessDate' => (string) $r->business_date, ...self::purchaseFigures($r)])->all();
    }

    // --- inventory ---------------------------------------------------------

    /** Narrow products by category, supplier ever delivered and stock status NOW. */
    private static function stockConditions(Builder $q, array $query, string $productIdColumn): Builder
    {
        if (isset($query['categoryId'])) {
            $q->where('products.category_id', $query['categoryId']);
        }
        if (isset($query['supplierId'])) {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('purchase_items as vi')->join('purchases as vp', 'vp.id', '=', 'vi.purchase_id')
                ->whereColumn('vi.product_id', $productIdColumn)->where('vp.supplier_id', $query['supplierId']));
        }
        if (isset($query['stockStatus'])) {
            $threshold = CatalogService::getLowStockThreshold();
            match ($query['stockStatus']) {
                'low' => $q->whereRaw('products.qty_on_hand_milli <= COALESCE(products.min_stock_milli, ?)', [$threshold]),
                'out' => $q->where('products.qty_on_hand_milli', '<=', 0),
                'negative' => $q->where('products.qty_on_hand_milli', '<', 0),
                'in-stock' => $q->whereRaw('products.qty_on_hand_milli > COALESCE(products.min_stock_milli, ?)', [$threshold]),
                default => null,
            };
        }

        return $q;
    }

    /** @param  array{categoryId?: int, supplierId?: int, stockStatus?: string}  $query */
    public static function getStockValuation(array $query = []): array
    {
        $fallbackMin = CatalogService::getLowStockThreshold();

        // An archived product stays while it still holds stock or value: the inventory account counts it.
        $rows = self::stockConditions(DB::table('products')->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where(fn ($w) => $w->where('products.is_active', true)->orWhere('products.qty_on_hand_milli', '<>', 0)->orWhere('products.stock_value_minor', '<>', 0))
            ->where('products.track_inventory', true), $query, 'products.id')
            ->orderBy('products.name')->orderBy('products.id')
            ->get(['products.id', 'products.name', 'products.sku', 'products.unit', 'products.alt_unit', 'products.alt_factor_milli', 'categories.name as category_name',
                'products.qty_on_hand_milli', 'products.stock_value_minor', 'products.selling_price_minor', 'products.min_stock_milli'])
            ->map(function (object $r) use ($fallbackMin): array {
                $qty = (int) $r->qty_on_hand_milli;
                $value = (int) $r->stock_value_minor;
                $price = (int) $r->selling_price_minor;
                $minStock = $r->min_stock_milli === null ? null : (int) $r->min_stock_milli;
                $retail = JsCompat::round($price * $qty / 1000);

                return [
                    'productId' => (int) $r->id,
                    'productName' => (string) $r->name,
                    'sku' => $r->sku,
                    'unit' => (string) $r->unit,
                    'altUnit' => $r->alt_unit,
                    'altFactorMilli' => $r->alt_factor_milli === null ? null : (int) $r->alt_factor_milli,
                    'categoryName' => $r->category_name,
                    'qtyOnHand' => $qty,
                    'averageCost' => Costing::averageUnitCost(new StockState($qty, $value)),
                    'stockValue' => $value,
                    'sellingPrice' => $price,
                    'retailValue' => $retail,
                    'potentialProfit' => $retail - $value,
                    'minStock' => $minStock,
                    'lowStock' => Costing::isLowStock($qty, $minStock, $fallbackMin),
                    'outOfStock' => Costing::isOutOfStock($qty),
                ];
            })->all();

        return [
            'rows' => $rows,
            'totalCostValue' => Money::sum(array_column($rows, 'stockValue')),
            'totalRetailValue' => Money::sum(array_column($rows, 'retailValue')),
            'totalPotentialProfit' => Money::sum(array_column($rows, 'potentialProfit')),
            'lowStockCount' => count(array_filter($rows, fn (array $r): bool => $r['lowStock'] && ! $r['outOfStock'])),
            'outOfStockCount' => count(array_filter($rows, fn (array $r): bool => $r['outOfStock'])),
        ];
    }

    /**
     * What moved in the period, per product, for the products the same
     * filter as the valuation selects.
     *
     * @param  array{from: string, to: string}  $period
     * @return list<array{productId: int, productName: string, unit: string, qtyIn: int, qtyOut: int, netQty: int, valueIn: int, valueOut: int, closingQty: int, closingValue: int}>
     */
    public static function getStockMovementSummary(array $period, array $query = []): array
    {
        return self::stockConditions(DB::table('stock_ledger')->join('products', 'products.id', '=', 'stock_ledger.product_id')
            ->whereBetween('stock_ledger.business_date', [$period['from'], $period['to']]), $query, 'stock_ledger.product_id')
            ->groupBy('stock_ledger.product_id', 'products.name', 'products.unit', 'products.qty_on_hand_milli', 'products.stock_value_minor')
            ->orderBy('products.name')->orderBy('stock_ledger.product_id')
            ->selectRaw('stock_ledger.product_id, products.name, products.unit, products.qty_on_hand_milli, products.stock_value_minor,
                COALESCE(SUM(stock_ledger.qty_in_milli), 0) AS qin, COALESCE(SUM(stock_ledger.qty_out_milli), 0) AS qout,
                COALESCE(SUM(CASE WHEN stock_ledger.qty_in_milli > 0 THEN stock_ledger.total_cost_minor ELSE 0 END), 0) AS vin,
                COALESCE(SUM(CASE WHEN stock_ledger.qty_out_milli > 0 THEN stock_ledger.total_cost_minor ELSE 0 END), 0) AS vout')
            ->get()->map(fn (object $r): array => [
                'productId' => (int) $r->product_id,
                'productName' => (string) $r->name,
                'unit' => (string) $r->unit,
                'qtyIn' => (int) $r->qin,
                'qtyOut' => (int) $r->qout,
                'netQty' => (int) $r->qin - (int) $r->qout,
                'valueIn' => (int) $r->vin,
                'valueOut' => (int) $r->vout,
                'closingQty' => (int) $r->qty_on_hand_milli,
                'closingValue' => (int) $r->stock_value_minor,
            ])->all();
    }
}
