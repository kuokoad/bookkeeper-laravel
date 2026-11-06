<?php

declare(strict_types=1);

namespace App\Reports\Csv;

use App\Services\CashbookService;
use App\Services\CatalogService;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\PaymentAccountService;
use App\Services\PurchaseService;
use App\Services\QuotationService;
use App\Services\SaleService;
use App\Services\SupplierService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use App\Support\Filters\ListFilters;

/**
 * CSV of a FILTERED list: exactly the rows the owner was looking at, through
 * the same parser and the same service read as the page. A port of the old
 * app/api/exports/[list]/route.ts, without the HTTP: a controller checks the
 * module permission (MODULES), throttles, and sends download()'s body with
 * Content-Type text/csv; charset=utf-8, the filename and Cache-Control: no-store.
 * A NotFoundError (an unknown account id) is the controller's 404.
 */
final class ListExport
{
    /** Which permission module a list belongs to, and therefore who may download it. */
    public const MODULES = [
        'sales' => 'sales',
        'purchases' => 'purchases',
        'expenses' => 'expenses',
        'income' => 'income',
        'products' => 'products',
        'stock-movements' => 'inventory',
        'customers' => 'customers',
        'suppliers' => 'suppliers',
        'account' => 'accounts',
        'quotations' => 'quotations',
    ];

    public const MOVEMENT_LABELS = [
        'OPENING_STOCK' => 'Opening stock',
        'PURCHASE' => 'Purchase',
        'PURCHASE_RETURN' => 'Return to supplier',
        'SALE' => 'Sale',
        'SALE_RETURN' => 'Customer return',
        'ADJUSTMENT_IN' => 'Adjustment in',
        'ADJUSTMENT_OUT' => 'Adjustment out',
    ];

    /**
     * The file, or null for an unknown list (or an account export without an id).
     *
     * @param  array  $query  the query string as Laravel gives it
     * @return array{filename: string, body: string, headers: list<string>, rows: list<list<string>>}|null
     */
    public static function download(string $list, array $query, ?string $today = null): ?array
    {
        $today ??= Clock::today();
        $table = self::table($list, $query, $today);
        if ($table === null) {
            return null;
        }

        // The account statement adds an opening and two closing lines of its own.
        $dataRows = $list === 'account' ? max(0, count($table['rows']) - 3) : count($table['rows']);
        $table = self::withTruncationNotice($table, $dataRows);
        $params = Csv::firstValues($query);

        return [
            ...$table,
            'filename' => Csv::filename($list, $params['from'] ?? $today, $params['to'] ?? $today),
            'body' => Csv::toCsv($table['headers'], $table['rows']),
        ];
    }

    /** A last line saying the file stops at the limit: a silently short file is worse than a refusal. */
    private static function withTruncationNotice(array $table, int $rowCount): array
    {
        if ($rowCount < Csv::EXPORT_LIMIT) {
            return $table;
        }
        $notice = ['NOT THE WHOLE SET — this file stops at '.Csv::EXPORT_LIMIT.' rows. Narrow the filter and download again.'];
        while (count($notice) < count($table['headers'])) {
            $notice[] = '';
        }
        $table['rows'][] = $notice;

        return $table;
    }

    /** @return array{headers: list<string>, rows: list<list<string>>}|null */
    public static function table(string $list, array $query, string $today): ?array
    {
        $params = Csv::firstValues($query);
        $page = ['limit' => Csv::EXPORT_LIMIT, 'offset' => 0];
        $m = Csv::money(...);

        switch ($list) {
            case 'quotations':
                $rows = QuotationService::listQuotations([...ListFilters::parseQuotationFilters($params, $today)['filters'], ...$page], $today);

                return [
                    'headers' => ['Quote', 'Issued', 'Valid until', 'Customer', 'Job', 'Subtotal', 'Discount', 'Tax', 'Total', 'Status'],
                    'rows' => array_map(fn (array $q): array => [
                        $q['quoteNo'], (string) $q['businessDate'], (string) $q['validUntil'], $q['customerName'], $q['reference'] ?? '',
                        $m((int) $q['subtotalMinor']), $m((int) $q['discountMinor']), $m((int) $q['taxMinor']), $m((int) $q['totalMinor']),
                        // "Ran out" is a view of an OPEN quote, not a stored status.
                        QuotationService::isExpired($q, $today) ? 'Ran out' : $q['status'],
                    ], $rows),
                ];

            case 'sales':
                $rows = SaleService::listSales([...ListFilters::parseSalesFilters($params, $today)['filters'], ...$page]);

                return [
                    'headers' => ['Receipt', 'Invoice', 'Date', 'Customer', 'Served by', 'Items', 'Total', 'Discount', 'Cost of goods', 'Profit', 'Owing', 'Status'],
                    'rows' => array_map(fn (array $r): array => [
                        $r['receiptNo'], $r['invoiceNo'] ?? '', (string) $r['businessDate'], $r['customerName'] ?? 'Walk-in', $r['staffName'] ?? '',
                        (string) $r['itemCount'], $m((int) $r['totalMinor']), $m((int) $r['discountMinor']), $m((int) $r['cogsMinor']),
                        $m((int) $r['profitMinor']), $m((int) $r['outstandingMinor']),
                        $r['status'] === 'VOIDED' ? 'Voided' : ($r['outstandingMinor'] > 0 ? 'Credit' : 'Paid'),
                    ], $rows),
                ];

            case 'purchases':
                $rows = PurchaseService::listPurchases([...ListFilters::parsePurchaseFilters($params, $today)['filters'], ...$page]);

                return [
                    'headers' => ['Purchase', 'Invoice', 'Date', 'Supplier', 'Items', 'Total', 'Paid', 'Outstanding', 'Status'],
                    'rows' => array_map(fn (array $r): array => [
                        $r['purchaseNo'], $r['invoiceNo'] ?? '', (string) $r['businessDate'], $r['supplierName'] ?? '', (string) $r['itemCount'],
                        $m((int) $r['totalMinor']), $m((int) $r['paidMinor']), $m((int) $r['outstandingMinor']),
                        match (true) {
                            $r['status'] === 'VOIDED' => 'Voided',
                            $r['outstandingMinor'] <= 0 => 'Paid',
                            $r['paidMinor'] > 0 => 'Partly paid',
                            default => 'Unpaid',
                        },
                    ], $rows),
                ];

            case 'expenses':
            case 'income':
                $filters = [...ListFilters::parseCashbookFilters($params, $today)['filters'], ...$page];
                $rows = $list === 'expenses' ? CashbookService::listExpenses($filters) : CashbookService::listIncomes($filters);

                return [
                    'headers' => ['Number', 'Date', 'Category', 'Description', 'Account', 'Reference', 'Amount', 'Status'],
                    'rows' => array_map(fn (array $r): array => [
                        $r['documentNo'], (string) $r['businessDate'], $r['categoryName'], $r['description'], $r['paymentAccountName'], $r['reference'] ?? '',
                        $m((int) $r['amountMinor']), $r['status'] === 'VOIDED' ? 'Voided' : 'Posted',
                    ], $rows),
                ];

            case 'products':
                $rows = CatalogService::listProducts([...ListFilters::parseProductFilters($params)['filters'], ...$page]);

                return [
                    'headers' => ['Product', 'SKU', 'Barcode', 'Category', 'Unit', 'In stock', 'In stock (alternate unit)', 'Average cost', 'Selling price', 'Stock value', 'Stock status', 'Product status'],
                    'rows' => array_map(fn (array $r): array => [
                        $r['name'], $r['sku'] ?? '', $r['barcode'] ?? '', $r['categoryName'] ?? '', $r['unit'],
                        $r['trackInventory'] ? Csv::qty($r['qtyOnHand']) : '',
                        $r['trackInventory'] ? Csv::altQty($r['qtyOnHand'], $r['unit'], $r['altUnit'], $r['altFactor']) : '',
                        $r['trackInventory'] ? $m($r['averageCost']) : '',
                        $m($r['sellingPrice']), $m($r['stockValue']),
                        match (true) {
                            ! $r['trackInventory'] => 'Not tracked',
                            $r['qtyOnHand'] < 0 => 'Negative',
                            $r['outOfStock'] => 'Out of stock',
                            $r['lowStock'] => 'Low',
                            default => 'In stock',
                        },
                        $r['isActive'] ? 'Active' : 'Archived',
                    ], $rows),
                ];

            case 'stock-movements':
                $rows = InventoryService::getStockLedger([...ListFilters::parseStockMovementFilters($params, $today)['filters'], ...$page]);

                return [
                    'headers' => ['Date', 'Product', 'Unit', 'Movement', 'Reference', 'In', 'Out', 'Unit cost', 'Total cost', 'Balance qty', 'Balance value', 'Note'],
                    'rows' => array_map(fn (array $r): array => [
                        (string) $r['businessDate'], $r['productName'], $r['productUnit'], self::MOVEMENT_LABELS[$r['movementType']] ?? $r['movementType'],
                        $r['sourceRef'] ?? '', $r['qtyIn'] === 0 ? '' : Csv::qty($r['qtyIn']), $r['qtyOut'] === 0 ? '' : Csv::qty($r['qtyOut']),
                        $m($r['unitCost']), $m($r['totalCost']), Csv::qty($r['balanceQty']), $m($r['balanceValue']), $r['note'] ?? '',
                    ], $rows),
                ];

            case 'customers':
                $rows = CustomerService::listCustomers([...ListFilters::parseCustomerFilters($params)['filters'], ...$page]);

                return [
                    'headers' => ['Customer', 'Phone', 'Email', 'Credit limit', 'Balance owing', 'Status'],
                    'rows' => array_map(fn (array $r): array => [
                        $r['name'], $r['phone'] ?? '', $r['email'] ?? '', $r['creditLimit'] === null ? '' : $m($r['creditLimit']), $m($r['balance']), $r['isActive'] ? 'Active' : 'Archived',
                    ], $rows),
                ];

            case 'suppliers':
                $rows = SupplierService::listSuppliers([...ListFilters::parseSupplierFilters($params)['filters'], ...$page]);

                return [
                    'headers' => ['Supplier', 'Contact', 'Phone', 'Email', 'Balance owed', 'Status'],
                    'rows' => array_map(fn (array $r): array => [
                        $r['name'], $r['contactPerson'] ?? '', $r['phone'] ?? '', $r['email'] ?? '', $m($r['balance']), $r['isActive'] ? 'Active' : 'Archived',
                    ], $rows),
                ];

            case 'account':
                $accountId = Filters::parseId($params['id'] ?? null);
                if ($accountId === null) {
                    return null;
                }
                $filters = ListFilters::parseAccountFilters($params, $today)['filters'];
                $account = PaymentAccountService::getPaymentAccount($accountId);
                $statement = PaymentAccountService::getAccountStatement($accountId, [...$filters, ...$page]);
                // Same wording as the screen: under a type, flow, search or amount filter these are subtotals.
                $filtered = ListFilters::accountFiltersHideMovements($filters);

                return [
                    'headers' => ['Date', 'What happened', 'Reference', 'Description', 'In', 'Out', $filtered ? 'Filtered running total' : 'Balance'],
                    'rows' => [
                        ["Opening balance — {$account['name']}", '', '', '', '', '', $m($statement['opening'])],
                        ...array_map(fn (array $r): array => [
                            (string) $r['entryDate'], $r['sourceType'], $r['entryNo'], $r['description'] ?? $r['memo'] ?? '',
                            $r['inMinor'] === 0 ? '' : $m($r['inMinor']), $r['outMinor'] === 0 ? '' : $m($r['outMinor']), $m($r['runningBalance']),
                        ], array_reverse($statement['movements'])),
                        ['Total', '', '', '', $m($statement['moneyIn']), $m($statement['moneyOut']), ''],
                        [$filtered ? 'Filtered closing total' : 'Closing balance', '', '', '', '', '', $m($statement['closing'])],
                    ],
                ];
        }

        return null;
    }
}
