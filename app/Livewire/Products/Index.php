<?php

declare(strict_types=1);

namespace App\Livewire\Products;

use App\Services\CatalogService;
use App\Services\SettingsService;
use App\Services\SupplierService;
use App\Support\Filters\Filters;
use App\Support\Filters\ListFilters;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The old (app)/products/page.tsx: what you sell, its cost and what is on the shelf. Reads only. */
#[Title('Stock items')]
final class Index extends Component
{
    public array $params = [];

    public function mount(Request $request): void
    {
        $this->params = $request->query();
    }

    public function render(): View
    {
        $list = ListFilters::parseProductFilters($this->params);
        $filters = $list['filters'];
        $total = CatalogService::countProducts($filters);
        $list['page'] = Filters::clampPage($list['page'], $total, $list['pageSize']);
        $settings = SettingsService::get();

        $stockOptions = ['in-stock' => 'In stock', 'low' => 'Low or out', 'out' => 'Out of stock'];
        // "Negative stock" only where it can mean something: the shop allows it, or something already is.
        if ($settings->allow_negative_stock || CatalogService::countProducts(['stockStatus' => 'negative']) > 0) {
            $stockOptions['negative'] = 'Negative stock';
        }

        return view('livewire.products.index', [
            'list' => $list,
            'total' => $total,
            'items' => CatalogService::listProducts([...$filters, 'limit' => $list['pageSize'], 'offset' => ($list['page'] - 1) * $list['pageSize']]),
            'categories' => CatalogService::listCategories(),
            'suppliers' => SupplierService::listSupplierOptions(true),
            // The shop as a whole does not move with the filter; the selection line does.
            'shop' => CatalogService::getStockSummary(),
            'selection' => CatalogService::getStockSummary($filters),
            'stockOptions' => $stockOptions,
            'offerExpiry' => SettingsService::features($settings)['expiry_batches'] || CatalogService::hasDatedStock(),
            'isFiltered' => array_diff_key($filters, ['sort' => 1, 'direction' => 1]) !== [],
        ]);
    }
}
