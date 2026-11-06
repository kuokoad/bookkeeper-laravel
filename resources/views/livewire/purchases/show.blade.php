@use('App\Domain\Money')
@use('App\Domain\Quantity')
@use('App\View\Format')
@php
    $lineQty = fn (array $item): string => $item['entryUnit'] && $item['entryQtyMilli'] !== null ? Format::qty((int) $item['entryQtyMilli'], $item['entryUnit']) : Format::qty((int) $item['qtyMilli'], $item['unit']);
    $lines = $journal['lines'] ?? [];
@endphp
<div class="w-full">
    <x-page-header :title="$purchase['purchaseNo']" :description="Format::date($purchase['businessDate']).($purchase['invoiceNo'] ? ' · Invoice '.$purchase['invoiceNo'] : '')">
        <x-slot:actions><x-button variant="secondary" size="sm" href="/purchases">All purchases</x-button></x-slot:actions>
    </x-page-header>

    @if ($purchase['status'] === 'VOIDED')
        <x-alert tone="warning" title="This purchase was voided" class="mb-4">
            {{ $purchase['voidReason'] }}@if ($purchase['voidedAt']) — {{ Format::dateTime($purchase['voidedAt']) }}@endif.
        </x-alert>
    @endif
    @if ($purchase['kind'] === 'RETURN')
        <x-alert tone="info" class="mb-4">This is a return document — goods sent back to the supplier.</x-alert>
    @endif
    @if ($purchase['kind'] === 'VOID')
        <x-alert tone="info" class="mb-4">This is the reversing entry created when a purchase was voided.</x-alert>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-card>
            <p class="text-sm text-content-muted">Total</p>
            <p class="tabular mt-1 text-xl font-semibold text-content">{{ Format::money((int) $purchase['totalMinor'], currencyCode: $currency) }}</p>
        </x-card>
        <x-card>
            <p class="text-sm text-content-muted">Paid</p>
            <p class="tabular mt-1 text-xl font-semibold text-content">{{ Format::money($paid, bare: true) }}</p>
        </x-card>
        <x-card>
            <p class="text-sm text-content-muted">{{ $purchase['outstandingMinor'] < 0 ? 'Supplier owes you' : 'Still owed' }}</p>
            <p @class(['tabular mt-1 text-xl font-semibold', 'text-warning' => $purchase['outstandingMinor'] > 0, 'text-content' => $purchase['outstandingMinor'] <= 0])>{{ Format::money(Money::absolute($purchase['outstandingMinor']), bare: true) }}</p>
        </x-card>
    </div>

    @if ($purchase['supplierName'])
        <x-card class="mb-4">
            <p class="text-sm text-content-muted">Supplier</p>
            <p class="mt-0.5">
                <a href="/suppliers/{{ $purchase['supplierId'] }}" class="font-medium text-accent hover:underline">{{ $purchase['supplierName'] }}</a>
                @if ($purchase['supplierPhone'])<span class="ml-2 text-sm text-content-subtle">{{ $purchase['supplierPhone'] }}</span>@endif
            </p>
        </x-card>
    @endif

    <h2 class="mb-3 text-sm font-semibold text-content">Items</h2>
    <x-table class="mb-6">
        <x-slot:head>
            <x-th>Product</x-th>
            <x-th numeric>Qty</x-th>
            <x-th numeric>Cost each</x-th>
            <x-th numeric>Discount</x-th>
            <x-th numeric>Total</x-th>
            <x-th numeric>Returned</x-th>
        </x-slot:head>
        @foreach ($purchase['items'] as $item)
            <tr class="border-b border-line last:border-0" wire:key="item-{{ $item['id'] }}">
                <x-td>
                    <span class="font-medium text-content">{{ $item['productName'] }}</span>
                    @foreach ($item['batches'] as $batch)
                        <span class="mt-0.5 block text-xs text-content-muted">{{ $batch['batchRef'] }} · expires @date($batch['expiryDate'])</span>
                    @endforeach
                </x-td>
                <x-td numeric>{{ $lineQty($item) }}</x-td>
                <x-td numeric>{{ Format::money((int) $item['unitCostMinor'], bare: true) }}</x-td>
                <x-td numeric>{{ $item['discountMinor'] > 0 ? Format::money((int) $item['discountMinor'], bare: true) : '—' }}</x-td>
                <x-td numeric>{{ Format::money((int) $item['lineTotalMinor'], bare: true) }}</x-td>
                <x-td numeric>
                    @if ($item['returnedQtyMilli'] > 0)
                        <span class="text-warning">{{ Format::qty((int) $item['returnedQtyMilli'], $item['unit']) }}</span>
                    @else
                        <span class="text-content-subtle">—</span>
                    @endif
                </x-td>
            </tr>
        @endforeach
        <tr class="bg-surface-sunken font-semibold">
            <x-td>Total</x-td>
            <x-td />
            <x-td />
            <x-td numeric>{{ $purchase['discountMinor'] > 0 ? Format::money((int) $purchase['discountMinor'], bare: true) : '—' }}</x-td>
            <x-td numeric>{{ Format::money((int) $purchase['totalMinor'], bare: true) }}</x-td>
            <x-td />
        </tr>
    </x-table>

    <h2 class="mb-3 text-sm font-semibold text-content">Accounting entry</h2>
    @if ($lines === [])
        <x-card>
            <p class="text-sm text-content-muted">{{ (int) $purchase['totalMinor'] === 0 ? 'Nothing to post: this delivery came to 0.00, so no money was paid or owed.' : 'No accounting entry is linked to this purchase.' }}</p>
        </x-card>
    @else
        <x-table>
            <x-slot:head>
                <x-th>Account</x-th>
                <x-th numeric>Debit</x-th>
                <x-th numeric>Credit</x-th>
            </x-slot:head>
            @foreach ($lines as $line)
                <tr class="border-b border-line last:border-0" wire:key="line-{{ $line['id'] }}">
                    <x-td><span class="text-content-subtle">{{ $line['accountCode'] }}</span> <span class="font-medium text-content">{{ $line['accountName'] }}</span></x-td>
                    <x-td numeric>{{ $line['debit'] > 0 ? Format::money((int) $line['debit'], bare: true) : '—' }}</x-td>
                    <x-td numeric>{{ $line['credit'] > 0 ? Format::money((int) $line['credit'], bare: true) : '—' }}</x-td>
                </tr>
            @endforeach
            <tr class="bg-surface-sunken font-semibold">
                <x-td>Total</x-td>
                <x-td numeric>{{ Format::money($journal['totalDebit'], bare: true) }}</x-td>
                <x-td numeric>{{ Format::money($journal['totalCredit'], bare: true) }}</x-td>
            </tr>
        </x-table>
        <p class="mt-2 text-xs text-content-subtle">{{ $journal['balanced'] ? 'Debits equal credits — this entry balances.' : 'WARNING: this entry does not balance. Please report it.' }}</p>
    @endif

    @if ($returnable !== [] && Gate::allows('purchases.create'))
        @php
            $returnErrors = $errors->hasAny(['returnForm', 'returnDate', 'refundAmount']);
        @endphp
        <div class="mt-8" x-data="{ open: {{ $returnErrors ? 'true' : 'false' }} }">
            <div x-show="! open" class="rounded-xl border border-line bg-surface-raised p-4">
                <h2 class="text-sm font-semibold text-content">Return goods to this supplier</h2>
                <p class="mt-1 mb-3 text-sm text-content-muted">Send some or all of this delivery back. Stock leaves at the price this supplier charged, not the blended average.</p>
                <x-button variant="secondary" x-on:click="open = true">Record a return…</x-button>
            </div>
            <form x-show="open" x-cloak wire:submit="recordReturn" class="rounded-xl border border-line bg-surface-raised p-4" novalidate>
                <h2 class="mb-3 text-sm font-semibold text-content">Return goods to this supplier</h2>
                @error('returnForm')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror
                <div class="mb-4 max-w-xs">
                    <x-field label="Date" for="return-date" name="returnDate">
                        <x-date-input id="return-date" wire:model="returnDate" :value="$returnDate" required :invalid="$errors->has('returnDate')" />
                    </x-field>
                </div>
                <div class="space-y-2">
                    @foreach ($returnable as $item)
                        @php
                            $typed = trim((string) ($returnQty[$item['id']] ?? ''));
                            try { $milli = $typed === '' ? 0 : Quantity::parse($typed); $bad = false; } catch (\App\Domain\Errors\DomainError) { $milli = 0; $bad = true; }
                            $tooMany = ! $bad && $milli > $item['returnableMilli'];
                        @endphp
                        <div wire:key="ret-{{ $item['id'] }}" class="flex flex-wrap items-end justify-between gap-3 rounded-lg border border-line p-3">
                            <div class="min-w-0">
                                <p class="font-medium text-content">{{ $item['productName'] }}</p>
                                <p class="text-xs text-content-subtle">{{ Quantity::format($item['returnableMilli']) }} {{ $item['unit'] }} can still be returned · {{ $currency }} {{ Money::toDecimalString($item['unitCostMinor']) }} each</p>
                            </div>
                            <div class="w-32">
                                <label for="ret-{{ $item['id'] }}" class="mb-1 block text-xs text-content-muted">Return qty</label>
                                <x-input amount id="ret-{{ $item['id'] }}" wire:model.live.debounce.400ms="returnQty.{{ $item['id'] }}" placeholder="0" :invalid="$bad || $tooMany" />
                                @if ($tooMany)<p class="mt-1 text-xs font-medium text-danger">Max {{ Quantity::format($item['returnableMilli']) }}</p>@endif
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div>
                        <label for="refundAccountId" class="mb-1 block text-xs text-content-muted">Money handed back via</label>
                        <select id="refundAccountId" wire:model="refundAccountId" class="h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content">
                            @foreach ($accounts as $account)
                                <option value="{{ $account['id'] }}">{{ $account['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="refundAmount" class="mb-1 block text-xs text-content-muted">Amount refunded</label>
                        <x-input amount id="refundAmount" wire:model="refundAmount" placeholder="0.00" :invalid="$errors->has('refundAmount')" />
                        @error('refundAmount')<p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="return-reason" class="mb-1 block text-xs text-content-muted">Reason</label>
                        <x-input id="return-reason" wire:model="returnReason" placeholder="e.g. Damaged" />
                    </div>
                </div>
                <p class="mt-3 text-xs text-content-subtle">Value of goods being returned: {{ $currency }} {{ Money::toDecimalString($estimate) }}. Anything not refunded in cash reduces what you owe them.</p>
                <div class="mt-4 flex items-center gap-3">
                    <x-button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="recordReturn">Record return to supplier</span>
                        <span wire:loading wire:target="recordReturn">Recording…</span>
                    </x-button>
                    <x-button variant="ghost" x-on:click="open = false">Cancel</x-button>
                </div>
            </form>
        </div>
    @endif

    @if ($purchase['status'] === 'POSTED' && $purchase['kind'] === 'PURCHASE' && Gate::allows('purchases.void'))
        <div class="mt-6">
            <x-confirm-dialog title="Void this purchase" description="Stock is taken back out and the payable or payment reversed. The original record is kept."
                trigger="Void {{ $purchase['purchaseNo'] }}…" heading="Void {{ $purchase['purchaseNo'] }}?" wire:submit="void">
                <x-field label="Reason" for="void-reason" name="reason" required>
                    <x-input id="void-reason" wire:model="reason" placeholder="e.g. Delivered to the wrong shop" :invalid="$errors->has('reason')" />
                </x-field>
            </x-confirm-dialog>
        </div>
    @endif
</div>
