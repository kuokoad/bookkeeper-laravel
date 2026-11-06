<div class="w-full">
    @php($m = fn (int $v): string => \App\View\Format::money($v, bare: true))
    <x-page-header title="Sales report" :description="\App\Livewire\Reports\Report::describePeriod($list['range'], $list['preset'])">
        <x-slot:actions>@include('livewire.reports.actions', ['report' => 'sales', 'query' => \App\Livewire\Reports\Report::csvQuery($list)])</x-slot:actions>
    </x-page-header>

    <x-filter-bar :list="$list" :dates="true" :fields="[
        ['kind' => 'select', 'key' => 'customer', 'label' => 'Customer', 'allLabel' => 'All customers', 'options' => $customers, 'value' => $list['filters']['customerId'] ?? null],
        ['kind' => 'select', 'key' => 'category', 'label' => 'Category', 'allLabel' => 'All categories', 'options' => $categories, 'value' => $list['filters']['categoryId'] ?? null],
        ['kind' => 'select', 'key' => 'product', 'label' => 'Product', 'allLabel' => 'All products', 'options' => $products, 'value' => $list['filters']['productId'] ?? null],
        ['kind' => 'select', 'key' => 'account', 'label' => 'Payment method', 'allLabel' => 'All methods', 'options' => $accounts, 'value' => $list['filters']['paymentAccountId'] ?? null],
    ]" />

    @if ($byLine)
        <p class="no-print mb-4 rounded-lg border border-line bg-surface-sunken px-3 py-2 text-xs text-content-muted">
            Every figure below is for the matching lines only — this product&rsquo;s revenue and
            this product&rsquo;s cost, not the totals of the receipts it appeared on. Revenue is net
            of tax and discounts: each receipt&rsquo;s tax and invoice discount are shared across
            its lines by value. Payment belongs to the whole receipt, which is why there is no
            payment-method breakdown while this filter is on.
        </p>
    @endif

    @if ($days === [])
        <x-empty-state title="No sales in this period" description="Try a different date range, or record a sale to see it here." />
    @else
        <div class="mb-6 grid gap-3 sm:grid-cols-4">
            <x-stat icon="income" :label="$byLine ? 'Product revenue (net)' : 'Total sales'" :value="\App\View\Format::money($totalSales)" />
            <x-stat icon="profit" label="Gross profit" :value="\App\View\Format::money($totalProfit)" tone="success" />
            <x-stat icon="sales" :label="$byLine ? 'Receipts involved' : 'Number of sales'" :value="(string) $saleCount" />
            <x-stat :label="$byLine ? 'Average per receipt' : 'Average sale'" :value="\App\View\Format::money($averageSale)" />
        </div>

        <h2 class="mb-3 text-sm font-semibold text-content">By day</h2>
        <x-table class="mb-8">
            <x-slot:head><x-th>Date</x-th><x-th numeric>Sales</x-th><x-th numeric>{{ $byLine ? 'Revenue (net)' : 'Takings (with tax)' }}</x-th><x-th numeric>Cost</x-th><x-th numeric>Profit</x-th></x-slot:head>
            @foreach ($days as $row)
                <tr class="border-b border-line">
                    <x-td><span class="whitespace-nowrap">@date($row['businessDate'])</span></x-td>
                    <x-td numeric>{{ $row['saleCount'] }}</x-td>
                    <x-td numeric>{{ $m($row['revenue']) }}</x-td>
                    <x-td numeric>{{ $m($row['cost']) }}</x-td>
                    <x-td numeric>{{ $m($row['profit']) }}</x-td>
                </tr>
            @endforeach
            <tr class="bg-surface-sunken font-semibold">
                <x-td>Total</x-td>
                <x-td numeric>{{ $saleCount }}</x-td>
                <x-td numeric>{{ $m($totalSales) }}</x-td>
                <x-td numeric>{{ $m($totalCost) }}</x-td>
                <x-td numeric>{{ $m($totalProfit) }}</x-td>
            </tr>
        </x-table>
        @unless ($byLine)
            <p class="-mt-6 mb-8 text-xs text-content-subtle">
                Takings are what customers paid, tax included. Profit and the Revenue (net) columns
                below leave tax out, so they will not add up to takings when tax is charged.
            </p>
        @endunless

        <h2 class="mb-3 text-sm font-semibold text-content">By product — what actually makes you money</h2>
        <x-table class="mb-8">
            <x-slot:head><x-th>Product</x-th><x-th>Category</x-th><x-th numeric>Sold</x-th><x-th numeric>Revenue (net)</x-th><x-th numeric>Cost</x-th><x-th numeric>Profit</x-th><x-th numeric>Margin</x-th></x-slot:head>
            @foreach ($byProduct as $row)
                <tr class="border-b border-line last:border-0">
                    <x-td><span class="font-medium text-content">{{ $row['productName'] }}</span></x-td>
                    <x-td><span class="text-content-muted">{{ $row['categoryName'] ?? '—' }}</span></x-td>
                    <x-td numeric>@qty($row['qtySold'], $row['unit'])</x-td>
                    <x-td numeric>{{ $m($row['revenue']) }}</x-td>
                    <x-td numeric>{{ $m($row['cost']) }}</x-td>
                    <x-td numeric><span @class(['text-danger' => $row['profit'] < 0])>{{ $m($row['profit']) }}</span></x-td>
                    <x-td numeric>{{ \App\Livewire\Reports\Report::percent($row['marginBp']) }}</x-td>
                </tr>
            @endforeach
        </x-table>

        <div class="grid gap-8 lg:grid-cols-2">
            <div>
                <h2 class="mb-3 text-sm font-semibold text-content">By category</h2>
                <x-table>
                    <x-slot:head><x-th>Category</x-th><x-th numeric>Revenue (net)</x-th><x-th numeric>Profit</x-th></x-slot:head>
                    @foreach ($byCategory as $row)
                        <tr class="border-b border-line last:border-0"><x-td>{{ $row['categoryName'] }}</x-td><x-td numeric>{{ $m($row['revenue']) }}</x-td><x-td numeric>{{ $m($row['profit']) }}</x-td></tr>
                    @endforeach
                </x-table>
            </div>

            <div>
                <h2 class="mb-3 text-sm font-semibold text-content">By payment method</h2>
                @if ($byLine)
                    <p class="rounded-xl border border-dashed border-line-strong bg-surface-raised px-4 py-6 text-sm text-content-muted">
                        Not shown while a product or category filter is on. Money is handed over for a
                        whole receipt, so there is no honest way to say how much of a cash payment was
                        for one product on it.
                    </p>
                @else
                    <x-table>
                        <x-slot:head><x-th>Method</x-th><x-th numeric>Received</x-th></x-slot:head>
                        @foreach ($byMethod as $row)
                            <tr class="border-b border-line"><x-td>{{ $row['accountName'] }}</x-td><x-td numeric>{{ $m($row['received']) }}</x-td></tr>
                        @endforeach
                        <tr class="bg-surface-sunken font-semibold"><x-td>Total taken</x-td><x-td numeric>{{ $m($totalTaken) }}</x-td></tr>
                    </x-table>
                    <p class="mt-2 text-xs text-content-subtle">
                        This is what was actually handed over, so it is less than total sales when
                        something was sold on credit.
                    </p>
                @endif
            </div>
        </div>

        <h2 class="mt-8 mb-3 text-sm font-semibold text-content">By customer</h2>
        <x-table>
            <x-slot:head><x-th>Customer</x-th><x-th numeric>{{ $byLine ? 'Receipts' : 'Sales' }}</x-th><x-th numeric>{{ $byLine ? 'Revenue (net)' : 'Takings (with tax)' }}</x-th><x-th numeric>Profit</x-th></x-slot:head>
            @foreach ($customerRows as $row)
                <tr class="border-b border-line last:border-0"><x-td>{{ $row['name'] }}</x-td><x-td numeric>{{ $row['saleCount'] }}</x-td><x-td numeric>{{ $m($row['revenue']) }}</x-td><x-td numeric>{{ $m($row['profit']) }}</x-td></tr>
            @endforeach
        </x-table>
    @endif
</div>
