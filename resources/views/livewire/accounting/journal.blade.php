<div class="w-full">
    <x-page-header title="Journal" description="Every accounting entry, newest first. Each one traces back to a real transaction.">
        <x-slot:actions><x-button variant="secondary" size="sm" href="/accounting">Back to accounting</x-button></x-slot:actions>
    </x-page-header>

    @if ($unbalanced !== [])
        <x-alert tone="danger" title="Some entries do not balance" class="mb-4">
            {{ implode(', ', array_column($unbalanced, 'entryNo')) }}. This should be impossible — please report it.
        </x-alert>
    @endif

    <x-filter-bar compact :list="$list" :fields="[
        ['kind' => 'date', 'key' => 'from', 'label' => 'From', 'value' => $from],
        ['kind' => 'date', 'key' => 'to', 'label' => 'To', 'value' => $to],
        ['kind' => 'select', 'key' => 'source', 'label' => 'Kind', 'allLabel' => 'Everything', 'options' => \App\Livewire\Accounting\Journal::KINDS, 'value' => $source],
    ]" />

    @if ($entries === [])
        <x-empty-state title="No entries in this period" description="Accounting entries are created automatically whenever you record a sale, purchase, payment or expense." />
    @else
        <x-table>
            <x-slot:head>
                <x-th>Entry</x-th>
                <x-th>Date</x-th>
                <x-th>Kind</x-th>
                <x-th>What it was</x-th>
                <x-th numeric>Lines</x-th>
                <x-th numeric>Amount</x-th>
                <x-th />
            </x-slot:head>
            @foreach ($entries as $entry)
                <tr class="border-b border-line last:border-0">
                    <x-td><a href="/accounting/journal/{{ $entry['id'] }}" class="font-medium text-accent hover:underline">{{ $entry['entryNo'] }}</a></x-td>
                    <x-td><span class="whitespace-nowrap text-content-muted">@date($entry['entryDate'])</span></x-td>
                    <x-td><x-badge :tone="$entry['reversesEntryId'] !== null ? 'neutral' : 'accent'">{{ \App\Livewire\Accounting\Journal::SOURCE_LABELS[$entry['sourceType']] ?? $entry['sourceType'] }}</x-badge></x-td>
                    <x-td><span class="text-content-muted">{{ $entry['memo'] ?? '—' }}</span></x-td>
                    <x-td numeric>{{ $entry['lineCount'] }}</x-td>
                    <x-td numeric>{{ \App\View\Format::money($entry['total'], bare: true) }}</x-td>
                    <x-td>
                        <div class="flex justify-end gap-1">
                            @if ($entry['isOpening'])<x-badge tone="neutral">Opening</x-badge>@endif
                            @if ($entry['reversedByEntryId'] !== null)<x-badge tone="danger">Reversed</x-badge>@endif
                            @unless ($entry['balanced'])<x-badge tone="danger">Unbalanced</x-badge>@endunless
                        </div>
                    </x-td>
                </tr>
            @endforeach
        </x-table>
        <x-pagination :list="$list" :total="$total" noun="entry" noun-plural="entries" />
    @endif

    <p class="mt-4 text-xs text-content-subtle">Showing @date($from) to @date($to). Nothing here can be edited — a mistake is corrected by posting a reversing entry, so both the error and the correction stay visible.</p>
</div>
