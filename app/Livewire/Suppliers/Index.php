<?php

declare(strict_types=1);

namespace App\Livewire\Suppliers;

use App\Services\SupplierService;
use App\Support\Filters\Filters;
use App\Support\Filters\ListFilters;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The old (app)/suppliers/page.tsx: who you buy from, and what you owe them. Reads only. */
#[Title('Suppliers')]
final class Index extends Component
{
    public const BALANCE_LABELS = ['owing' => 'We owe them', 'zero' => 'Nothing owing', 'credit' => 'They owe us'];

    public array $params = [];

    public function mount(Request $request): void
    {
        $this->params = $request->query();
    }

    public function render(): View
    {
        $list = ListFilters::parseSupplierFilters($this->params);
        $filters = $list['filters'];
        $total = SupplierService::countSuppliers($filters);
        $list['page'] = Filters::clampPage($list['page'], $total, $list['pageSize']);

        return view('livewire.suppliers.index', [
            'list' => $list,
            'total' => $total,
            'suppliers' => SupplierService::listSuppliers([...$filters, 'limit' => $list['pageSize'], 'offset' => ($list['page'] - 1) * $list['pageSize']]),
            // Shop-wide, so the total always equals the payables line on the balance sheet.
            'payables' => SupplierService::getTotalPayables(),
            'supplierCount' => SupplierService::countSuppliers(),
            'owedCount' => SupplierService::countSuppliers(['balanceState' => 'owing']),
            'isFiltered' => isset($filters['search']) || isset($filters['balanceState']) || isset($filters['supplierStatus']),
        ]);
    }
}
