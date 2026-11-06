@php
    $sorts = \App\Services\CatalogService::PRODUCT_SORTS;
    $f = $list['filters'];
@endphp
<div class="w-full">
    <x-page-header title="Stock items" description="What you sell, what it costs you, and how much is on the shelf.">
        <x-slot:actions>
            <x-csv-button list="products" :query="$list['carried']" />
            @can('products.create')
                <x-button variant="secondary" size="sm" href="/products/categories">Stock groups</x-button>
                <x-button variant="secondary" size="sm" href="/products/units">Units of measure</x-button>
                <x-button variant="secondary" size="sm" href="/products/import">Import</x-button>
                <x-button size="sm" href="/products/new">Add stock item</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- The shop as a whole. These do not move with the filter, on purpose. --}}
    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Stock items" icon="products" :value="(string) $shop['productCount']" />
        <x-stat label="Stock value" icon="inventory" :value="\App\View\Format::money($shop['totalStockValue'])" hint="At weighted average cost" />
        <x-stat label="Low stock" icon="warning" :value="(string) $shop['lowStockCount']" :tone="$shop['lowStockCount'] > 0 ? 'warning' : 'default'" />
        <x-stat label="Out of stock" icon="warning" :value="(string) $shop['outOfStockCount']" :tone="$shop['outOfStockCount'] > 0 ? 'danger' : 'default'" />
    </div>

    @if (isset($f['expiring']))
        <x-alert :tone="$f['expiring'] === 'expired' ? 'danger' : 'warning'" class="mb-4"
            :title="$f['expiring'] === 'expired' ? 'Showing stock items with stock that has expired' : 'Showing stock items with stock expiring soon'">
            <p>
                {{ $f['expiring'] === 'expired'
                    ? 'It cannot be sold. Record a stock adjustment with the reason "Expired" to take it off the shelf and out of the accounts.'
                    : 'Still sellable, and worth moving first.' }}
                <a href="/products" class="font-medium text-accent hover:underline">Show everything</a>
            </p>
        </x-alert>
    @endif

    @php
        $quick = array_values(array_filter([
        ['label' => 'Low stock', 'params' => ['stock' => 'low']],
        ['label' => 'Out of stock', 'params' => ['stock' => 'out']],
        $offerExpiry ? ['label' => 'Expiring soon', 'params' => ['expiring' => 'soon']] : null,
        ['label' => 'Archived', 'params' => ['archived' => 'archived']],
    ]));
    @endphp

    <x-filter-bar :quick="$quick" :list="$list" :fields="[
        ['kind' => 'search', 'key' => 'q', 'label' => 'Search', 'placeholder' => 'Name, SKU or barcode', 'wide' => true, 'value' => $f['search'] ?? ''],
        ['kind' => 'select', 'key' => 'category', 'label' => 'Stock group', 'allLabel' => 'All stock groups', 'options' => collect($categories)->mapWithKeys(fn ($c) => [$c->id => $c->name])->all(), 'value' => $f['categoryId'] ?? null],
        ['kind' => 'select', 'key' => 'supplier', 'label' => 'Supplier', 'allLabel' => 'All suppliers', 'options' => collect($suppliers)->mapWithKeys(fn ($s) => [$s['id'] => $s['name']])->all(), 'value' => $f['supplierId'] ?? null],
        ['kind' => 'select', 'key' => 'stock', 'label' => 'Stock status', 'allLabel' => 'Any stock level', 'options' => $stockOptions, 'value' => $f['stockStatus'] ?? null],
        ['kind' => 'select', 'key' => 'archived', 'label' => 'Stock item status', 'allLabel' => 'Active only', 'options' => ['active' => 'Active', 'archived' => 'Archived'], 'value' => $f['productStatus'] ?? null],
    ]" />

    @if ($items === [])
        <x-empty-state :title="$isFiltered ? 'No stock items match' : 'No stock items yet'"
            :description="$isFiltered ? 'Try a different search, or clear the filters to see everything.' : 'Add the things you sell. You can enter opening stock afterwards with a stock adjustment, so every figure traces back to a record.'">
            @if (! $isFiltered && Gate::allows('products.create'))
                <x-slot:action><x-button href="/products/new">Add your first stock item</x-button></x-slot:action>
            @endif
        </x-empty-state>
    @else
        @if ($isFiltered)
            <p class="mb-3 text-xs text-content-subtle">
                These {{ $total }} product(s) hold {{ \App\View\Format::money($selection['totalStockValue']) }} of stock — {{ $selection['lowStockCount'] }} low, {{ $selection['outOfStockCount'] }} out.
            </p>
        @endif

        <x-table>
            <x-slot:head>
                <x-th sort="name" :sorts="$sorts" :list="$list" default-direction="asc">Stock item</x-th>
                <x-th sort="category" :sorts="$sorts" :list="$list" default-direction="asc">Stock group</x-th>
                <x-th sort="quantity" :sorts="$sorts" :list="$list" default-direction="asc" numeric>In stock</x-th>
                <x-th sort="cost" :sorts="$sorts" :list="$list" numeric>Avg cost</x-th>
                <x-th sort="price" :sorts="$sorts" :list="$list" numeric>Selling price</x-th>
                <x-th sort="value" :sorts="$sorts" :list="$list" numeric>Stock value</x-th>
                <x-th />
            </x-slot:head>
            @foreach ($items as $item)
                <tr class="border-b border-line last:border-0" wire:key="product-{{ $item['id'] }}">
                    <x-td>
                        <div class="font-medium text-content">{{ $item['name'] }}</div>
                        <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-content-subtle">
                            @if ($item['sku'])<span>SKU {{ $item['sku'] }}</span>@endif
                            @if ($item['barcode'])<span>· {{ $item['barcode'] }}</span>@endif
                            @if (! $item['isActive'])<x-badge tone="neutral">Archived</x-badge>@endif
                        </div>
                    </x-td>
                    <x-td><span class="text-content-muted">{{ $item['categoryName'] ?? '—' }}</span></x-td>
                    <x-td numeric>
                        @if ($item['trackInventory'])
                            <span class="inline-flex items-center gap-2">
                                {{ \App\Domain\Quantity::formatInBothUnits($item['qtyOnHand'], $item['unit'], $item['altUnit'], $item['altFactor']) }}
                                @if ($item['qtyOnHand'] < 0)
                                    <x-badge tone="danger">Negative</x-badge>
                                @elseif ($item['outOfStock'])
                                    <x-badge tone="danger">Out</x-badge>
                                @elseif ($item['lowStock'])
                                    <x-badge tone="warning">Low</x-badge>
                                @endif
                            </span>
                        @else
                            <span class="text-content-subtle">Not tracked</span>
                        @endif
                    </x-td>
                    <x-td numeric>
                        @if ($item['trackInventory'] && $item['qtyOnHand'] !== 0)
                            {{ \App\View\Format::money($item['averageCost'], bare: true) }}
                        @else
                            <span class="text-content-subtle">—</span>
                        @endif
                    </x-td>
                    <x-td numeric>{{ \App\View\Format::money($item['sellingPrice'], bare: true) }}</x-td>
                    <x-td numeric>{{ \App\View\Format::money($item['stockValue'], bare: true) }}</x-td>
                    <x-td>
                        <div class="flex justify-end gap-2 whitespace-nowrap">
                            <a href="/inventory?product={{ $item['id'] }}" class="text-xs font-medium text-accent hover:underline">History</a>
                            @can('products.edit')
                                <a href="/products/{{ $item['id'] }}/edit" class="text-xs font-medium text-accent hover:underline">Edit</a>
                            @endcan
                        </div>
                    </x-td>
                </tr>
            @endforeach
        </x-table>
        <x-pagination :list="$list" :total="$total" noun="product" />
    @endif

    <p class="mt-4 text-xs text-content-subtle">
        Stock quantities cannot be typed in directly. Every change comes from a purchase, a sale or a recorded stock adjustment, which is what lets any figure here be traced back to its source.
    </p>
</div>
