@php
    use App\Domain\Quantity;
    use App\Services\StockAdjustmentService;
    use App\View\Format;
    $label = StockAdjustmentService::REASON_LABELS[$adjustment->reason] ?? $adjustment->reason;
@endphp
<div class="w-full">
    <x-page-header :title="$adjustment->adjustment_no" :description="$label.' · '.Format::date($adjustment->business_date)">
        <x-slot:actions>
            @if ($canEdit)
                <x-button variant="secondary" size="sm" href="/inventory/adjustments/{{ $adjustment->id }}/edit">Edit</x-button>
            @endif
            <x-button variant="secondary" size="sm" href="/inventory/adjustments">Back</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($adjustment->status === 'VOIDED')
        <x-alert tone="warning" title="This adjustment was voided" class="mb-4">
            {{ $adjustment->void_reason }}@if ($adjustment->voided_at) — {{ Format::dateTime($adjustment->voided_at) }}@endif. The original record is kept exactly as it was recorded.
        </x-alert>
    @endif

    <x-card class="mb-4">
        <dl class="grid gap-4 sm:grid-cols-3">
            <div>
                <dt class="text-sm text-content-muted">Reason</dt>
                <dd class="mt-0.5 font-medium text-content">{{ $label }}</dd>
            </div>
            <div>
                <dt class="text-sm text-content-muted">Recorded</dt>
                <dd class="mt-0.5 font-medium text-content">{{ Format::dateTime($adjustment->occurred_at) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-content-muted">Status</dt>
                <dd class="mt-0.5">
                    @if ($adjustment->status === 'VOIDED')
                        <x-badge tone="danger">Voided</x-badge>
                    @else
                        <x-badge tone="success">Posted</x-badge>
                    @endif
                    @if ($adjustment->edited_at)
                        <x-badge tone="accent">Edited</x-badge>
                    @endif
                </dd>
            </div>
            @if ($adjustment->note)
                <div class="sm:col-span-3">
                    <dt class="text-sm text-content-muted">Note</dt>
                    <dd class="mt-0.5 text-content">{{ $adjustment->note }}</dd>
                </div>
            @endif
        </dl>
    </x-card>

    <h2 class="mb-3 text-sm font-semibold text-content">Stock items</h2>
    <x-table class="mb-6">
        <x-slot:head>
            <x-th>Stock item</x-th>
            <x-th>Direction</x-th>
            <x-th numeric>Quantity</x-th>
            <x-th numeric>Unit cost</x-th>
            <x-th numeric>Value</x-th>
        </x-slot:head>
        @foreach ($items as $item)
            <tr class="border-b border-line last:border-0" wire:key="item-{{ $item->id }}">
                <x-td><span class="font-medium text-content">{{ $item->product_name }}</span></x-td>
                <x-td><x-badge :tone="$item->direction === 'IN' ? 'success' : 'warning'">{{ $item->direction === 'IN' ? 'In' : 'Out' }}</x-badge></x-td>
                {{-- Unit cost here is per base unit, so the quantity leads in it too. --}}
                <x-td numeric>{{ Quantity::formatInBothUnits((int) $item->qty_milli, $item->unit, $item->entry_unit, $item->entry_factor_milli === null ? null : (int) $item->entry_factor_milli) }}</x-td>
                <x-td numeric>{{ Format::money((int) $item->unit_cost_minor, bare: true) }}</x-td>
                <x-td numeric>{{ Format::money((int) $item->total_cost_minor, bare: true) }}</x-td>
            </tr>
        @endforeach
    </x-table>

    <h2 class="mb-3 text-sm font-semibold text-content">Accounting entry</h2>
    @if ($journal === null)
        <x-card><p class="text-sm text-content-muted">No accounting entry was needed — every line moved stock that carried no value.</p></x-card>
    @else
        <x-table>
            <x-slot:head>
                <x-th>Account</x-th>
                <x-th numeric>Debit</x-th>
                <x-th numeric>Credit</x-th>
            </x-slot:head>
            @foreach ($journal['lines'] as $line)
                <tr class="border-b border-line last:border-0" wire:key="line-{{ $line['id'] }}">
                    <x-td><span class="text-content-subtle">{{ $line['accountCode'] }}</span> <span class="font-medium text-content">{{ $line['accountName'] }}</span></x-td>
                    <x-td numeric>{{ (int) $line['debit'] > 0 ? Format::money((int) $line['debit'], bare: true) : '—' }}</x-td>
                    <x-td numeric>{{ (int) $line['credit'] > 0 ? Format::money((int) $line['credit'], bare: true) : '—' }}</x-td>
                </tr>
            @endforeach
            <tr class="bg-surface-sunken font-semibold">
                <x-td>Total ({{ $journal['entry']['entryNo'] }})</x-td>
                <x-td numeric>{{ Format::money($journal['totalDebit'], bare: true) }}</x-td>
                <x-td numeric>{{ Format::money($journal['totalCredit'], bare: true) }}</x-td>
            </tr>
        </x-table>
        <p class="mt-2 text-xs text-content-subtle">
            {{ $journal['balanced'] ? 'Debits equal credits — this entry balances.' : 'WARNING: this entry does not balance. Please report it.' }}
        </p>
    @endif

    <x-edit-history :history="$history" />

    @if ($adjustment->status === 'POSTED' && Gate::allows('inventory.void'))
        <div class="mt-8">
            <x-confirm-dialog title="Void this adjustment"
                description="The original record is kept. A reversing adjustment and a reversing accounting entry are created, putting stock and the accounts back where they were."
                trigger="Void {{ $adjustment->adjustment_no }}…" heading="Void {{ $adjustment->adjustment_no }}?"
                note="This reverses the stock movement and the accounting entry. Nothing is deleted."
                confirm="Yes, void this adjustment" wire:submit="void">
                <x-field label="Reason" for="void-reason" name="reason" required>
                    <x-input id="void-reason" wire:model="reason" placeholder="e.g. Entered against the wrong stock item" required :invalid="$errors->has('reason')" />
                </x-field>
            </x-confirm-dialog>
        </div>
    @endif
</div>
