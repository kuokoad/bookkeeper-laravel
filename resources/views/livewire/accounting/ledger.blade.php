<div class="w-full">
    <x-page-header :title="$account['name']" :description="'Account '.$account['code'].' · '.strtolower(\Illuminate\Support\Str::replaceFirst('_', ' ', $account['type']))">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/accounting/chart">Chart of accounts</x-button>
            <x-button variant="secondary" size="sm" :href="'/accounting/journal?account='.$account['id']">Journal</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 grid gap-3 sm:grid-cols-4">
        <x-stat icon="accounts" label="Opening" :value="\App\View\Format::money($openingBalance, bare: true)" />
        <x-stat icon="books" label="Debits" :value="\App\View\Format::money($totalDebit, bare: true)" />
        <x-stat icon="books" label="Credits" :value="\App\View\Format::money($totalCredit, bare: true)" />
        <x-stat icon="accounts" label="Balance" :value="\App\View\Format::money($closingBalance)" :tone="$closingBalance < 0 ? 'danger' : 'default'" />
    </div>

    <x-filter-bar compact :list="$list" :fields="[
        ['kind' => 'date', 'key' => 'from', 'label' => 'From', 'value' => $from ?? ''],
        ['kind' => 'date', 'key' => 'to', 'label' => 'To', 'value' => $toShown],
    ]" />

    @if ($lines === [])
        <x-empty-state title="Nothing posted to this account" description="Entries appear here as soon as a transaction touches this account." />
    @else
        <x-table>
            <x-slot:head>
                <x-th>Date</x-th>
                <x-th>Entry</x-th>
                <x-th>Kind</x-th>
                <x-th>Detail</x-th>
                <x-th numeric>Debit</x-th>
                <x-th numeric>Credit</x-th>
                <x-th numeric>Balance</x-th>
            </x-slot:head>
            @if ($from)
                <tr class="border-b border-line bg-surface-sunken">
                    <x-td><span class="text-content-muted">@date($from)</span></x-td>
                    <x-td /><x-td />
                    <x-td><span class="italic text-content-muted">Opening balance</span></x-td>
                    <x-td /><x-td />
                    <x-td numeric>{{ \App\View\Format::money($openingBalance, bare: true) }}</x-td>
                </tr>
            @endif
            @foreach ($lines as $line)
                <tr class="border-b border-line last:border-0">
                    <x-td><span class="whitespace-nowrap text-content-muted">@date($line['entryDate'])</span></x-td>
                    <x-td><a href="/accounting/journal/{{ $line['entryId'] }}" class="text-xs font-medium text-accent hover:underline">{{ $line['entryNo'] }}</a></x-td>
                    <x-td><x-badge tone="neutral">{{ \App\Livewire\Accounting\Journal::SOURCE_LABELS[$line['sourceType']] ?? $line['sourceType'] }}</x-badge></x-td>
                    <x-td><span class="text-content-muted">{{ $line['description'] ?? $line['memo'] ?? '—' }}</span></x-td>
                    <x-td numeric>{{ $line['debit'] > 0 ? \App\View\Format::money($line['debit'], bare: true) : '—' }}</x-td>
                    <x-td numeric>{{ $line['credit'] > 0 ? \App\View\Format::money($line['credit'], bare: true) : '—' }}</x-td>
                    <x-td numeric><span class="font-medium">{{ \App\View\Format::money($line['runningBalance'], bare: true) }}</span></x-td>
                </tr>
            @endforeach
            <tr class="bg-surface-sunken font-semibold">
                <x-td>Closing</x-td>
                <x-td /><x-td /><x-td />
                <x-td numeric>{{ \App\View\Format::money($totalDebit, bare: true) }}</x-td>
                <x-td numeric>{{ \App\View\Format::money($totalCredit, bare: true) }}</x-td>
                <x-td numeric>{{ \App\View\Format::money($closingBalance, bare: true) }}</x-td>
            </tr>
        </x-table>
    @endif

    <p class="mt-4 text-xs text-content-subtle">The running balance is shown the way this kind of account reads — for cash and stock a debit increases it; for what you owe and what you earn a credit does. Click any entry to see the full double entry behind it.</p>
</div>
