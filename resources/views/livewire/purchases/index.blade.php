@php
    $sorts = \App\Services\PurchaseService::PURCHASE_SORTS;
    $f = $list['filters'];
    $options = fn (iterable $items): array => collect($items)->mapWithKeys(fn ($i) => [data_get($i, 'id') => data_get($i, 'name')])->all();
@endphp
<div class="w-full">
    <x-page-header title="Purchases" description="What you bought, from whom, and what you still owe.">
        <x-slot:actions>
            <x-csv-button list="purchases" :query="$list['carried']" />
            @can('purchases.create')
                <x-button size="sm" href="/purchases/new">New purchase</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Four figures for the deliveries this filter selects, and one for the shop, which must NOT move with the filter. --}}
    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <x-stat label="Deliveries" icon="purchases" :value="(string) $summary['count']" :hint="$period" />
        <x-stat label="Total cost" icon="expenses" :value="\App\View\Format::money($summary['total'])" />
        <x-stat label="Paid" icon="accounts" :value="\App\View\Format::money($summary['paid'], bare: true)" />
        <x-stat label="Owing on these" icon="owes" :value="\App\View\Format::money($summary['outstanding'], bare: true)" :tone="$summary['outstanding'] > 0 ? 'warning' : 'default'" />
        <x-stat label="You owe suppliers" icon="suppliers" :value="\App\View\Format::money($payables)" :tone="$payables > 0 ? 'warning' : 'default'" hint="Accounts payable, whole shop" />
    </div>

    @php
        $quick = [
        ['label' => "Today's purchases", 'params' => ['period' => 'today', 'from' => null, 'to' => null]],
        ['label' => 'Credit purchases', 'params' => ['paid' => 'outstanding']],
        ['label' => 'Fully paid', 'params' => ['paid' => 'paid']],
        ['label' => 'Partly paid', 'params' => ['paid' => 'partial']],
    ];
    @endphp

    <x-filter-bar :quick="$quick" :list="$list" :dates="true" :fields="[
        ['kind' => 'search', 'key' => 'q', 'label' => 'Search', 'placeholder' => 'Purchase or invoice no, supplier, stock item', 'wide' => true, 'value' => $f['search'] ?? ''],
        ['kind' => 'select', 'key' => 'supplier', 'label' => 'Supplier', 'allLabel' => 'All suppliers', 'options' => $options($suppliers), 'value' => $f['supplierId'] ?? null],
        ['kind' => 'select', 'key' => 'category', 'label' => 'Stock group', 'allLabel' => 'All stock groups', 'options' => $options($categories), 'value' => $f['categoryId'] ?? null],
        ['kind' => 'select', 'key' => 'product', 'label' => 'Stock item', 'allLabel' => 'All stock items', 'options' => $options($products), 'value' => $f['productId'] ?? null],
        ['kind' => 'select', 'key' => 'account', 'label' => 'Paid from', 'allLabel' => 'Any account', 'options' => $options($accounts), 'value' => $f['paymentAccountId'] ?? null],
        ['kind' => 'select', 'key' => 'status', 'label' => 'Status', 'allLabel' => 'All', 'options' => ['POSTED' => 'Posted', 'VOIDED' => 'Voided'], 'value' => $f['status'] ?? null],
        ['kind' => 'amount-range', 'minKey' => 'min', 'maxKey' => 'max', 'label' => 'Total', 'min' => $minText, 'max' => $maxText],
    ]" />

    @if ($rows === [])
        <x-empty-state :title="$isFiltered ? 'No purchases match these filters' : 'No purchases in this period'"
            :description="$isFiltered ? 'Try widening the dates, or clear a filter to see more.' : 'Record a delivery to add stock at what it actually cost you, and to track what you owe.'">
            @if (! $isFiltered && Gate::allows('purchases.create'))
                <x-slot:action><x-button href="/purchases/new">Record a purchase</x-button></x-slot:action>
            @endif
        </x-empty-state>
    @else
        <x-table>
            <x-slot:head>
                <x-th sort="reference" :sorts="$sorts" :list="$list" default-direction="asc">Reference</x-th>
                <x-th sort="date" :sorts="$sorts" :list="$list">Date</x-th>
                <x-th sort="supplier" :sorts="$sorts" :list="$list" default-direction="asc">Supplier</x-th>
                <x-th>Invoice</x-th>
                <x-th numeric>Lines</x-th>
                <x-th sort="amount" :sorts="$sorts" :list="$list" numeric>Total</x-th>
                <x-th sort="outstanding" :sorts="$sorts" :list="$list" numeric>Owing</x-th>
                <x-th>Status</x-th>
            </x-slot:head>
            @foreach ($rows as $row)
                <tr class="border-b border-line last:border-0" wire:key="purchase-{{ $row['id'] }}">
                    <x-td><a href="/purchases/{{ $row['id'] }}" class="font-medium text-accent hover:underline">{{ $row['purchaseNo'] }}</a></x-td>
                    <x-td><span class="whitespace-nowrap text-content-muted">@date($row['businessDate'])</span></x-td>
                    <x-td>
                        @if ($row['supplierName'])
                            <a href="/suppliers/{{ $row['supplierId'] }}" class="text-accent hover:underline">{{ $row['supplierName'] }}</a>
                        @else
                            <span class="text-content-subtle">—</span>
                        @endif
                    </x-td>
                    <x-td><span class="text-content-subtle">{{ $row['invoiceNo'] ?? '—' }}</span></x-td>
                    <x-td numeric>{{ $row['itemCount'] }}</x-td>
                    <x-td numeric>{{ \App\View\Format::money($row['totalMinor'], bare: true) }}</x-td>
                    <x-td numeric>
                        @if ($row['outstandingMinor'] > 0)
                            <span class="font-medium text-warning">{{ \App\View\Format::money($row['outstandingMinor'], bare: true) }}</span>
                        @else
                            <span class="text-content-subtle">—</span>
                        @endif
                    </x-td>
                    <x-td>
                        @if ($row['status'] === 'VOIDED')
                            <x-badge tone="danger">Voided</x-badge>
                        @elseif ($row['kind'] === 'RETURN')
                            <x-badge tone="accent">Return</x-badge>
                        @elseif ($row['kind'] === 'VOID')
                            <x-badge tone="neutral">Reversal</x-badge>
                        @elseif ($row['outstandingMinor'] > 0)
                            <x-badge :tone="$row['paidMinor'] > 0 ? 'warning' : 'danger'">{{ $row['paidMinor'] > 0 ? 'Part paid' : 'Unpaid' }}</x-badge>
                        @else
                            <x-badge tone="success">Paid</x-badge>
                        @endif
                    </x-td>
                </tr>
            @endforeach
        </x-table>
        <x-pagination :list="$list" :total="$total" noun="purchase" />
    @endif

    <p class="mt-4 text-xs text-content-subtle">Every figure above the table is calculated from the deliveries this filter selects, and the CSV downloads the same set.</p>
</div>
