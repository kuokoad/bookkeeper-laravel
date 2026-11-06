<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Services\Reporting\BalancesService;
use App\Services\UserService;
use App\Support\Clock;
use App\Support\Filters\Filters;
use App\Support\Filters\ListFilters;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The old (app)/inventory/page.tsx: the stock ledger, what is running low, and the integrity checks. Reads only. */
#[Title('Inventory')]
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
        $list = ListFilters::parseStockMovementFilters($this->params, $today);
        $filters = $list['filters'];
        $total = InventoryService::countStockLedger($filters);
        $list['page'] = Filters::clampPage($list['page'], $total, $list['pageSize']);

        $productId = $filters['productId'] ?? null;
        $focus = $productId === null ? null : (CatalogService::listProducts(['includeInactive' => true, 'id' => $productId, 'limit' => 1])[0] ?? null);
        $inventoryCache = InventoryService::getInventoryValue();
        $inventoryGl = BalancesService::getAccountBalanceByCode(ChartOfAccounts::INVENTORY);

        return view('livewire.inventory.index', [
            'list' => $list,
            'total' => $total,
            'ledger' => InventoryService::getStockLedger([...$filters, 'limit' => $list['pageSize'], 'offset' => ($list['page'] - 1) * $list['pageSize']]),
            'summary' => CatalogService::getStockSummary(),
            'lowStock' => CatalogService::listProducts(['stockStatus' => 'low', 'sort' => 'quantity', 'direction' => 'asc', 'limit' => 50]),
            'categories' => CatalogService::listCategories(),
            'products' => CatalogService::listProductOptions(true),
            'staff' => UserService::listUsers(),
            'focus' => $focus,
            'batches' => $productId === null ? [] : InventoryService::listProductBatches($productId, $today),
            'drifted' => array_values(array_filter(InventoryService::verifyStockAgainstLedger(), fn (array $v): bool => ! $v['ok'])),
            'inventoryCache' => $inventoryCache,
            'inventoryGl' => $inventoryGl,
            'isFiltered' => array_diff_key($filters, ['from' => 1, 'to' => 1]) !== [] || $list['preset'] !== 'all',
        ]);
    }
}
