<div class="w-full">
    @php($m = fn (int $v): string => \App\View\Format::money($v, bare: true))
    @php($movementPeriod = mb_strtolower(\App\Livewire\Reports\Report::describePeriod($list['range'], $list['preset'])))
    <x-page-header title="Inventory report" :description="'Valuation as at today · movement '.$movementPeriod">
        <x-slot:actions>@include('livewire.reports.actions', ['report' => 'inventory', 'query' => \App\Livewire\Reports\Report::csvQuery($list)])</x-slot:actions>
    </x-page-header>

    <nav aria-label="Quick filters" class="no-print mb-2 flex flex-wrap gap-2">
        @foreach (['low' => 'Low stock', 'out' => 'Out of stock'] as $key => $label)
            <x-button size="sm" :variant="($list['filters']['stockStatus'] ?? null) === $key ? 'primary' : 'secondary'" href="?{{ http_build_query([...$list['carried'], 'stock' => $key]) }}">{{ $label }}</x-button>
        @endforeach
    </nav>

    <x-filter-bar compact :list="$list" :dates="true" :fields="[
        ['kind' => 'select', 'key' => 'category', 'label' => 'Stock group', 'allLabel' => 'All stock groups', 'options' => $categories, 'value' => $list['filters']['categoryId'] ?? null],
        ['kind' => 'select', 'key' => 'supplier', 'label' => 'Supplier', 'allLabel' => 'All suppliers', 'options' => $suppliers, 'value' => $list['filters']['supplierId'] ?? null],
        ['kind' => 'select', 'key' => 'stock', 'label' => 'Stock status', 'allLabel' => 'Any stock level', 'options' => $stockOptions, 'value' => $list['filters']['stockStatus'] ?? null],
    ]" />

    @if ($isFiltered)
        <p class="no-print mb-4 text-xs text-content-subtle">
            Both tables below cover only the stock items matching these filters — the valuation and
            the stock movement underneath it.
        </p>
    @endif

    @unless ($matchesLedger)
        <x-alert tone="danger" title="Stock value does not match the accounts" class="mb-4">
            Stock is valued at @money($valuation['totalCostValue']) but the Inventory ledger account holds @money($inventoryGl). Please report this.
        </x-alert>
    @endunless

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat icon="inventory" label="Stock at cost" :value="\App\View\Format::money($valuation['totalCostValue'])" hint="What you paid for it" />
        <x-stat icon="inventory" label="Stock at selling price" :value="\App\View\Format::money($valuation['totalRetailValue'])" hint="If it all sold" />
        <x-stat icon="profit" label="Profit if all sold" :value="\App\View\Format::money($valuation['totalPotentialProfit'])" tone="success" />
        <x-stat icon="warning" label="Needs attention" :value="(string) $needsAttention"
            :tone="$valuation['outOfStockCount'] > 0 ? 'danger' : ($valuation['lowStockCount'] > 0 ? 'warning' : 'default')"
            :hint="$valuation['lowStockCount'].' low, '.$valuation['outOfStockCount'].' out'" />
    </div>

    {{-- Dates, and only if the shop keeps any. QUANTITY, never value: a batch has never carried a cost. --}}
    @if (collect($ageing)->contains(fn (array $row): bool => $row['batchCount'] > 0))
        <h2 class="mb-3 text-sm font-semibold text-content">How long the stock has left</h2>
        <x-table class="mb-8">
            <x-slot:head><x-th>When it runs out</x-th><x-th numeric>Batches</x-th><x-th numeric>Quantity</x-th></x-slot:head>
            @foreach ($ageing as $row)
                <tr class="border-b border-line last:border-0">
                    <x-td>
                        <span @class([
                            'font-medium text-danger' => $row['bucket'] === 'expired' && $row['qtyMilli'] > 0,
                            'font-medium text-warning' => $row['bucket'] === 'within7' && $row['qtyMilli'] > 0,
                            'text-content' => ! (in_array($row['bucket'], ['expired', 'within7'], true) && $row['qtyMilli'] > 0),
                        ])>{{ $row['label'] }}</span>
                    </x-td>
                    <x-td numeric>{{ $row['batchCount'] === 0 ? '—' : $row['batchCount'] }}</x-td>
                    <x-td numeric>{{ $row['qtyMilli'] === 0 ? '—' : \App\View\Format::qty($row['qtyMilli']) }}</x-td>
                </tr>
            @endforeach
        </x-table>
        <p class="-mt-6 mb-8 text-xs text-content-muted">
            Quantities across every stock item, so units are mixed. Stock with no date recorded is
            listed separately rather than as distant: unknown is not the same as far away.
            <a href="/export/report/expiry?to={{ $today }}" class="font-medium text-accent hover:underline">Download every batch</a>
            to see which ones.
        </p>
    @endif

    <h2 class="mb-3 text-sm font-semibold text-content">Stock valuation</h2>
    <x-table class="mb-8">
        <x-slot:head><x-th>Stock item</x-th><x-th>Stock group</x-th><x-th numeric>On hand</x-th><x-th numeric>Avg cost</x-th><x-th numeric>Value at cost</x-th><x-th numeric>Selling price</x-th><x-th numeric>Value at retail</x-th><x-th /></x-slot:head>
        @foreach ($valuation['rows'] as $row)
            <tr class="border-b border-line">
                <x-td>
                    <a href="/inventory?product={{ $row['productId'] }}" class="font-medium text-accent hover:underline">{{ $row['productName'] }}</a>
                    @if ($row['sku'])<span class="ml-2 text-xs text-content-subtle">{{ $row['sku'] }}</span>@endif
                </x-td>
                <x-td><span class="text-content-muted">{{ $row['categoryName'] ?? '—' }}</span></x-td>
                <x-td numeric>{{ \App\Domain\Quantity::formatInBothUnits($row['qtyOnHand'], $row['unit'], $row['altUnit'], $row['altFactorMilli']) }}</x-td>
                <x-td numeric>{{ $row['qtyOnHand'] === 0 ? '—' : $m($row['averageCost']) }}</x-td>
                <x-td numeric>{{ $m($row['stockValue']) }}</x-td>
                <x-td numeric>{{ $m($row['sellingPrice']) }}</x-td>
                <x-td numeric>{{ $m($row['retailValue']) }}</x-td>
                <x-td>
                    <div class="flex justify-end">
                        @if ($row['outOfStock'])<x-badge tone="danger">Out</x-badge>@elseif ($row['lowStock'])<x-badge tone="warning">Low</x-badge>@endif
                    </div>
                </x-td>
            </tr>
        @endforeach
        <tr class="bg-surface-sunken font-semibold">
            <x-td>Total</x-td><x-td /><x-td /><x-td />
            <x-td numeric>{{ $m($valuation['totalCostValue']) }}</x-td><x-td />
            <x-td numeric>{{ $m($valuation['totalRetailValue']) }}</x-td><x-td />
        </tr>
    </x-table>

    <h2 class="mb-3 text-sm font-semibold text-content">Stock movement — {{ $movementPeriod }}</h2>
    @if ($movement === [])
        <p class="rounded-xl border border-dashed border-line-strong bg-surface-raised px-6 py-8 text-center text-sm text-content-muted">No stock moved in this period.</p>
    @else
        <x-table>
            <x-slot:head><x-th>Stock item</x-th><x-th numeric>In</x-th><x-th numeric>Out</x-th><x-th numeric>Net</x-th><x-th numeric>Value in</x-th><x-th numeric>Value out</x-th><x-th numeric>On hand now</x-th></x-slot:head>
            @foreach ($movement as $row)
                <tr class="border-b border-line last:border-0">
                    <x-td><span class="font-medium text-content">{{ $row['productName'] }}</span></x-td>
                    <x-td numeric>{{ $row['qtyIn'] > 0 ? \App\View\Format::qty($row['qtyIn'], $row['unit']) : '—' }}</x-td>
                    <x-td numeric>{{ $row['qtyOut'] > 0 ? \App\View\Format::qty($row['qtyOut'], $row['unit']) : '—' }}</x-td>
                    <x-td numeric><span @class(['text-warning' => $row['netQty'] < 0])>@qty($row['netQty'], $row['unit'])</span></x-td>
                    <x-td numeric>{{ $m($row['valueIn']) }}</x-td>
                    <x-td numeric>{{ $m($row['valueOut']) }}</x-td>
                    <x-td numeric>@qty($row['closingQty'], $row['unit'])</x-td>
                </tr>
            @endforeach
        </x-table>
    @endif

    <p class="mt-4 text-xs text-content-subtle">
        Stock is valued at what it actually cost, using weighted average — the same figure that
        appears as Inventory on the balance sheet.
    </p>
</div>
