@php($href = \App\Livewire\Accounts\Index::href(...))
<div class="w-full">
    <x-page-header title="Transfers" description="Money moved between the shop's own accounts — cash banked, MoMo drawn into the till.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/accounts">All accounts</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($voided)
        <x-alert tone="success" class="mb-4">
            Transfer voided. The original record was kept and a reversing entry posted today, so
            both balances are back where they were.
        </x-alert>
    @endif

    @if ($rows === [])
        <x-empty-state title="No transfers yet" description="When you bank cash or move money between accounts, record it from the Accounts page so both balances stay right." />
    @else
        <x-table>
            <x-slot:head>
                <x-th>Date</x-th>
                <x-th>Transfer</x-th>
                <x-th>From</x-th>
                <x-th>To</x-th>
                <x-th numeric>Amount ({{ $currency }})</x-th>
                <x-th />
            </x-slot:head>
            @foreach ($rows as $row)
                <tr class="border-b border-line last:border-0" wire:key="transfer-{{ $row['id'] }}">
                    <x-td>@date($row['businessDate'])</x-td>
                    <x-td>
                        <span class="font-medium text-content">{{ $row['transferNo'] }}</span>
                        @if ($row['status'] === 'VOIDED')<x-badge tone="danger" class="ml-2">Voided</x-badge>@endif
                        @if ($row['reference'] || $row['note'])
                            <div class="mt-0.5 text-xs text-content-subtle">{{ implode(' · ', array_filter([$row['reference'], $row['note']])) }}</div>
                        @endif
                        @if ($row['status'] === 'VOIDED' && $row['voidReason'])
                            <div class="mt-0.5 text-xs text-content-subtle">Voided: {{ $row['voidReason'] }}</div>
                        @endif
                    </x-td>
                    <x-td><a href="{{ $href($row['fromAccountId']) }}" class="text-accent hover:underline">{{ $row['fromAccountName'] }}</a></x-td>
                    <x-td><a href="{{ $href($row['toAccountId']) }}" class="text-accent hover:underline">{{ $row['toAccountName'] }}</a></x-td>
                    <x-td numeric><span @class(['text-content-subtle line-through' => $row['status'] === 'VOIDED'])>{{ \App\View\Format::money($row['amount'], bare: true) }}</span></x-td>
                    <x-td>
                        <div class="flex justify-end">
                            @if ($canVoid && $row['status'] === 'POSTED')
                                @include('livewire.cashbook.row-void', ['id' => $row['id'], 'what' => $row['transferNo'], 'placeholder' => 'e.g. Wrong amount'])
                            @endif
                        </div>
                    </x-td>
                </tr>
            @endforeach
        </x-table>
        <x-pagination :list="$list" :total="$total" noun="transfer" />
    @endif
</div>
