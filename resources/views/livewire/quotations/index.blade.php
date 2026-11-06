@php
    use App\View\Format;
    $f = $list['filters'];
@endphp
<div class="w-full">
    <x-page-header title="Quotations" description="Prices offered to customers. Nothing here has been sold.">
        <x-slot:actions>
            <x-csv-button list="quotations" :query="$list['carried']" />
            @can('quotations.create')
                <x-button size="sm" href="/quotations/new">New quote</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @php
        $quick = [
        ['label' => 'Open', 'params' => ['status' => 'OPEN'], 'match' => ['status' => 'OPEN']],
        ['label' => 'Ran out', 'params' => ['expired' => '1'], 'match' => ['expired' => '1']],
        ['label' => 'Became sales', 'params' => ['status' => 'CONVERTED'], 'match' => ['status' => 'CONVERTED']],
    ];
    @endphp

    <x-filter-bar :quick="$quick" :list="$list" :dates="true" :fields="[
        ['kind' => 'search', 'key' => 'q', 'label' => 'Search', 'placeholder' => 'Quote number, customer or job', 'wide' => true, 'value' => $f['search'] ?? ''],
        ['kind' => 'select', 'key' => 'customer', 'label' => 'Customer', 'allLabel' => 'Everyone', 'options' => collect($customers)->pluck('name', 'id')->all(), 'value' => $f['customerId'] ?? null],
    ]" />

    @if ($rows === [])
        <x-empty-state title="No quotes here" description="A quote is a price a customer can take away and think about. It sells nothing until they accept it." />
    @else
        <x-card class="mt-4">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-left text-xs text-content-muted">
                            <th class="pb-2 font-medium">Quote</th>
                            <th class="pb-2 font-medium">Customer</th>
                            <th class="pb-2 font-medium">Issued</th>
                            <th class="pb-2 font-medium">Valid until</th>
                            <th class="pb-2 text-right font-medium">Total</th>
                            <th class="pb-2 text-right font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $quote)
                            <tr class="border-b border-line" wire:key="quote-{{ $quote['id'] }}">
                                <td class="py-2.5"><a href="/quotations/{{ $quote['id'] }}" class="font-medium text-accent hover:underline">{{ $quote['quoteNo'] }}</a></td>
                                <td class="py-2.5 text-content">
                                    <span class="block">{{ $quote['customerName'] }}</span>
                                    @if ($quote['reference'])<span class="block text-xs text-content-muted">{{ $quote['reference'] }}</span>@endif
                                </td>
                                <td class="py-2.5 text-content-muted">@date($quote['businessDate'])</td>
                                <td @class(['py-2.5', 'font-medium text-warning' => $quote['expired'], 'text-content-muted' => ! $quote['expired']])>@date($quote['validUntil'])</td>
                                <td class="tabular py-2.5 text-right font-medium text-content">{{ Format::money((int) $quote['totalMinor'], currencyCode: $currency) }}</td>
                                <td class="py-2.5 text-right">
                                    @if ($quote['status'] === 'CONVERTED')
                                        <x-badge tone="success">Sold</x-badge>
                                    @elseif ($quote['status'] === 'CANCELLED')
                                        <x-badge>Cancelled</x-badge>
                                    @elseif ($quote['expired'])
                                        <x-badge tone="warning">Ran out</x-badge>
                                    @else
                                        <x-badge tone="accent">Open</x-badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :list="$list" :total="$total" noun="quote" noun-plural="quotes" />
        </x-card>
    @endif
</div>
