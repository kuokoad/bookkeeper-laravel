<div class="w-full">
    @php($m = fn (int $v): string => \App\View\Format::money($v, bare: true))
    @php($docs = fn (int $n): string => $n === 1 ? '' : 's')
    <x-page-header title="Tax return" :description="'What you owe, '.mb_strtolower(\App\Livewire\Reports\Report::describePeriod($list['range'], $list['preset']))">
        <x-slot:actions>@include('livewire.reports.actions', ['report' => 'tax', 'query' => \App\Livewire\Reports\Report::csvQuery($list)])</x-slot:actions>
    </x-page-header>

    <x-filter-bar :list="$list" :dates="true" />

    @unless ($enabled)
        <x-alert tone="info" title="Tax is switched off" class="mb-4">
            This shop is not charging tax, so there is nothing to return. Turn it on in Settings if
            the shop is registered.
        </x-alert>
    @endunless

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat icon="owes" label="Tax you charged" :value="\App\View\Format::money($tax['totalOutput'])" hint="On sales, less returns" />
        <x-stat icon="owed" label="Tax you can reclaim" :value="\App\View\Format::money($tax['totalRecoverableInput'])" hint="On purchases, less returns" />
        {{-- Nothing owed is its own answer, and neither "you owe" nor "the authority owes you" describes it. --}}
        <x-stat :icon="$owed ? 'owes' : ($reclaimable ? 'owed' : null)" :label="$owed ? 'You owe' : ($reclaimable ? 'Reclaimable' : 'Nothing owed')"
            :value="\App\View\Format::money($netUnsigned)" :tone="$owed ? 'warning' : ($reclaimable ? 'success' : 'default')"
            :hint="$owed ? 'Pay this to the authority' : ($reclaimable ? 'The authority owes you this' : 'Charged and reclaimable cancel out')" />
        <x-stat icon="sales" label="Sales it was charged on" :value="\App\View\Format::money($tax['taxableSalesMinor'])" :hint="$tax['saleCount'].' document'.$docs($tax['saleCount'])" />
    </div>

    @if ($tax['components'] === [])
        <x-alert tone="info" title="Nothing to report for this period">No sale or purchase in this period carried tax.</x-alert>
    @else
        <h2 class="mb-3 text-sm font-semibold text-content">Each tax, separately</h2>
        <x-table class="mb-4">
            <x-slot:head><x-th>Tax</x-th><x-th numeric>Charged on sales</x-th><x-th numeric>Paid on purchases</x-th><x-th numeric>Reclaimable</x-th><x-th numeric>Net</x-th></x-slot:head>
            @foreach ($tax['components'] as $i => $component)
                <tr class="border-b border-line">
                    <x-td><span class="font-medium text-content">{{ $component['name'] }}</span></x-td>
                    <x-td numeric>{{ $m($component['outputMinor']) }}</x-td>
                    <x-td numeric>{{ $m($paid[$i]) }}</x-td>
                    <x-td numeric>
                        {{ $m($component['recoverableInputMinor']) }}
                        @if ($component['nonRecoverableInputMinor'] !== 0)
                            <span class="block text-xs text-content-subtle">{{ $m($component['nonRecoverableInputMinor']) }} not reclaimable</span>
                        @endif
                    </x-td>
                    <x-td numeric>
                        <span @class(['font-medium text-warning' => $component['netMinor'] > 0, 'font-medium text-success' => $component['netMinor'] < 0, 'text-content' => $component['netMinor'] === 0])>{{ $m($component['netMinor']) }}</span>
                    </x-td>
                </tr>
            @endforeach
            <tr>
                <x-td><span class="font-semibold text-content">Total</span></x-td>
                <x-td numeric><span class="font-semibold">{{ $m($tax['totalOutput']) }}</span></x-td>
                <x-td numeric><span class="font-semibold">{{ $m($totalPaid) }}</span></x-td>
                <x-td numeric><span class="font-semibold">{{ $m($tax['totalRecoverableInput']) }}</span></x-td>
                <x-td numeric><span class="font-semibold">{{ $m($tax['netPayable']) }}</span></x-td>
            </tr>
        </x-table>

        @if ($tax['totalNonRecoverableInput'] !== 0)
            <p class="mb-6 text-xs text-content-muted">
                Tax marked &ldquo;not reclaimable&rdquo; was paid to suppliers but cannot be claimed
                back. It went into the cost of the goods and out again through cost of sales, so it
                is already in your profit figure and must not be entered on the return.
            </p>
        @endif

        <x-alert :tone="$owed ? 'warning' : 'info'" title="Before you file this">
            <p>
                These figures come from {{ $tax['saleCount'] }} sales document{{ $docs($tax['saleCount']) }} and {{ $tax['purchaseCount'] }} purchase document{{ $docs($tax['purchaseCount']) }} dated inside the period, cancellations
                included. A sale cancelled after a return was filed appears in the period it was
                cancelled, not the one it was made — which is how it should be, and means an earlier
                period will not change under you.
            </p>
            <p class="mt-2">
                Check them against your own records before filing. This is a summary of what this
                shop recorded, not tax advice.
            </p>
        </x-alert>
    @endif
</div>
