<div class="w-full">
    @php($m = fn (int $v): string => \App\View\Format::money($v, bare: true))
    <x-page-header title="Purchase report" :description="\App\Livewire\Reports\Report::describePeriod($list['range'], $list['preset'])">
        <x-slot:actions>@include('livewire.reports.actions', ['report' => 'purchases', 'query' => \App\Livewire\Reports\Report::csvQuery($list)])</x-slot:actions>
    </x-page-header>

    <x-filter-bar :list="$list" :dates="true" :fields="[
        ['kind' => 'select', 'key' => 'supplier', 'label' => 'Supplier', 'allLabel' => 'All suppliers', 'options' => $suppliers, 'value' => $list['filters']['supplierId'] ?? null],
        ['kind' => 'select', 'key' => 'category', 'label' => 'Category', 'allLabel' => 'All categories', 'options' => $categories, 'value' => $list['filters']['categoryId'] ?? null],
        ['kind' => 'select', 'key' => 'product', 'label' => 'Product', 'allLabel' => 'All products', 'options' => $products, 'value' => $list['filters']['productId'] ?? null],
        ['kind' => 'select', 'key' => 'account', 'label' => 'Payment method', 'allLabel' => 'All methods', 'options' => $accounts, 'value' => $list['filters']['paymentAccountId'] ?? null],
    ]" />

    @if ($byDay === [])
        <x-empty-state title="No purchases in this period" description="Try a different date range, or record a delivery to see it here." />
    @else
        <div class="mb-6 grid gap-3 sm:grid-cols-3">
            <x-stat icon="expenses" label="Total bought" :value="\App\View\Format::money($total)" />
            <x-stat icon="purchases" label="Deliveries" :value="(string) $count" />
            <x-stat icon="suppliers" label="Suppliers used" :value="(string) count($bySupplier)" />
        </div>

        <h2 class="mb-3 text-sm font-semibold text-content">By supplier</h2>
        <x-table class="mb-8">
            <x-slot:head><x-th>Supplier</x-th><x-th numeric>Deliveries</x-th><x-th numeric>Total</x-th></x-slot:head>
            @foreach ($bySupplier as $row)
                <tr class="border-b border-line"><x-td><span class="font-medium text-content">{{ $row['supplierName'] }}</span></x-td><x-td numeric>{{ $row['purchaseCount'] }}</x-td><x-td numeric>{{ $m($row['total']) }}</x-td></tr>
            @endforeach
            <tr class="bg-surface-sunken font-semibold"><x-td>Total</x-td><x-td numeric>{{ $count }}</x-td><x-td numeric>{{ $m($total) }}</x-td></tr>
        </x-table>

        <h2 class="mb-3 text-sm font-semibold text-content">By product</h2>
        <x-table class="mb-8">
            <x-slot:head><x-th>Product</x-th><x-th numeric>Quantity</x-th><x-th numeric>Total cost</x-th></x-slot:head>
            @foreach ($byProduct as $row)
                <tr class="border-b border-line last:border-0"><x-td><span class="font-medium text-content">{{ $row['productName'] }}</span></x-td><x-td numeric>@qty($row['qtyBought'], $row['unit'])</x-td><x-td numeric>{{ $m($row['total']) }}</x-td></tr>
            @endforeach
        </x-table>

        <h2 class="mb-3 text-sm font-semibold text-content">By day</h2>
        <x-table>
            <x-slot:head><x-th>Date</x-th><x-th numeric>Deliveries</x-th><x-th numeric>Total</x-th></x-slot:head>
            @foreach ($byDay as $row)
                <tr class="border-b border-line last:border-0"><x-td><span class="whitespace-nowrap">@date($row['businessDate'])</span></x-td><x-td numeric>{{ $row['purchaseCount'] }}</x-td><x-td numeric>{{ $m($row['total']) }}</x-td></tr>
            @endforeach
        </x-table>
    @endif
</div>
