<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Enums\AuditAction;
use App\Enums\JournalSourceType;
use App\Enums\MovementType;
use App\Services\CashbookService;
use App\Services\CatalogService;
use App\Services\CustomerService;
use App\Services\PurchaseService;
use App\Services\QuotationService;
use App\Services\SaleService;
use App\Services\SupplierService;
use App\Support\Clock;
use Illuminate\Support\Carbon;

/**
 * The URL, turned into a service query, once per module for the page and its
 * CSV export alike. Everything is validated on the way in. A port of the
 * product, stock-movement, sales, quotation, customer, purchase and supplier
 * parts of the old lib/list-filters.ts.
 *
 * $params is the query string as Laravel gives it: a value may be a string or
 * a list (a repeated key), and the first value wins.
 */
final class ListFilters
{
    private static function one(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) ? $value : null;
    }

    /** Every filter key that is set, for links that must not drop the current view. */
    private static function carry(array $params, array $keys): array
    {
        $carried = [];
        foreach ($keys as $key) {
            $value = self::one($params, $key);
            if ($value !== null && $value !== '') {
                $carried[$key] = $value;
            }
        }

        return $carried;
    }

    /** @return array{filters: array, range: array{from: string, to: string}, preset: string, page: int, pageSize: int, carried: array<string, string>} */
    public static function parseSalesFilters(array $params, ?string $today = null, int $pageSize = Filters::DEFAULT_PAGE_SIZE): array
    {
        $resolved = Filters::resolveDateRange(self::one($params, 'period'), self::one($params, 'from'), self::one($params, 'to'), $today ?? Clock::today());
        $sort = Filters::parseSort(self::one($params, 'sort'), self::one($params, 'direction'), SaleService::SALE_SORTS, 'date');
        $filters = [
            'from' => $resolved['range']['from'],
            'to' => $resolved['range']['to'],
            'sort' => $sort['sort'],
            'direction' => $sort['direction'],
            ...array_filter([
                'customerId' => Filters::parseId(self::one($params, 'customer')),
                'productId' => Filters::parseId(self::one($params, 'product')),
                'categoryId' => Filters::parseId(self::one($params, 'category')),
                'paymentAccountId' => Filters::parseId(self::one($params, 'account')),
                'staffId' => Filters::parseId(self::one($params, 'staff')),
                'paymentKind' => Filters::parseEnum(self::one($params, 'method'), SaleService::SALE_PAYMENT_KINDS),
                'status' => Filters::parseEnum(self::one($params, 'status'), ['POSTED', 'VOIDED']),
                'paymentState' => Filters::parseEnum(self::one($params, 'paid'), ['paid', 'unpaid']),
                'search' => Filters::parseSearch(self::one($params, 'q')),
            ], fn ($value): bool => $value !== null),
            ...Filters::parseAmountRange(self::one($params, 'min'), self::one($params, 'max')),
        ];
        $paged = Filters::parsePage(self::one($params, 'page'), $pageSize);

        return [
            'filters' => $filters,
            'range' => $resolved['range'],
            'preset' => $resolved['preset'],
            'page' => $paged['page'],
            'pageSize' => $paged['pageSize'],
            'carried' => self::carry($params, ['period', 'from', 'to', 'q', 'customer', 'product', 'category', 'account', 'method', 'staff', 'status', 'paid', 'statusSource', 'min', 'max', 'sort', 'direction']),
        ];
    }

    /** @return array{filters: array, range: array{from: string, to: string}, preset: string, page: int, pageSize: int, carried: array<string, string>} */
    public static function parseQuotationFilters(array $params, ?string $today = null, int $pageSize = Filters::DEFAULT_PAGE_SIZE): array
    {
        $resolved = Filters::resolveDateRange(self::one($params, 'period'), self::one($params, 'from'), self::one($params, 'to'), $today ?? Clock::today());
        $sort = Filters::parseSort(self::one($params, 'sort'), self::one($params, 'direction'), QuotationService::QUOTATION_SORTS, 'date');
        $filters = [
            'from' => $resolved['range']['from'],
            'to' => $resolved['range']['to'],
            'sort' => $sort['sort'],
            'direction' => $sort['direction'],
            ...array_filter([
                'status' => Filters::parseEnum(self::one($params, 'status'), ['OPEN', 'CONVERTED', 'CANCELLED']),
                // Only ever true or absent: "expired=0" narrows nothing.
                'expired' => self::one($params, 'expired') === '1' ? true : null,
                'customerId' => Filters::parseId(self::one($params, 'customer')),
                'search' => Filters::parseSearch(self::one($params, 'q')),
            ], fn ($value): bool => $value !== null),
        ];
        $paged = Filters::parsePage(self::one($params, 'page'), $pageSize);

        return [
            'filters' => $filters,
            'range' => $resolved['range'],
            'preset' => $resolved['preset'],
            'page' => $paged['page'],
            'pageSize' => $paged['pageSize'],
            'carried' => self::carry($params, ['period', 'from', 'to', 'q', 'status', 'expired', 'customer', 'sort', 'direction']),
        ];
    }

    /** @return array{filters: array, page: int, pageSize: int, carried: array<string, string>} */
    public static function parseCustomerFilters(array $params, int $pageSize = Filters::DEFAULT_PAGE_SIZE): array
    {
        $sort = Filters::parseSort(self::one($params, 'sort'), self::one($params, 'direction'), CustomerService::CUSTOMER_SORTS, 'name', 'asc');
        $filters = [
            'sort' => $sort['sort'],
            'direction' => $sort['direction'],
            ...array_filter([
                'search' => Filters::parseSearch(self::one($params, 'q')),
                'balanceState' => Filters::parseEnum(self::one($params, 'balance'), ['owing', 'zero', 'credit']),
                'customerStatus' => Filters::parseEnum(self::one($params, 'archived'), ['active', 'archived']),
            ], fn ($value): bool => $value !== null),
        ];
        $paged = Filters::parsePage(self::one($params, 'page'), $pageSize);

        return [
            'filters' => $filters,
            'page' => $paged['page'],
            'pageSize' => $paged['pageSize'],
            'carried' => self::carry($params, ['q', 'balance', 'archived', 'sort', 'direction']),
        ];
    }

    /** @return array{filters: array, range: array{from: string, to: string}, preset: string, page: int, pageSize: int, carried: array<string, string>} */
    public static function parsePurchaseFilters(array $params, ?string $today = null, int $pageSize = Filters::DEFAULT_PAGE_SIZE): array
    {
        $resolved = Filters::resolveDateRange(self::one($params, 'period'), self::one($params, 'from'), self::one($params, 'to'), $today ?? Clock::today());
        $sort = Filters::parseSort(self::one($params, 'sort'), self::one($params, 'direction'), PurchaseService::PURCHASE_SORTS, 'date');
        $filters = [
            'from' => $resolved['range']['from'],
            'to' => $resolved['range']['to'],
            'sort' => $sort['sort'],
            'direction' => $sort['direction'],
            ...array_filter([
                'supplierId' => Filters::parseId(self::one($params, 'supplier')),
                'productId' => Filters::parseId(self::one($params, 'product')),
                'categoryId' => Filters::parseId(self::one($params, 'category')),
                'paymentAccountId' => Filters::parseId(self::one($params, 'account')),
                'paymentKind' => Filters::parseEnum(self::one($params, 'method'), PurchaseService::PURCHASE_PAYMENT_KINDS),
                'status' => Filters::parseEnum(self::one($params, 'status'), ['POSTED', 'VOIDED']),
                'paymentState' => Filters::parseEnum(self::one($params, 'paid'), ['paid', 'partial', 'outstanding']),
                'search' => Filters::parseSearch(self::one($params, 'q')),
            ], fn ($value): bool => $value !== null),
            ...Filters::parseAmountRange(self::one($params, 'min'), self::one($params, 'max')),
        ];
        $paged = Filters::parsePage(self::one($params, 'page'), $pageSize);

        return [
            'filters' => $filters,
            'range' => $resolved['range'],
            'preset' => $resolved['preset'],
            'page' => $paged['page'],
            'pageSize' => $paged['pageSize'],
            'carried' => self::carry($params, ['period', 'from', 'to', 'q', 'supplier', 'product', 'category', 'account', 'method', 'status', 'paid', 'min', 'max', 'sort', 'direction']),
        ];
    }

    /** @return array{filters: array, page: int, pageSize: int, carried: array<string, string>} */
    public static function parseSupplierFilters(array $params, int $pageSize = Filters::DEFAULT_PAGE_SIZE): array
    {
        $sort = Filters::parseSort(self::one($params, 'sort'), self::one($params, 'direction'), SupplierService::SUPPLIER_SORTS, 'name', 'asc');
        $filters = [
            'sort' => $sort['sort'],
            'direction' => $sort['direction'],
            ...array_filter([
                'search' => Filters::parseSearch(self::one($params, 'q')),
                'balanceState' => Filters::parseEnum(self::one($params, 'balance'), ['owing', 'zero', 'credit']),
                'supplierStatus' => Filters::parseEnum(self::one($params, 'archived'), ['active', 'archived']),
            ], fn ($value): bool => $value !== null),
        ];
        $paged = Filters::parsePage(self::one($params, 'page'), $pageSize);

        return [
            'filters' => $filters,
            'page' => $paged['page'],
            'pageSize' => $paged['pageSize'],
            'carried' => self::carry($params, ['q', 'balance', 'archived', 'sort', 'direction']),
        ];
    }

    /** Expenses and other income alike. @return array{filters: array, range: array{from: string, to: string}, preset: string, page: int, pageSize: int, carried: array<string, string>} */
    public static function parseCashbookFilters(array $params, ?string $today = null, int $pageSize = Filters::DEFAULT_PAGE_SIZE): array
    {
        $resolved = Filters::resolveDateRange(self::one($params, 'period'), self::one($params, 'from'), self::one($params, 'to'), $today ?? Clock::today());
        $sort = Filters::parseSort(self::one($params, 'sort'), self::one($params, 'direction'), CashbookService::CASHBOOK_SORTS, 'date');
        $filters = [
            'from' => $resolved['range']['from'],
            'to' => $resolved['range']['to'],
            'sort' => $sort['sort'],
            'direction' => $sort['direction'],
            ...array_filter([
                'categoryAccountId' => Filters::parseId(self::one($params, 'category')),
                'paymentAccountId' => Filters::parseId(self::one($params, 'account')),
                'staffId' => Filters::parseId(self::one($params, 'staff')),
                'status' => Filters::parseEnum(self::one($params, 'status'), ['POSTED', 'VOIDED']),
                'search' => Filters::parseSearch(self::one($params, 'q')),
            ], fn ($value): bool => $value !== null),
            ...Filters::parseAmountRange(self::one($params, 'min'), self::one($params, 'max')),
        ];
        $paged = Filters::parsePage(self::one($params, 'page'), $pageSize);

        return [
            'filters' => $filters,
            'range' => $resolved['range'],
            'preset' => $resolved['preset'],
            'page' => $paged['page'],
            'pageSize' => $paged['pageSize'],
            'carried' => self::carry($params, ['period', 'from', 'to', 'q', 'category', 'account', 'staff', 'status', 'min', 'max', 'sort', 'direction']),
        ];
    }

    /** One account's statement. @return array{filters: array, range: array{from: string, to: string}, preset: string, page: int, pageSize: int, carried: array<string, string>} */
    public static function parseAccountFilters(array $params, ?string $today = null, int $pageSize = Filters::DEFAULT_PAGE_SIZE): array
    {
        $resolved = Filters::resolveDateRange(self::one($params, 'period'), self::one($params, 'from'), self::one($params, 'to'), $today ?? Clock::today());
        $filters = [
            'from' => $resolved['range']['from'],
            'to' => $resolved['range']['to'],
            ...array_filter([
                // A transaction type is a fixed set: anything else narrows nothing.
                'sourceType' => Filters::parseEnum(self::one($params, 'type'), JournalSourceType::values()),
                'flow' => Filters::parseEnum(self::one($params, 'flow'), ['in', 'out']),
                'search' => Filters::parseSearch(self::one($params, 'q')),
            ], fn ($value): bool => $value !== null),
            ...Filters::parseAmountRange(self::one($params, 'min'), self::one($params, 'max')),
        ];
        $paged = Filters::parsePage(self::one($params, 'page'), $pageSize);

        return [
            'filters' => $filters,
            'range' => $resolved['range'],
            'preset' => $resolved['preset'],
            'page' => $paged['page'],
            'pageSize' => $paged['pageSize'],
            'carried' => self::carry($params, ['period', 'from', 'to', 'q', 'type', 'flow', 'min', 'max']),
        ];
    }

    /** True when filters beyond the dates hide movements, so the statement's figures are filtered subtotals, not the balance. */
    public static function accountFiltersHideMovements(array $filters): bool
    {
        return isset($filters['sourceType']) || isset($filters['flow']) || isset($filters['search']) || isset($filters['minAmount']) || isset($filters['maxAmount']);
    }

    /** @return array{filters: array, page: int, pageSize: int, carried: array<string, string>} */
    public static function parseProductFilters(array $params, int $pageSize = Filters::DEFAULT_PAGE_SIZE): array
    {
        $sort = Filters::parseSort(self::one($params, 'sort'), self::one($params, 'direction'), CatalogService::PRODUCT_SORTS, 'name', 'asc');
        $filters = array_filter([
            'sort' => $sort['sort'],
            'direction' => $sort['direction'],
            'search' => Filters::parseSearch(self::one($params, 'q')),
            'categoryId' => Filters::parseId(self::one($params, 'category')),
            'supplierId' => Filters::parseId(self::one($params, 'supplier')),
            'stockStatus' => Filters::parseEnum(self::one($params, 'stock'), CatalogService::STOCK_STATUSES),
            'expiring' => Filters::parseEnum(self::one($params, 'expiring'), ['expired', 'soon']),
            'productStatus' => Filters::parseEnum(self::one($params, 'archived'), ['active', 'archived']),
        ], fn ($value): bool => $value !== null);
        $paged = Filters::parsePage(self::one($params, 'page'), $pageSize);

        return [
            'filters' => $filters,
            'page' => $paged['page'],
            'pageSize' => $paged['pageSize'],
            'carried' => self::carry($params, ['q', 'category', 'supplier', 'stock', 'expiring', 'archived', 'sort', 'direction']),
        ];
    }

    /**
     * Stock movements default to everything rather than this month: the page
     * answers "where did this stock go", and an answer that stops at the first
     * of the month is the wrong answer.
     *
     * @return array{filters: array, range: array{from: string, to: string}, preset: string, page: int, pageSize: int, carried: array<string, string>}
     */
    public static function parseStockMovementFilters(array $params, ?string $today = null, int $pageSize = Filters::DEFAULT_PAGE_SIZE): array
    {
        $resolved = Filters::resolveDateRange(self::one($params, 'period') ?? 'all', self::one($params, 'from'), self::one($params, 'to'), $today ?? Clock::today());
        $filters = array_filter([
            'from' => $resolved['range']['from'],
            'to' => $resolved['range']['to'],
            'productId' => Filters::parseId(self::one($params, 'product')),
            'categoryId' => Filters::parseId(self::one($params, 'category')),
            'movementType' => Filters::parseEnum(self::one($params, 'movement'), MovementType::values()),
            'userId' => Filters::parseId(self::one($params, 'user')),
            'search' => Filters::parseSearch(self::one($params, 'q')),
        ], fn ($value): bool => $value !== null);
        $paged = Filters::parsePage(self::one($params, 'page'), $pageSize);

        return [
            'filters' => $filters,
            'range' => $resolved['range'],
            'preset' => $resolved['preset'],
            'page' => $paged['page'],
            'pageSize' => $paged['pageSize'],
            'carried' => self::carry($params, ['period', 'from', 'to', 'q', 'product', 'category', 'movement', 'user']),
        ];
    }

    // --- reports: a whole answer for a whole period, never paged -----------

    /** @return array{filters: array{from: string, to: string}, range: array{from: string, to: string}, preset: string, carried: array<string, string>} */
    public static function parseReportPeriod(array $params, ?string $today = null): array
    {
        $resolved = Filters::resolveDateRange(self::one($params, 'period'), self::one($params, 'from'), self::one($params, 'to'), $today ?? Clock::today());

        return ['filters' => $resolved['range'], 'range' => $resolved['range'], 'preset' => $resolved['preset'], 'carried' => self::carry($params, ['period', 'from', 'to'])];
    }

    /**
     * A report export's from and to, resolved exactly as the page resolves a
     * custom range (D10): one end alone means "from then on" or "up to then",
     * a pair the wrong way round is swapped, and with neither valid it is this
     * month so far, as the old CSV route fell back.
     *
     * @return array{from: string, to: string}
     */
    public static function parseReportDates(array $params, ?string $today = null): array
    {
        return Filters::resolveDateRange('custom', self::one($params, 'from'), self::one($params, 'to'), $today ?? Clock::today())['range'];
    }

    /** @return array{filters: array, range: array{from: string, to: string}, preset: string, carried: array<string, string>} */
    public static function parseSalesReportFilters(array $params, ?string $today = null): array
    {
        return self::reportFilters($params, $today, ['customerId' => 'customer', 'productId' => 'product', 'categoryId' => 'category', 'paymentAccountId' => 'account']);
    }

    /** @return array{filters: array, range: array{from: string, to: string}, preset: string, carried: array<string, string>} */
    public static function parsePurchaseReportFilters(array $params, ?string $today = null): array
    {
        return self::reportFilters($params, $today, ['supplierId' => 'supplier', 'productId' => 'product', 'categoryId' => 'category', 'paymentAccountId' => 'account']);
    }

    /** The inventory report is a position, not a period: no dates. @return array{filters: array, carried: array<string, string>} */
    public static function parseInventoryReportFilters(array $params): array
    {
        $filters = array_filter([
            'categoryId' => Filters::parseId(self::one($params, 'category')),
            'supplierId' => Filters::parseId(self::one($params, 'supplier')),
            'stockStatus' => Filters::parseEnum(self::one($params, 'stock'), CatalogService::STOCK_STATUSES),
        ], fn ($value): bool => $value !== null);

        return ['filters' => $filters, 'carried' => self::carry($params, ['category', 'supplier', 'stock'])];
    }

    /** One account, for the cash-flow report. @return array{filters: array{from: string, to: string}, range: array{from: string, to: string}, preset: string, carried: array<string, string>, accountId: ?int} */
    public static function parseCashFlowFilters(array $params, ?string $today = null): array
    {
        return [
            ...self::parseReportPeriod($params, $today),
            'accountId' => Filters::parseId(self::one($params, 'account')),
            'carried' => self::carry($params, ['period', 'from', 'to', 'account']),
        ];
    }

    // --- accounting and the audit log --------------------------------------

    /** The kinds the old journal page offered: every source but the year-end close. */
    public const JOURNAL_SOURCES = [
        'SALE', 'SALE_RETURN', 'PURCHASE', 'PURCHASE_RETURN', 'CUSTOMER_PAYMENT', 'SUPPLIER_PAYMENT', 'EXPENSE', 'INCOME',
        'STOCK_ADJUSTMENT', 'RECONCILIATION', 'OPENING_BALANCE', 'CAPITAL', 'DRAWINGS', 'TRANSFER', 'REVERSAL',
    ];

    /**
     * The journal: from (default the 1st of this month), to (default today)
     * and a source kind. A kind outside the list narrows nothing.
     *
     * @return array{filters: array{from: string, to: string, sourceType?: string}, from: string, to: string, source: ?string, carried: array<string, string>}
     */
    public static function parseJournalFilters(array $params, ?string $today = null): array
    {
        $today ??= Clock::today();
        ['from' => $from, 'to' => $to] = self::monthByDefault(Filters::parseDate(self::one($params, 'from')), Filters::parseDate(self::one($params, 'to')), $today);
        $source = Filters::parseEnum(self::one($params, 'source'), self::JOURNAL_SOURCES);

        return [
            'filters' => ['from' => $from, 'to' => $to, ...($source === null ? [] : ['sourceType' => $source])],
            'from' => $from, 'to' => $to, 'source' => $source,
            // Only what the URL set (and was valid), in resolved order: the defaults are not a filter somebody chose.
            'carried' => array_filter(['from' => Filters::parseDate(self::one($params, 'from')) === null ? null : $from, 'to' => Filters::parseDate(self::one($params, 'to')) === null ? null : $to, 'source' => $source]),
        ];
    }

    /**
     * One account's ledger: from (default the 1st of this month, as the
     * journal; the opening balance carries the earlier lines) and an optional
     * to. With no valid `to` the ledger runs to today, which `toShown` says
     * (never the junk typed), and the query ends there too.
     *
     * @return array{filters: array{from: string, to: string}, from: string, to: ?string, toShown: string, carried: array<string, string>}
     */
    public static function parseLedgerFilters(array $params, ?string $today = null): array
    {
        $today ??= Clock::today();
        $rawFrom = Filters::parseDate(self::one($params, 'from'));
        $rawTo = Filters::parseDate(self::one($params, 'to'));
        ['from' => $from, 'to' => $toShown] = self::monthByDefault($rawFrom, $rawTo, $today);
        // Only a From later than today: run to that day, never a To before the From.
        if ($rawTo === null && $from > $toShown) {
            $toShown = $from;
        }
        $to = $rawTo === null ? null : $toShown;

        return [
            // The query ends where the To box says: a future-dated payment is not in today's balance.
            'filters' => ['from' => $from, 'to' => $toShown], 'from' => $from, 'to' => $to, 'toShown' => $toShown,
            'carried' => array_filter(['from' => $rawFrom === null ? null : $from, 'to' => $to]),
        ];
    }

    /**
     * The trial balance: as at `to` (default today), optionally from a date.
     *
     * @return array{filters: array{from?: string, to: string}, from: ?string, to: string, carried: array<string, string>}
     */
    public static function parseTrialBalanceFilters(array $params, ?string $today = null): array
    {
        $today ??= Clock::today();
        $from = Filters::parseDate(self::one($params, 'from'));
        $to = Filters::parseDate(self::one($params, 'to')) ?? $today;
        if ($from !== null) {
            ['from' => $from, 'to' => $to] = self::orderedDates($from, $to, $today);
        }
        $filters = array_filter(['from' => $from, 'to' => $to]);

        return ['filters' => $filters, 'from' => $from, 'to' => $to, 'carried' => $filters];
    }

    /**
     * Who owes you / who you owe: as at a date (`asAt`, or the CSV's `to`;
     * default today), one party (`$partyKey`: customer or supplier) and a
     * status (overdue = anything over 90 days, current = nothing over 90).
     * The CSV is the whole ageing as at the date, as the old route was; the
     * page narrows the rows after the service built them.
     *
     * @return array{asAt: string, isToday: bool, partyId: ?int, status: ?string, filtered: bool, carried: array<string, string>}
     */
    public static function parseAgeingFilters(array $params, string $partyKey = 'customer', ?string $today = null): array
    {
        $today ??= Clock::today();
        $asAt = Filters::parseDate(self::one($params, 'asAt') ?? self::one($params, 'to')) ?? $today;
        $partyId = Filters::parseId(self::one($params, $partyKey));
        $status = Filters::parseEnum(self::one($params, 'status'), ['overdue', 'current']);

        return [
            'asAt' => $asAt, 'isToday' => $asAt === $today, 'partyId' => $partyId, 'status' => $status,
            'filtered' => $partyId !== null || $status !== null || $asAt !== $today,
            'carried' => array_map('strval', array_filter([$partyKey => $partyId, 'status' => $status, 'asAt' => $asAt === $today ? null : $asAt])),
        ];
    }

    /**
     * The audit log: user, action, entity type (from `$entityTypes`), a
     * search, and an Accra-day from/to turned into UTC instants (the log is
     * stamped in UTC; the whole of the "to" day counts). Unknown values
     * narrow nothing.
     *
     * @param  list<string>  $entityTypes
     * @return array{filters: array<string, mixed>, resolved: array<string, int|string>, page: int, pageSize: int, offset: int, carried: array<string, string>}
     */
    public static function parseAuditFilters(array $params, array $entityTypes, int $pageSize = 50): array
    {
        $resolved = array_filter([
            'user' => Filters::parseId(self::one($params, 'user')),
            'action' => Filters::parseEnum(self::one($params, 'action'), AuditAction::values()),
            'entity' => Filters::parseEnum(self::one($params, 'entity'), $entityTypes),
            'q' => Filters::parseSearch(self::one($params, 'q')),
            'from' => Filters::parseDate(self::one($params, 'from')),
            'to' => Filters::parseDate(self::one($params, 'to')),
        ], fn ($v): bool => $v !== null);
        if (isset($resolved['from'], $resolved['to'])) {
            [$resolved['from'], $resolved['to']] = array_values(self::orderedDates($resolved['from'], $resolved['to'], Clock::today()));
        }
        $paged = Filters::parsePage(self::one($params, 'page'), $pageSize);

        $filters = array_filter([
            'userId' => $resolved['user'] ?? null,
            'action' => isset($resolved['action']) ? AuditAction::from($resolved['action']) : null,
            'entityType' => $resolved['entity'] ?? null,
            'search' => $resolved['q'] ?? null,
            'from' => isset($resolved['from']) ? Carbon::parse($resolved['from'], 'Africa/Accra')->startOfDay()->utc() : null,
            'to' => isset($resolved['to']) ? Carbon::parse($resolved['to'], 'Africa/Accra')->endOfDay()->utc() : null,
        ], fn ($v): bool => $v !== null);

        return [
            'filters' => $filters, 'resolved' => $resolved,
            'page' => $paged['page'], 'pageSize' => $paged['pageSize'], 'offset' => $paged['offset'],
            'carried' => array_map('strval', $resolved),
        ];
    }

    /** Two dates through the shared resolver, so a backwards pair is swapped as the report pages do. @return array{from: string, to: string} */
    private static function orderedDates(?string $from, ?string $to, string $today): array
    {
        return Filters::resolveDateRange('custom', $from, $to, $today)['range'];
    }

    /**
     * From defaults to the 1st of this month, to to today. Only a pair both
     * typed is swapped; a `to` alone before the 1st starts at its own month.
     *
     * @return array{from: string, to: string}
     */
    private static function monthByDefault(?string $from, ?string $to, string $today): array
    {
        if ($from !== null && $to !== null) {
            return self::orderedDates($from, $to, $today);
        }
        $to ??= $today;
        $from ??= min(substr($today, 0, 7), substr($to, 0, 7)).'-01';

        return ['from' => $from, 'to' => $to];
    }

    /** @param  array<string, string>  $ids  filter key => query-string key */
    private static function reportFilters(array $params, ?string $today, array $ids): array
    {
        $resolved = Filters::resolveDateRange(self::one($params, 'period'), self::one($params, 'from'), self::one($params, 'to'), $today ?? Clock::today());
        $filters = $resolved['range'];
        foreach ($ids as $key => $param) {
            $id = Filters::parseId(self::one($params, $param));
            if ($id !== null) {
                $filters[$key] = $id;
            }
        }

        return ['filters' => $filters, 'range' => $resolved['range'], 'preset' => $resolved['preset'], 'carried' => self::carry($params, ['period', 'from', 'to', ...array_values($ids)])];
    }
}
