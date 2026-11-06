<div class="w-full">
    <x-page-header title="Chart of accounts" description="Every account the books are kept in. Balances are calculated from the ledger.">
        <x-slot:actions><x-button variant="secondary" size="sm" href="/accounting">Back to accounting</x-button></x-slot:actions>
    </x-page-header>

    <x-table>
        <x-slot:head>
            <x-th>Code</x-th>
            <x-th>Account</x-th>
            <x-th>Type</x-th>
            <x-th numeric>Entries</x-th>
            <x-th numeric>Balance</x-th>
            <x-th />
        </x-slot:head>
        @foreach ($chart as $account)
            <tr @class(['border-b border-line last:border-0', 'bg-surface-sunken' => $account['isHeader']])>
                <x-td><span class="tabular text-xs text-content-subtle">{{ $account['code'] }}</span></x-td>
                <x-td>
                    <span @class(['font-semibold text-content' => $account['isHeader'], 'text-content' => ! $account['isHeader']]) style="padding-left: {{ $account['depth'] * 1.25 }}rem">{{ $account['name'] }}</span>
                    @unless ($account['isActive'])<x-badge tone="neutral" class="ml-2">Archived</x-badge>@endunless
                </x-td>
                <x-td><x-badge :tone="\App\Livewire\Accounting\Chart::TYPE_TONES[$account['type']] ?? 'neutral'">{{ \App\Livewire\Accounting\Chart::TYPE_LABELS[$account['type']] ?? $account['type'] }}</x-badge></x-td>
                <x-td numeric>
                    @if ($account['isHeader'])<span class="text-content-subtle">—</span>@else{{ $account['entryCount'] }}@endif
                </x-td>
                <x-td numeric><span @class(['font-semibold' => $account['isHeader']])>{{ \App\View\Format::money($account['isHeader'] ? $account['rollup'] : $account['balance'], bare: true) }}</span></x-td>
                <x-td>
                    <div class="flex justify-end">
                        @if (! $account['isHeader'] && $account['entryCount'] > 0)
                            <a href="/accounting/ledger/{{ $account['id'] }}" class="text-xs font-medium text-accent hover:underline">Ledger</a>
                        @endif
                    </div>
                </x-td>
            </tr>
        @endforeach
    </x-table>

    <p class="mt-4 text-xs text-content-subtle">Headings show the total of everything beneath them. Open an account’s ledger to see the individual entries that produced its balance.</p>
</div>
