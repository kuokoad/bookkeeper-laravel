<div class="w-full">
    <x-page-header :title="$side['title']" :description="$side['description']">
        <x-slot:actions>
            <x-csv-button :report="$side['report']" :query="['to' => $asAt]" />
            <x-button variant="secondary" size="sm" href="/accounting">Back to accounting</x-button>
        </x-slot:actions>
    </x-page-header>

    @unless ($agrees)
        <x-alert tone="danger" title="This report does not agree with the ledger" class="mb-4">
            The ageing adds to @money($ageingTotal), {{ $side['balancesWord'] }} balances add to @money($subledgerTotal), and the {{ $side['controlName'] }} account holds @money($controlTotal). These three must match. Please report this.
        </x-alert>
    @endunless

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <x-stat :icon="$side['totalIcon']" :label="$filtered ? 'Owed, as filtered' : $side['totalLabel']" :value="\App\View\Format::money($totals['total'])"
            :tone="$totals['total'] > 0 ? 'warning' : 'default'" :hint="$filtered ? 'of '.\App\View\Format::money($ageingTotal).' in total' : null" />
        <x-stat :icon="$side['countIcon']" :label="$side['countLabel']" :value="(string) count($rows)" />
        <x-stat icon="warning" label="Over 90 days" :value="\App\View\Format::money($totals['over90'])" :tone="$totals['over90'] > 0 ? 'danger' : 'default'"
            :hint="$totals['over90'] > 0 ? $side['overdueHint'] : 'Nothing long overdue'" />
    </div>

    <x-filter-bar :list="$list" :fields="[
        ['kind' => 'select', 'key' => $side['key'], 'label' => $side['partyLabel'], 'allLabel' => $side['allLabel'], 'options' => array_column($parties, 'name', 'id'), 'value' => $partyId],
        ['kind' => 'select', 'key' => 'status', 'label' => 'Outstanding', 'allLabel' => 'Any age', 'options' => ['overdue' => 'Over 90 days', 'current' => 'Within 90 days'], 'value' => $status],
        ['kind' => 'date', 'key' => 'asAt', 'label' => 'As at', 'value' => $asAt],
    ]" />

    @if ($rows === [])
        <x-empty-state :title="$side['emptyTitle']" :description="$side['emptyDescription']" />
    @else
        @php($cell = fn (int $v): string => $v > 0 ? \App\View\Format::money($v, bare: true) : '—')
        <x-table>
            <x-slot:head>
                <x-th>{{ $side['partyLabel'] }}</x-th>
                <x-th>{{ $side['dateHeading'] }}</x-th>
                <x-th numeric>Not due</x-th>
                <x-th numeric>1–30 days</x-th>
                <x-th numeric>31–60</x-th>
                <x-th numeric>61–90</x-th>
                <x-th numeric>Over 90</x-th>
                <x-th numeric>Total</x-th>
            </x-slot:head>
            @foreach ($rows as $row)
                <tr class="border-b border-line last:border-0">
                    <x-td>
                        <a href="{{ $side['hrefBase'] }}/{{ $row['partyId'] }}" class="font-medium text-accent hover:underline">{{ $row['partyName'] }}</a>
                        @if ($row['phone'])<span class="mt-0.5 block text-xs text-content-subtle">{{ $row['phone'] }}</span>@endif
                    </x-td>
                    <x-td><span class="whitespace-nowrap text-content-muted">{{ $row['oldestDate'] ? \App\View\Format::date($row['oldestDate']) : '—' }}</span></x-td>
                    <x-td numeric>{{ $cell($row['current']) }}</x-td>
                    <x-td numeric>{{ $cell($row['days1to30']) }}</x-td>
                    <x-td numeric>{{ $cell($row['days31to60']) }}</x-td>
                    <x-td numeric>{{ $cell($row['days61to90']) }}</x-td>
                    <x-td numeric>
                        @if ($row['over90'] > 0)<span class="font-medium text-danger">{{ \App\View\Format::money($row['over90'], bare: true) }}</span>@else—@endif
                    </x-td>
                    <x-td numeric><span class="font-semibold">{{ \App\View\Format::money($row['total'], bare: true) }}</span></x-td>
                </tr>
            @endforeach
            <tr class="bg-surface-sunken font-semibold">
                <x-td>Total</x-td>
                <x-td />
                @foreach (['current', 'days1to30', 'days31to60', 'days61to90', 'over90', 'total'] as $key)
                    <x-td numeric>{{ \App\View\Format::money($totals[$key], bare: true) }}</x-td>
                @endforeach
            </tr>
        </x-table>
    @endif

    <p class="mt-4 text-xs text-content-subtle">As at @date($asAt). {{ $side['footnote'] }}</p>
</div>
