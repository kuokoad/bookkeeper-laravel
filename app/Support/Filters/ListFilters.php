<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Enums\MovementType;
use App\Services\CatalogService;
use App\Services\CustomerService;
use App\Services\QuotationService;
use App\Services\SaleService;
use App\Support\Clock;

/**
 * The URL, turned into a service query, once per module for the page and its
 * CSV export alike. Everything is validated on the way in. A port of the
 * product and stock-movement parts of the old lib/list-filters.ts.
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
}
