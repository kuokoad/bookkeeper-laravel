<div class="w-full">
    <x-page-header :title="$entry['entryNo']" :description="\App\View\Format::date($entry['entryDate']).' · '.$kind">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/accounting/journal">All entries</x-button>
            @if ($href)<x-button size="sm" :href="$href">View the transaction</x-button>@endif
        </x-slot:actions>
    </x-page-header>

    @unless ($balanced)
        <x-alert tone="danger" title="This entry does not balance" class="mb-4">
            Debits @money($totalDebit) do not equal credits @money($totalCredit). This should be impossible — please report it.
        </x-alert>
    @endunless

    @if ($entry['reversedByEntryId'] !== null)
        <x-alert tone="warning" class="mb-4">This entry was reversed. <a href="/accounting/journal/{{ $entry['reversedByEntryId'] }}" class="text-accent hover:underline">See the reversing entry</a>.</x-alert>
    @endif
    @if ($entry['reversesEntryId'] !== null)
        <x-alert tone="info" class="mb-4">This entry reverses an earlier one. <a href="/accounting/journal/{{ $entry['reversesEntryId'] }}" class="text-accent hover:underline">See the original</a>.</x-alert>
    @endif

    <x-card class="mb-4">
        <dl class="grid gap-4 sm:grid-cols-3">
            <div>
                <dt class="text-sm text-content-muted">Recorded</dt>
                <dd class="mt-0.5 text-content">{{ \App\View\Format::dateTime($entry['occurredAt']) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-content-muted">Business date</dt>
                <dd class="mt-0.5 text-content">@date($entry['entryDate'])</dd>
            </div>
            <div>
                <dt class="text-sm text-content-muted">Traces to</dt>
                <dd class="mt-0.5 text-content">{{ $kind }}{{ $entry['sourceId'] !== null ? ' #'.$entry['sourceId'] : '' }}</dd>
            </div>
            @if ($entry['memo'])
                <div class="sm:col-span-3">
                    <dt class="text-sm text-content-muted">Memo</dt>
                    <dd class="mt-0.5 text-content">{{ $entry['memo'] }}</dd>
                </div>
            @endif
        </dl>
    </x-card>

    <x-table>
        <x-slot:head>
            <x-th>Account</x-th>
            <x-th>Detail</x-th>
            <x-th numeric>Debit</x-th>
            <x-th numeric>Credit</x-th>
        </x-slot:head>
        @foreach ($lines as $line)
            <tr class="border-b border-line last:border-0">
                <x-td>
                    <a href="/accounting/ledger/{{ $line['accountId'] }}" class="font-medium text-accent hover:underline">{{ $line['accountName'] }}</a>
                    <span class="ml-2 tabular text-xs text-content-subtle">{{ $line['accountCode'] }}</span>
                </x-td>
                <x-td>
                    <span class="text-content-muted">{{ $line['description'] ?? '—' }}</span>
                    @if ($line['customerName'])<x-badge tone="accent" class="ml-2">{{ $line['customerName'] }}</x-badge>@endif
                    @if ($line['supplierName'])<x-badge tone="warning" class="ml-2">{{ $line['supplierName'] }}</x-badge>@endif
                </x-td>
                <x-td numeric>{{ (int) $line['debit'] > 0 ? \App\View\Format::money((int) $line['debit'], bare: true) : '—' }}</x-td>
                <x-td numeric>{{ (int) $line['credit'] > 0 ? \App\View\Format::money((int) $line['credit'], bare: true) : '—' }}</x-td>
            </tr>
        @endforeach
        <tr class="bg-surface-sunken font-semibold">
            <x-td>Total</x-td>
            <x-td />
            <x-td numeric>{{ \App\View\Format::money($totalDebit, bare: true) }}</x-td>
            <x-td numeric>{{ \App\View\Format::money($totalCredit, bare: true) }}</x-td>
        </tr>
    </x-table>

    <p class="mt-3 text-xs text-content-subtle">{{ $balanced ? 'Debits equal credits — this entry balances.' : 'WARNING: this entry does not balance.' }}</p>
</div>
