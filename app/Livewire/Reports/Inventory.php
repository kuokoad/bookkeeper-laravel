<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Domain\Accounting\ChartOfAccounts;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Services\Reporting\BalancesService;
use App\Services\Reporting\OperationsService;
use App\Services\SettingsService;
use App\Services\SupplierService;
use App\Support\Clock;
use App\Support\Filters\ListFilters;
use Illuminate\View\View;
use Livewire\Attributes\Title;

/** The old (app)/reports/inventory/page.tsx: valuation as at today, movement over a period. */
#[Title('Inventory report')]
final class Inventory extends ReportPage
{
    public const STOCK_LABELS = ['in-stock' => 'In stock', 'low' => 'Low or out', 'out' => 'Out of stock', 'negative' => 'Negative stock'];

    public function render(): View
    {
        $today = Clock::today();
        $period = ListFilters::parseReportPeriod($this->params, $today);
        $stock = ListFilters::parseInventoryReportFilters($this->params);
        $list = [...$period, 'filters' => $stock['filters'], 'carried' => [...$period['carried'], ...$stock['carried']]];
        $valuation = OperationsService::getStockValuation($stock['filters']);
        $inventoryGl = BalancesService::getAccountBalanceByCode(ChartOfAccounts::INVENTORY);

        // "Negative stock" is offered only when it can mean something: the shop allows it, or something has gone negative.
        $offerNegative = CatalogService::countProducts(['stockStatus' => 'negative']) > 0 || (bool) SettingsService::get()->allow_negative_stock;
        $stockOptions = $offerNegative ? self::STOCK_LABELS : array_slice(self::STOCK_LABELS, 0, 3, true);

        return view('livewire.reports.inventory', [
            'list' => $list,
            'today' => $today,
            'valuation' => $valuation,
            'ageing' => InventoryService::getExpiryAgeing($today),
            'movement' => OperationsService::getStockMovementSummary($period['range'], $stock['filters']),
            'inventoryGl' => $inventoryGl,
            // The ledger check is the WHOLE shop against the Inventory account: a filtered subset would raise a false alarm.
            'matchesLedger' => OperationsService::getStockValuation()['totalCostValue'] === $inventoryGl,
            'isFiltered' => $stock['filters'] !== [],
            'needsAttention' => $valuation['lowStockCount'] + $valuation['outOfStockCount'],
            'stockOptions' => $stockOptions,
            'categories' => Report::options(CatalogService::listCategories()),
            'suppliers' => Report::options(SupplierService::listSupplierOptions(true)),
        ]);
    }
}
