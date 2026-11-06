<div class="w-full">
    @php($m = fn (int $v): string => \App\View\Format::money($v, bare: true))
    <x-page-header title="Profit & Loss" :description="\App\Livewire\Reports\Report::describePeriod($list['range'], $list['preset'])">
        <x-slot:actions>@include('livewire.reports.actions', ['report' => 'profit-and-loss', 'query' => \App\Livewire\Reports\Report::csvQuery($list)])</x-slot:actions>
    </x-page-header>

    <x-filter-bar :list="$list" :dates="true" />

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <x-stat icon="income" label="Net sales" :value="\App\View\Format::money($pl['netSales'])" />
        <x-stat icon="profit" label="Gross profit" :value="\App\View\Format::money($pl['grossProfit'])" :hint="'Margin '.\App\Livewire\Reports\Report::percent($pl['grossMarginBp'])" :tone="$pl['grossProfit'] < 0 ? 'danger' : 'default'" />
        <x-stat icon="profit" label="Net profit" :value="\App\View\Format::money($pl['netProfit'])" :hint="'Margin '.\App\Livewire\Reports\Report::percent($pl['netMarginBp'])" :tone="$pl['netProfit'] < 0 ? 'danger' : 'success'" />
    </div>

    <div class="mb-2 text-sm font-semibold text-content">{{ $businessName }} — Profit &amp; Loss</div>

    <x-table>
        <x-slot:head><x-th>Item</x-th><x-th numeric>Amount</x-th></x-slot:head>
        <tr class="border-b border-line bg-surface-sunken"><x-td><span class="font-semibold text-content">Revenue</span></x-td><x-td /></tr>
        <tr class="border-b border-line"><x-td><span class="pl-4">Sales</span></x-td><x-td numeric>{{ $m($pl['salesRevenue']) }}</x-td></tr>
        @if ($pl['salesDiscounts'] > 0)
            <tr class="border-b border-line"><x-td><span class="pl-4 text-content-muted">Less: discounts given</span></x-td><x-td numeric>({{ $m($pl['salesDiscounts']) }})</x-td></tr>
        @endif
        @if ($pl['salesReturns'] > 0)
            <tr class="border-b border-line"><x-td><span class="pl-4 text-content-muted">Less: goods returned</span></x-td><x-td numeric>({{ $m($pl['salesReturns']) }})</x-td></tr>
        @endif
        <tr class="border-b border-line font-medium"><x-td><span class="pl-4">Net sales</span></x-td><x-td numeric>{{ $m($pl['netSales']) }}</x-td></tr>
        <tr class="border-b border-line bg-surface-sunken"><x-td><span class="font-semibold text-content">Cost of goods sold</span></x-td><x-td numeric>({{ $m($pl['costOfGoodsSold']) }})</x-td></tr>
        <tr class="border-b border-line bg-accent-soft font-semibold"><x-td>Gross profit</x-td><x-td numeric>{{ $m($pl['grossProfit']) }}</x-td></tr>

        @if ($pl['otherIncome'] !== [])
            <tr class="border-b border-line bg-surface-sunken"><x-td><span class="font-semibold text-content">Other income</span></x-td><x-td /></tr>
            @foreach ($pl['otherIncome'] as $line)
                <tr class="border-b border-line"><x-td><span class="pl-4">{{ $line['name'] }}</span></x-td><x-td numeric>{{ $m($line['amount']) }}</x-td></tr>
            @endforeach
            <tr class="border-b border-line font-medium"><x-td><span class="pl-4">Total other income</span></x-td><x-td numeric>{{ $m($pl['totalOtherIncome']) }}</x-td></tr>
        @endif

        <tr class="border-b border-line bg-surface-sunken"><x-td><span class="font-semibold text-content">Running costs</span></x-td><x-td /></tr>
        @forelse ($pl['expenses'] as $line)
            <tr class="border-b border-line"><x-td><span class="pl-4">{{ $line['name'] }}</span></x-td><x-td numeric>{{ $m($line['amount']) }}</x-td></tr>
        @empty
            <tr class="border-b border-line"><x-td><span class="pl-4 text-content-muted">No costs recorded in this period</span></x-td><x-td numeric>—</x-td></tr>
        @endforelse
        <tr class="border-b border-line font-medium"><x-td><span class="pl-4">Total running costs</span></x-td><x-td numeric>({{ $m($pl['totalExpenses']) }})</x-td></tr>
        <tr class="bg-accent-soft text-base font-semibold"><x-td>Net profit</x-td><x-td numeric><span @class(['text-danger' => $pl['netProfit'] < 0])>{{ $m($pl['netProfit']) }}</span></x-td></tr>
    </x-table>

    <p class="mt-4 text-xs text-content-subtle">
        Cost of goods sold is what the items actually cost when they were sold, taken from the stock
        ledger — not from today&rsquo;s prices. Money the owner takes out is not counted here,
        because it is not a cost of running the shop.
    </p>
</div>
