<div class="w-full">
    <x-page-header title="Trial balance" description="Every account with movement, and the proof that debits equal credits.">
        <x-slot:actions><x-button variant="secondary" size="sm" href="/accounting">Back</x-button></x-slot:actions>
    </x-page-header>

    @if ($balanced)
        <x-alert tone="success" class="mb-4 no-print">The books balance. Total debits equal total credits exactly.</x-alert>
    @else
        <x-alert tone="danger" title="The books do not balance" class="mb-4">
            Debits @money($totalDebit) do not equal credits @money($totalCredit). Difference @money($difference). Please report this.
        </x-alert>
    @endif

    <x-filter-bar :list="$list" :fields="[
        ['kind' => 'date', 'key' => 'from', 'label' => 'From (optional)', 'value' => $from ?? ''],
        ['kind' => 'date', 'key' => 'to', 'label' => 'As at', 'value' => $to],
    ]" />

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <x-stat icon="books" label="Total debits" :value="\App\View\Format::money($totalDebit)" />
        <x-stat icon="books" label="Total credits" :value="\App\View\Format::money($totalCredit)" />
        <x-stat :icon="$balanced ? 'books' : 'warning'" label="Status" :value="$balanced ? 'Balanced' : 'Out of balance'" :tone="$balanced ? 'success' : 'danger'" />
    </div>

    <x-table>
        <x-slot:head>
            <x-th>Code</x-th>
            <x-th>Account</x-th>
            <x-th numeric>Debit</x-th>
            <x-th numeric>Credit</x-th>
        </x-slot:head>
        @foreach ($rows as $row)
            <tr class="border-b border-line last:border-0">
                <x-td><span class="tabular text-xs text-content-subtle">{{ $row['code'] }}</span></x-td>
                <x-td><a href="/accounting/ledger/{{ $row['id'] }}" class="text-accent hover:underline">{{ $row['name'] }}</a></x-td>
                <x-td numeric>{{ $row['totalDebit'] > 0 ? \App\View\Format::money($row['totalDebit'], bare: true) : '—' }}</x-td>
                <x-td numeric>{{ $row['totalCredit'] > 0 ? \App\View\Format::money($row['totalCredit'], bare: true) : '—' }}</x-td>
            </tr>
        @endforeach
        <tr class="bg-surface-sunken font-semibold">
            <x-td />
            <x-td>Total</x-td>
            <x-td numeric>{{ \App\View\Format::money($totalDebit, bare: true) }}</x-td>
            <x-td numeric>{{ \App\View\Format::money($totalCredit, bare: true) }}</x-td>
        </tr>
    </x-table>

    <p class="mt-4 text-xs text-content-subtle">{{ $from ? \App\View\Format::date($from).' to '.\App\View\Format::date($to) : 'Everything up to '.\App\View\Format::date($to) }}. This lists the raw debit and credit totals per account — the check that the double entry holds. For what the shop is worth, see the balance sheet.</p>
</div>
