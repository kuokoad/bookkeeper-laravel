<?php

declare(strict_types=1);

namespace App\Livewire\Purchases;

use App\Domain\Money;
use App\Services\CatalogService;
use App\Services\PaymentAccountService;
use App\Services\PurchaseService;
use App\Services\SupplierService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use App\Support\Filters\ListFilters;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The old (app)/purchases/page.tsx: what you bought, from whom, and what you still owe. Reads only. */
#[Title('Purchases')]
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
        $list = ListFilters::parsePurchaseFilters($this->params, $today);
        $filters = $list['filters'];
        $total = PurchaseService::countPurchases($filters);
        $list['page'] = Filters::clampPage($list['page'], $total, $list['pageSize']);
        $filterKeys = ['search', 'supplierId', 'categoryId', 'productId', 'paymentAccountId', 'paymentKind', 'status', 'paymentState', 'minAmount', 'maxAmount'];

        return view('livewire.purchases.index', [
            'list' => $list,
            'total' => $total,
            'rows' => PurchaseService::listPurchases([...$filters, 'limit' => $list['pageSize'], 'offset' => ($list['page'] - 1) * $list['pageSize']]),
            // Totals for the filtered set, not for the month.
            'summary' => PurchaseService::getFilteredPurchasesSummary($filters),
            // Shop-wide, deliberately outside the filter: narrowing to a month cannot reduce what the shop owes.
            'payables' => SupplierService::getTotalPayables(),
            'period' => Filters::describeDateRange($list['range'], $list['preset'], $today),
            'suppliers' => SupplierService::listSupplierOptions(true),
            'categories' => CatalogService::listCategories(),
            'products' => CatalogService::listProductOptions(true),
            'accounts' => PaymentAccountService::listPaymentAccountOptions(true),
            'minText' => isset($filters['minAmount']) ? Money::toInputString($filters['minAmount']) : '',
            'maxText' => isset($filters['maxAmount']) ? Money::toInputString($filters['maxAmount']) : '',
            'isFiltered' => array_intersect_key($filters, array_flip($filterKeys)) !== [] || $list['preset'] !== 'month',
        ]);
    }
}
