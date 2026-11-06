<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Services\CustomerService;
use App\Support\Filters\Filters;
use App\Support\Filters\ListFilters;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The customer list: the old (app)/customers/page.tsx. The three figures on
 * top are shop-wide on purpose: "total owed to you" must equal receivables
 * on the balance sheet whatever is being searched for. Reads only.
 */
#[Title('Customers')]
final class Index extends Component
{
    public array $params = [];

    public function mount(Request $request): void
    {
        $this->params = $request->query();
    }

    public function render(): View
    {
        $list = ListFilters::parseCustomerFilters($this->params);
        $total = CustomerService::countCustomers($list['filters']);
        $list['page'] = Filters::clampPage($list['page'], $total, $list['pageSize']);

        return view('livewire.customers.index', [
            'list' => $list,
            'rows' => CustomerService::listCustomers([...$list['filters'], 'limit' => $list['pageSize'], 'offset' => ($list['page'] - 1) * $list['pageSize']]),
            'total' => $total,
            'customerCount' => CustomerService::countCustomers(),
            'totalOwed' => CustomerService::getTotalReceivables(),
            // A COUNT, not a page length, so it never stops at the page size.
            'owingCount' => CustomerService::countCustomers(['balanceState' => 'owing']),
        ]);
    }
}
