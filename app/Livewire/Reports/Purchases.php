<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Services\CatalogService;
use App\Services\PaymentAccountService;
use App\Services\Reporting\OperationsService;
use App\Services\SupplierService;
use App\Support\Filters\ListFilters;
use Illuminate\View\View;
use Livewire\Attributes\Title;

/** The old (app)/reports/purchases/page.tsx. */
#[Title('Purchase report')]
final class Purchases extends ReportPage
{
    public function render(): View
    {
        $list = ListFilters::parsePurchaseReportFilters($this->params);
        $byDay = OperationsService::getPurchasesByDay($list['filters']);

        return view('livewire.reports.purchases', [
            'list' => $list,
            'byDay' => $byDay,
            'bySupplier' => OperationsService::getPurchasesBySupplier($list['filters']),
            'byProduct' => OperationsService::getPurchasesByProduct($list['filters']),
            ...OperationsService::getPurchasesReportTotals($byDay),
            'suppliers' => Report::options(SupplierService::listSupplierOptions(true)),
            'categories' => Report::options(CatalogService::listCategories()),
            'products' => Report::options(CatalogService::listProductOptions(true)),
            'accounts' => Report::options(PaymentAccountService::listPaymentAccountOptions(true)),
        ]);
    }
}
