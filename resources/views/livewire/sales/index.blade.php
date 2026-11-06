@php
    use App\Domain\Money;
    use App\Services\SaleService;
    use App\Support\Filters\Filters;
    use App\View\Format;

    $f = $list['filters'];
    $isFiltered = $list['preset'] !== 'month'
        || array_intersect_key($f, array_flip(['search', 'customerId', 'productId', 'categoryId', 'paymentAccountId', 'paymentKind', 'staffId', 'status', 'paymentState', 'minAmount', 'maxAmount'])) !== [];
    $options = fn (iterable $items, string $label = 'name') => collect($items)->mapWithKeys(fn ($item) => [(string) data_get($item, 'id') => data_get($item, $label)])->all();
@endphp
<div class="w-full">
    <x-page-header title="Sales" description="Every sale, what it earned and what is still owed.">
        <x-slot:actions>
            <x-csv-button list="sales" :query="$list['carried']" />
            @can('sales.create')
                <x-button size="sm" href="/sales/new">New sale</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- The FILTERED figures: they narrow with the table, never the shop's month to date. --}}
    <div class="mb-3 grid gap-3 sm:grid-cols-3">
        <x-stat label="Revenue" icon="income" :value="Format::money($summary['revenue'])"
            :hint="$summary['outstanding'] > 0 ? Format::money($summary['outstanding'], bare: true).' still owing' : ($summary['count'] > 0 ? 'All settled' : null)" />
        <x-stat label="Gross profit" icon="profit" :value="Format::money($summary['grossProfit'], bare: true)"
            :tone="$summary['grossProfit'] < 0 ? 'danger' : 'success'" hint="Revenue less tax and cost of goods" />
        <x-stat label="Sales" icon="sales" :value="(string) $summary['count']" :hint="Filters::describeDateRange($list['range'], $list['preset'], $today)" />
    </div>
    <div class="mb-6 grid grid-cols-3 gap-3">
        <x-stat size="compact" label="Items sold" icon="products" :value="Format::qty($summary['quantity'])" />
        <x-stat size="compact" label="Discount" icon="discount" :value="Format::money($summary['discount'], bare: true)" />
        <x-stat size="compact" label="Cost of goods" icon="expenses" :value="Format::money($summary['cogs'], bare: true)" />
    </div>

    @php
        $quick = [
        ['label' => "Today's sales", 'params' => ['period' => 'today', 'from' => null, 'to' => null], 'match' => ['period' => 'today']],
        ['label' => 'Credit sales', 'params' => ['paid' => 'unpaid'], 'match' => ['paid' => 'unpaid']],
        ['label' => 'Cash sales', 'params' => ['method' => 'CASH'], 'match' => ['method' => 'CASH']],
        ['label' => 'MoMo sales', 'params' => ['method' => 'MOBILE_MONEY'], 'match' => ['method' => 'MOBILE_MONEY']],
        ['label' => 'Voided', 'params' => ['status' => 'VOIDED'], 'match' => ['status' => 'VOIDED']],
    ];
    @endphp

    <x-filter-bar :quick="$quick" :list="$list" :dates="true" :fields="[
        ['kind' => 'search', 'key' => 'q', 'label' => 'Search', 'placeholder' => 'Receipt, customer, phone, stock item or SKU', 'wide' => true, 'value' => $f['search'] ?? ''],
        ['kind' => 'select', 'key' => 'customer', 'label' => 'Customer', 'allLabel' => 'All customers', 'options' => $options($customers), 'value' => $f['customerId'] ?? null],
        ['kind' => 'select', 'key' => 'category', 'label' => 'Stock group', 'allLabel' => 'All stock groups', 'options' => $options($categories), 'value' => $f['categoryId'] ?? null],
        ['kind' => 'select', 'key' => 'product', 'label' => 'Stock item', 'allLabel' => 'All stock items', 'options' => $options($products), 'value' => $f['productId'] ?? null],
        ['kind' => 'select', 'key' => 'account', 'label' => 'Paid into', 'allLabel' => 'Any account', 'options' => $options($accounts), 'value' => $f['paymentAccountId'] ?? null],
        ['kind' => 'select', 'key' => 'staff', 'label' => 'Served by', 'allLabel' => 'Anyone', 'options' => $options($staff, 'displayName'), 'value' => $f['staffId'] ?? null],
        ['kind' => 'select', 'key' => 'status', 'label' => 'Status', 'allLabel' => 'All', 'options' => ['POSTED' => 'Posted', 'VOIDED' => 'Voided'], 'value' => $f['status'] ?? null],
        ['kind' => 'amount-range', 'minKey' => 'min', 'maxKey' => 'max', 'label' => 'Total', 'min' => isset($f['minAmount']) ? Money::toInputString($f['minAmount']) : '', 'max' => isset($f['maxAmount']) ? Money::toInputString($f['maxAmount']) : ''],
    ]" />

    @if ($rows === [])
        <x-empty-state :title="$isFiltered ? 'No sales match these filters' : 'No sales in this period'"
            :description="$isFiltered ? 'Try widening the dates, or clear a filter to see more.' : 'Sales you record will appear here with their profit and any balance still owing.'">
            @if (! $isFiltered)
                @can('sales.create')
                    <x-slot:action><x-button href="/sales/new">Record a sale</x-button></x-slot:action>
                @endcan
            @endif
        </x-empty-state>
    @else
        <x-table>
            <x-slot:head>
                <x-th sort="receipt" :sorts="SaleService::SALE_SORTS" :list="$list" default-direction="asc">Receipt</x-th>
                <x-th sort="date" :sorts="SaleService::SALE_SORTS" :list="$list">When</x-th>
                <x-th sort="customer" :sorts="SaleService::SALE_SORTS" :list="$list" default-direction="asc">Customer</x-th>
                <x-th numeric>Items</x-th>
                <x-th sort="amount" :sorts="SaleService::SALE_SORTS" :list="$list" numeric>Total</x-th>
                <x-th sort="profit" :sorts="SaleService::SALE_SORTS" :list="$list" numeric>Profit</x-th>
                <x-th numeric>Owing</x-th>
                <x-th>Status</x-th>
            </x-slot:head>
            @foreach ($rows as $row)
                <tr class="border-b border-line last:border-0" wire:key="sale-{{ $row['id'] }}">
                    <x-td><a href="/sales/{{ $row['id'] }}" class="font-medium text-accent hover:underline">{{ $row['receiptNo'] }}</a></x-td>
                    <x-td><span class="whitespace-nowrap text-content-muted">@date($row['businessDate']) {{ \Illuminate\Support\Str::after(Format::dateTime($row['occurredAt']), ', ') }}</span></x-td>
                    <x-td>
                        @if ($row['customerName'])
                            <a href="/customers/{{ $row['customerId'] }}" class="text-accent hover:underline">{{ $row['customerName'] }}</a>
                        @else
                            <span class="text-content-subtle">Walk-in</span>
                        @endif
                        @if ($row['staffName'])<span class="block text-xs text-content-subtle">{{ $row['staffName'] }}</span>@endif
                    </x-td>
                    <x-td numeric>{{ $row['itemCount'] }}</x-td>
                    <x-td numeric>{{ Format::money($row['totalMinor'], bare: true) }}</x-td>
                    <x-td numeric><span @class(['text-danger' => $row['profitMinor'] < 0])>{{ Format::money($row['profitMinor'], bare: true) }}</span></x-td>
                    <x-td numeric>
                        @if ($row['outstandingMinor'] > 0)
                            <span class="font-medium text-warning">{{ Format::money($row['outstandingMinor'], bare: true) }}</span>
                        @else
                            <span class="text-content-subtle">—</span>
                        @endif
                    </x-td>
                    <x-td>
                        @if ($row['status'] === 'VOIDED')
                            <x-badge tone="danger">Voided</x-badge>
                        @elseif ($row['voidsSaleId'] !== null)
                            <x-badge>Reversal</x-badge>
                        @elseif ($row['outstandingMinor'] > 0)
                            <x-badge tone="warning">Credit</x-badge>
                        @else
                            <x-badge tone="success">Paid</x-badge>
                        @endif
                    </x-td>
                </tr>
            @endforeach
        </x-table>
        <x-pagination :list="$list" :total="$total" noun="sale" />
    @endif

    <p class="mt-4 text-xs text-content-subtle">
        Profit is revenue less the cost the goods actually carried when they were sold, taken from
        the stock ledger — not from today&rsquo;s cost price. Every figure above the table is
        calculated from the sales this filter selects, and the CSV downloads the same set.
    </p>
</div>
