<div class="w-full">
    @php($m = fn (int $v): string => \App\View\Format::money($v, bare: true))
    <x-page-header title="Balance sheet" :description="'As at '.\App\View\Format::date($asAt)">
        <x-slot:actions>@include('livewire.reports.actions', ['report' => 'balance-sheet', 'query' => ['asAt' => $asAt]])</x-slot:actions>
    </x-page-header>

    @unless ($sheet['balances'])
        <x-alert tone="danger" title="This balance sheet does not balance" class="mb-4">
            Assets @money($sheet['totalAssets']) do not equal liabilities plus equity @money($sheet['totalLiabilitiesAndEquity']). Difference @money($sheet['difference']). This should be impossible — please report it.
        </x-alert>
    @endunless

    <x-filter-bar :list="['carried' => []]" :fields="[['kind' => 'date', 'key' => 'asAt', 'label' => 'As at', 'value' => $asAt]]" />

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <x-stat icon="accounts" label="What you own" :value="\App\View\Format::money($sheet['totalAssets'])" />
        <x-stat icon="owes" label="What you owe" :value="\App\View\Format::money($sheet['totalLiabilities'])" />
        <x-stat icon="books" label="Business worth" :value="\App\View\Format::money($sheet['totalEquity'])" :tone="$sheet['totalEquity'] < 0 ? 'danger' : 'success'" />
    </div>

    <div class="mb-2 text-sm font-semibold text-content">{{ $businessName }} — Balance sheet as at @date($asAt)</div>

    <x-table>
        <x-slot:head><x-th>Item</x-th><x-th numeric>Amount</x-th></x-slot:head>
        {{-- Dual headings: the plain wording for the owner, the formal term for the accountant. --}}
        <tr class="border-b border-line bg-surface-sunken"><x-td><span class="font-semibold text-content">What the business owns</span><span class="ml-2 text-xs font-normal text-content-subtle">(Assets)</span></x-td><x-td /></tr>
        @foreach ($sheet['cashAccounts'] as $line)
            <tr class="border-b border-line"><x-td><span class="pl-4">{{ $line['name'] }}</span></x-td><x-td numeric>{{ $m($line['amount']) }}</x-td></tr>
        @endforeach
        <tr class="border-b border-line"><x-td><span class="pl-4">Money customers owe you</span></x-td><x-td numeric>{{ $m($sheet['receivables']) }}</x-td></tr>
        @if ($sheet['supplierAdvances'] !== 0)
            <tr class="border-b border-line"><x-td><span class="pl-4">Paid to suppliers in advance</span></x-td><x-td numeric>{{ $m($sheet['supplierAdvances']) }}</x-td></tr>
        @endif
        <tr class="border-b border-line"><x-td><span class="pl-4">Stock on the shelf</span></x-td><x-td numeric>{{ $m($sheet['inventory']) }}</x-td></tr>
        @foreach ($sheet['otherAssets'] as $line)
            <tr class="border-b border-line"><x-td><span class="pl-4">{{ $line['name'] }}</span></x-td><x-td numeric>{{ $m($line['amount']) }}</x-td></tr>
        @endforeach
        <tr class="border-b border-line font-semibold"><x-td>Total assets</x-td><x-td numeric>{{ $m($sheet['totalAssets']) }}</x-td></tr>

        <tr class="border-b border-line bg-surface-sunken"><x-td><span class="font-semibold text-content">What the business owes</span><span class="ml-2 text-xs font-normal text-content-subtle">(Liabilities)</span></x-td><x-td /></tr>
        <tr class="border-b border-line"><x-td><span class="pl-4">Money you owe suppliers</span></x-td><x-td numeric>{{ $m($sheet['payables']) }}</x-td></tr>
        @if ($sheet['customerCredits'] !== 0)
            <tr class="border-b border-line"><x-td><span class="pl-4">Customer credit balances</span></x-td><x-td numeric>{{ $m($sheet['customerCredits']) }}</x-td></tr>
        @endif
        @if ($sheet['taxPayable'] !== 0)
            <tr class="border-b border-line"><x-td><span class="pl-4">Tax not yet paid</span></x-td><x-td numeric>{{ $m($sheet['taxPayable']) }}</x-td></tr>
        @endif
        @foreach ($sheet['otherLiabilities'] as $line)
            <tr class="border-b border-line"><x-td><span class="pl-4">{{ $line['name'] }}</span></x-td><x-td numeric>{{ $m($line['amount']) }}</x-td></tr>
        @endforeach
        <tr class="border-b border-line font-semibold"><x-td>Total liabilities</x-td><x-td numeric>{{ $m($sheet['totalLiabilities']) }}</x-td></tr>

        <tr class="border-b border-line bg-surface-sunken"><x-td><span class="font-semibold text-content">Your stake in the business</span><span class="ml-2 text-xs font-normal text-content-subtle">(Equity)</span></x-td><x-td /></tr>
        <tr class="border-b border-line"><x-td><span class="pl-4">Money you put in</span></x-td><x-td numeric>{{ $m($sheet['ownersCapital']) }}</x-td></tr>
        @if ($sheet['drawings'] !== 0)
            <tr class="border-b border-line"><x-td><span class="pl-4 text-content-muted">Less: money you took out</span></x-td><x-td numeric>({{ $m($sheet['drawings']) }})</x-td></tr>
        @endif
        @if ($sheet['openingBalanceEquity'] !== 0)
            <tr class="border-b border-line"><x-td><span class="pl-4">Opening balances</span></x-td><x-td numeric>{{ $m($sheet['openingBalanceEquity']) }}</x-td></tr>
        @endif
        <tr class="border-b border-line"><x-td><span class="pl-4">Profit kept in the business</span></x-td><x-td numeric><span @class(['text-danger' => $sheet['retainedEarnings'] < 0])>{{ $m($sheet['retainedEarnings']) }}</span></x-td></tr>
        <tr class="border-b border-line font-semibold"><x-td>Total your stake</x-td><x-td numeric>{{ $m($sheet['totalEquity']) }}</x-td></tr>
        <tr class="bg-accent-soft text-base font-semibold"><x-td>Liabilities + your stake</x-td><x-td numeric>{{ $m($sheet['totalLiabilitiesAndEquity']) }}</x-td></tr>
    </x-table>

    <p class="mt-4 text-xs text-content-subtle">
        {{ $sheet['balances'] ? 'What the business owns exactly equals what it owes plus your stake in it. That is the check that the books are sound.' : 'WARNING: this statement does not balance.' }}
        Profit is inside your stake whether or not the year has been closed, so this statement is
        correct either way. Closing the year moves it into Retained Earnings and locks the period
        against later entries; it does not change what the business is worth.
    </p>
</div>
