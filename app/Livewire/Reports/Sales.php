<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Services\CatalogService;
use App\Services\CustomerService;
use App\Services\PaymentAccountService;
use App\Services\Reporting\OperationsService;
use App\Support\Filters\ListFilters;
use Illuminate\View\View;
use Livewire\Attributes\Title;

/** The old (app)/reports/sales/page.tsx. */
#[Title('Sales report')]
final class Sales extends ReportPage
{
    public function render(): View
    {
        $list = ListFilters::parseSalesReportFilters($this->params);
        $filters = $list['filters'];
        /*
         * A product or a category is a property of a LINE, so once one is chosen
         * every money figure becomes line money; the receipt-level tables are
         * swapped for line-level ones.
         */
        $byLine = isset($filters['productId']) || isset($filters['categoryId']);
        // The lines are read once and shared by every line table on the page.
        $lines = OperationsService::earnedLines($filters);

        $days = $byLine
            ? array_map(fn (array $r): array => ['businessDate' => $r['businessDate'], 'saleCount' => $r['saleCount'], 'revenue' => $r['revenue'], 'cost' => $r['cost'], 'profit' => $r['profit']], OperationsService::getSalesLinesByDay($filters, $lines))
            : array_map(fn (array $r): array => ['businessDate' => $r['businessDate'], 'saleCount' => $r['saleCount'], 'revenue' => $r['total'], 'cost' => $r['cogs'], 'profit' => $r['profit']], OperationsService::getSalesByDay($filters));
        $customerRows = $byLine
            ? array_map(fn (array $r): array => ['key' => $r['customerId'], 'name' => $r['customerName'], 'saleCount' => $r['saleCount'], 'revenue' => $r['revenue'], 'profit' => $r['profit']], OperationsService::getSalesLinesByCustomer($filters, $lines))
            : array_map(fn (array $r): array => ['key' => $r['customerId'], 'name' => $r['customerName'], 'saleCount' => $r['saleCount'], 'revenue' => $r['total'], 'profit' => $r['profit']], OperationsService::getSalesByCustomer($filters));
        $byMethod = $byLine ? [] : OperationsService::getSalesByPaymentMethod($filters);

        return view('livewire.reports.sales', [
            'list' => $list,
            'byLine' => $byLine,
            'days' => $days,
            'customerRows' => $customerRows,
            'byProduct' => OperationsService::getSalesByProduct($filters, $lines),
            'byCategory' => OperationsService::getSalesByCategory($filters, $lines),
            'byMethod' => $byMethod,
            ...OperationsService::getSalesReportTotals($days, $byMethod),
            'customers' => Report::options(CustomerService::listCustomerOptions(true)),
            'categories' => Report::options(CatalogService::listCategories()),
            'products' => Report::options(CatalogService::listProductOptions(true)),
            'accounts' => Report::options(PaymentAccountService::listPaymentAccountOptions(true)),
        ]);
    }
}
