@php
    use App\Livewire\Inventory\Batch;
    use App\View\Format;
@endphp
<div class="w-full">
    <x-page-header :title="$batch['batchRef']" :description="$batch['supplierName'] === null ? $batch['productName'] : $batch['productName'].' · from '.$batch['supplierName']">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/products/{{ $batch['productId'] }}/edit">Open the stock item</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($expired)
        <x-alert tone="danger" title="This stock has passed its date" class="mb-4">
            It expired @date($batch['expiryDate']), {{ abs($daysLeft) }} {{ Format::plural(abs($daysLeft), 'day') }} ago. It cannot be sold without approval. Record a stock adjustment with the reason &ldquo;Expired&rdquo; to take it off the shelf and out of the accounts.
        </x-alert>
    @endif

    <x-card title="This batch" class="mb-4">
        <dl class="grid gap-4 sm:grid-cols-4">
            <div>
                <dt class="text-xs text-content-muted">Still on the shelf</dt>
                <dd class="tabular text-lg font-semibold text-content">{{ Format::qty($batch['qtyMilli'], $batch['unit']) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-content-muted">Arrived with</dt>
                <dd class="tabular text-lg font-semibold text-content">{{ Format::qty($arrivedWith, $batch['unit']) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-content-muted">Expires</dt>
                <dd class="text-lg font-semibold text-content">
                    @if ($batch['expiryDate'] === null)
                        <span class="text-content-muted">No date recorded</span>
                    @else
                        @date($batch['expiryDate'])
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs text-content-muted">Status</dt>
                <dd class="text-lg font-semibold">
                    @if ($expired)
                        <x-badge tone="danger">Expired</x-badge>
                    @elseif ($batch['isClosed'])
                        <x-badge tone="neutral">Empty</x-badge>
                    @elseif ($daysLeft !== null && $daysLeft <= 30)
                        <x-badge tone="warning">{{ $daysLeft }} {{ Format::plural($daysLeft, 'day') }} left</x-badge>
                    @else
                        <x-badge tone="success">On the shelf</x-badge>
                    @endif
                </dd>
            </div>
        </dl>

        @if ($batch['receivedDate'] !== null)
            <p class="mt-3 border-t border-line pt-3 text-xs text-content-muted">
                Received @date($batch['receivedDate'])@if ($batch['note'] !== null) · {{ $batch['note'] }}@endif
            </p>
        @endif

        @can('inventory.edit')
            <form wire:submit="saveExpiry" class="mt-3 flex flex-wrap items-end gap-3 border-t border-line pt-3" novalidate>
                <div class="w-48">
                    <label for="expiryDate" class="mb-1 block text-xs font-medium text-content-muted">{{ $batch['expiryDate'] === null ? 'Set a date' : 'Change the date' }}</label>
                    <x-date-input id="expiryDate" wire:model="expiryDate" :value="$expiryDate" :invalid="$errors->has('expiryDate')" />
                </div>
                <x-button type="submit" variant="secondary" size="sm" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="saveExpiry">Save</span>
                    <span wire:loading wire:target="saveExpiry">Saving…</span>
                </x-button>
                <p class="w-full text-xs text-content-muted">
                    {{ $batch['expiryDate'] === null
                        ? "{$batch['batchRef']} has no date, so nothing will ever warn about it. Stock already on the shelf when this shop started recording batches arrives here."
                        : 'Changing this changes which stock the till reaches for first, and whether it refuses a sale. It is recorded in the audit log.' }}
                </p>
                @error('form')<x-alert tone="danger" class="w-full">{{ $message }}</x-alert>@enderror
                @error('expiryDate')<p class="w-full text-xs font-medium text-danger">{{ $message }}</p>@enderror
            </form>
        @endcan
    </x-card>

    <x-card title="Everywhere it went">
        @if ($entries === [])
            <p class="text-sm text-content-muted">Nothing has moved through this batch yet. Stock that was already on the shelf when batches were first recorded has no history before that day.</p>
        @else
            <p class="mb-3 text-sm text-content-muted">Every movement that touched this batch, oldest first. On the day a supplier reports a problem, this is who to telephone.</p>
            <x-table>
                <x-slot:head>
                    <x-th>When</x-th>
                    <x-th>What happened</x-th>
                    <x-th>Who</x-th>
                    <x-th numeric>In</x-th>
                    <x-th numeric>Out</x-th>
                </x-slot:head>
                @foreach ($entries as $entry)
                    @php
                        $href = Batch::documentHref($entry);
                    @endphp
                    <tr class="border-b border-line last:border-0" wire:key="entry-{{ $entry['ledgerId'] }}">
                        <x-td>
                            <span class="block text-content">@date($entry['businessDate'])</span>
                            <span class="block text-xs text-content-subtle">{{ Format::dateTime($entry['occurredAt']) }}</span>
                        </x-td>
                        <x-td>
                            <span class="text-content">{{ Batch::SOURCE_LABELS[$entry['sourceType']] ?? $entry['sourceType'] }}</span>
                            @if ($entry['sourceRef'] !== null)
                                <span class="block text-xs text-content-subtle">
                                    @if ($href === null){{ $entry['sourceRef'] }}@else<a href="{{ $href }}" class="text-accent hover:underline">{{ $entry['sourceRef'] }}</a>@endif
                                </span>
                            @endif
                        </x-td>
                        <x-td>{!! $entry['partyName'] === null ? '<span class="text-content-subtle">—</span>' : e($entry['partyName']) !!}</x-td>
                        <x-td numeric>{{ $entry['qtyInMilli'] > 0 ? Format::qty($entry['qtyInMilli'], $batch['unit']) : '—' }}</x-td>
                        <x-td numeric>{{ $entry['qtyOutMilli'] > 0 ? Format::qty($entry['qtyOutMilli'], $batch['unit']) : '—' }}</x-td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>
</div>
