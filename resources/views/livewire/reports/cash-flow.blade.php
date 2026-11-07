<div class="w-full">
    @php($m = fn (int $v): string => \App\View\Format::money($v, bare: true))
    <x-page-header title="Cash flow" :description="\App\Livewire\Reports\Report::describePeriod($list['range'], $list['preset'])">
        <x-slot:actions>@include('livewire.reports.actions', ['report' => 'cash-flow', 'query' => \App\Livewire\Reports\Report::csvQuery($list)])</x-slot:actions>
    </x-page-header>

    <x-filter-bar compact :list="$list" :dates="true" :fields="[
        ['kind' => 'select', 'key' => 'account', 'label' => 'Account', 'allLabel' => 'All accounts', 'options' => $accounts, 'value' => $list['accountId']],
    ]" />

    @unless ($flow['reconciles'])
        <x-alert tone="danger" title="Cash flow does not reconcile" class="mb-4">The closing balance does not equal the sum of the per-account closing balances. Please report this.</x-alert>
    @endunless

    <div class="mb-6 grid gap-3 sm:grid-cols-4">
        <x-stat icon="accounts" label="Started with" :value="\App\View\Format::money($flow['openingBalance'])" />
        <x-stat icon="income" label="Money in" :value="\App\View\Format::money($flow['totalIn'])" tone="success" />
        <x-stat icon="expenses" label="Money out" :value="\App\View\Format::money($flow['totalOut'])" tone="warning" />
        <x-stat icon="accounts" label="Ended with" :value="\App\View\Format::money($flow['closingBalance'])" :tone="$flow['closingBalance'] < 0 ? 'danger' : 'default'" />
    </div>

    <x-table class="mb-6">
        <x-slot:head><x-th>Where it came from / went</x-th><x-th numeric>In</x-th><x-th numeric>Out</x-th><x-th numeric>Net</x-th></x-slot:head>
        <tr class="border-b border-line bg-surface-sunken"><x-td><span class="font-semibold text-content">Opening balance</span></x-td><x-td /><x-td /><x-td numeric>{{ $m($flow['openingBalance']) }}</x-td></tr>
        @if ($moneyIn !== [])
            <tr class="border-b border-line bg-surface-sunken"><x-td><span class="font-semibold text-content">Money in</span></x-td><x-td /><x-td /><x-td /></tr>
        @endif
        @foreach ($moneyIn as $line)
            <tr class="border-b border-line"><x-td><span class="pl-4">{{ $line['label'] }}</span></x-td><x-td numeric>{{ $m($line['inMinor']) }}</x-td><x-td numeric>{{ $line['outMinor'] > 0 ? $m($line['outMinor']) : '—' }}</x-td><x-td numeric>{{ $m($line['net']) }}</x-td></tr>
        @endforeach
        @if ($moneyOut !== [])
            <tr class="border-b border-line bg-surface-sunken"><x-td><span class="font-semibold text-content">Money out</span></x-td><x-td /><x-td /><x-td /></tr>
        @endif
        @foreach ($moneyOut as $line)
            <tr class="border-b border-line"><x-td><span class="pl-4">{{ $line['label'] }}</span></x-td><x-td numeric>{{ $line['inMinor'] > 0 ? $m($line['inMinor']) : '—' }}</x-td><x-td numeric>{{ $m($line['outMinor']) }}</x-td><x-td numeric>{{ $m($line['net']) }}</x-td></tr>
        @endforeach
        @if ($flow['lines'] === [])
            <tr class="border-b border-line"><x-td><span class="text-content-muted">No money moved in this period</span></x-td><x-td numeric>—</x-td><x-td numeric>—</x-td><x-td numeric>—</x-td></tr>
        @endif
        <tr class="bg-accent-soft text-base font-semibold"><x-td>Closing balance</x-td><x-td numeric>{{ $m($flow['totalIn']) }}</x-td><x-td numeric>{{ $m($flow['totalOut']) }}</x-td><x-td numeric>{{ $m($flow['closingBalance']) }}</x-td></tr>
    </x-table>

    <h2 class="mb-3 text-sm font-semibold text-content">By account</h2>
    <x-table>
        <x-slot:head><x-th>Account</x-th><x-th numeric>Opening</x-th><x-th numeric>In</x-th><x-th numeric>Out</x-th><x-th numeric>Closing</x-th></x-slot:head>
        @foreach ($flow['byAccount'] as $account)
            <tr class="border-b border-line last:border-0">
                <x-td><span class="font-medium text-content">{{ $account['name'] }}</span></x-td>
                <x-td numeric>{{ $m($account['opening']) }}</x-td>
                <x-td numeric>{{ $m($account['in']) }}</x-td>
                <x-td numeric>{{ $m($account['out']) }}</x-td>
                <x-td numeric><span @class(['font-medium', 'text-danger' => $account['closing'] < 0])>{{ $m($account['closing']) }}</span></x-td>
            </tr>
        @endforeach
    </x-table>

    <p class="mt-4 text-xs text-content-subtle">
        This is money actually moving, not profit. A sale on credit shows nothing here until the
        customer pays — which is exactly why a profitable shop can still run out of cash.
    </p>
</div>
