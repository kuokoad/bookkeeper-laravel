<div class="w-full">
    <x-page-header title="Stock adjustments" description="Damage, loss, opening stock and count corrections.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/inventory">Back to inventory</x-button>
            @can('inventory.create')
                <x-button size="sm" href="/inventory/adjustments/new">New adjustment</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($adjustments === [])
        <x-empty-state title="No adjustments yet" description="Use an adjustment to enter opening stock, or to record damage, loss or a count correction.">
            @can('inventory.create')
                <x-slot:action><x-button href="/inventory/adjustments/new">Record one</x-button></x-slot:action>
            @endcan
        </x-empty-state>
    @else
        <x-table>
            <x-slot:head>
                <x-th>Reference</x-th>
                <x-th>Date</x-th>
                <x-th>Reason</x-th>
                <x-th>Note</x-th>
                <x-th>Status</x-th>
                <x-th />
            </x-slot:head>
            @foreach ($adjustments as $adjustment)
                <tr class="border-b border-line last:border-0" wire:key="adj-{{ $adjustment->id }}">
                    <x-td><span class="font-medium text-content">{{ $adjustment->adjustment_no }}</span></x-td>
                    <x-td><span class="whitespace-nowrap text-content-muted">@date($adjustment->business_date)</span></x-td>
                    <x-td>{{ \App\Services\StockAdjustmentService::REASON_LABELS[$adjustment->reason] ?? $adjustment->reason }}</x-td>
                    <x-td><span class="text-content-muted">{{ $adjustment->note ?? '—' }}</span></x-td>
                    <x-td>
                        @if ($adjustment->status === 'VOIDED')
                            <x-badge tone="danger">Voided</x-badge>
                        @else
                            <x-badge tone="success">Posted</x-badge>
                        @endif
                    </x-td>
                    <x-td><div class="flex justify-end"><a href="/inventory/adjustments/{{ $adjustment->id }}" class="text-xs font-medium text-accent hover:underline">View</a></div></x-td>
                </tr>
            @endforeach
        </x-table>
    @endif

    <p class="mt-4 text-xs text-content-subtle">
        Adjustments are never deleted. Voiding one keeps the original and posts a reversing entry, so the history shows both what was recorded and that it was corrected.
    </p>
</div>
