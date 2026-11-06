<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Services\CatalogService;
use App\Services\CustomerService;
use App\Services\PaymentAccountService;
use App\Services\SaleService;
use App\Services\UserService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use App\Support\Filters\ListFilters;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The sales list: the old (app)/sales/page.tsx. One parser for the page and
 * its CSV; the figures above the table are the FILTERED totals. Reads only.
 */
#[Title('Sales')]
final class Index extends Component
{
    public array $params = [];

    public function mount(Request $request): void
    {
        $this->params = $request->query();
    }

    public function render(): View
    {
        $today = Clock::today();
        $list = ListFilters::parseSalesFilters($this->params, $today);
        // Count first, then clamp: a narrowed filter must not leave an empty page under a pager.
        $total = SaleService::countSales($list['filters']);
        $list['page'] = Filters::clampPage($list['page'], $total, $list['pageSize']);

        return view('livewire.sales.index', [
            'list' => $list,
            'today' => $today,
            'rows' => SaleService::listSales([...$list['filters'], 'limit' => $list['pageSize'], 'offset' => ($list['page'] - 1) * $list['pageSize']]),
            'total' => $total,
            'summary' => SaleService::getFilteredSalesSummary($list['filters']),
            // Options from the shop's own records, so a filter only offers what exists.
            'customers' => CustomerService::listCustomerOptions(true),
            'categories' => CatalogService::listCategories(),
            'products' => CatalogService::listProductOptions(true),
            'accounts' => PaymentAccountService::listPaymentAccountOptions(true),
            'staff' => UserService::listUsers(),
        ]);
    }
}
