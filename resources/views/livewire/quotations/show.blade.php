@php
    use App\View\Format;
    $cash = fn (int $v) => Format::money($v, currencyCode: $currency);
    $lineQty = fn (array $item) => $item['entryUnit'] && $item['entryQtyMilli'] !== null
        ? Format::qty((int) $item['entryQtyMilli'], $item['entryUnit'])
        : Format::qty((int) $item['qtyMilli'], $item['unit']);
@endphp
<div class="w-full">
    <x-page-header :title="'Quote '.$quote['quoteNo']" :description="'For '.$quote['customerName'].($quote['reference'] ? ' · '.$quote['reference'] : '')">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/quotations">All quotes</x-button>
            <x-button variant="secondary" size="sm" href="/quotations/{{ $quote['id'] }}/print">Print</x-button>
            @if ($quote['status'] === 'OPEN')
                @can('quotations.edit')
                    <x-button variant="secondary" size="sm" href="/quotations/{{ $quote['id'] }}/edit">Change</x-button>
                @endcan
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($quote['status'] === 'CONVERTED' && $quote['convertedSaleId'] !== null)
        <x-alert tone="success" title="This quote became a sale" class="mb-4">
            <a href="/sales/{{ $quote['convertedSaleId'] }}" class="font-medium underline">Open the sale</a>
            @if ($quote['overrideReason'] !== null)
                <p class="mt-1">It was past its date when it was honoured: {{ $quote['overrideReason'] }}</p>
            @endif
        </x-alert>
    @endif

    @if ($quote['status'] === 'CANCELLED')
        <x-alert tone="warning" title="This quote was cancelled" class="mb-4">{{ $quote['cancelReason'] }}</x-alert>
    @endif

    @if ($expired && $quote['status'] === 'OPEN')
        <x-alert tone="warning" title="This quote has run out" class="mb-4">
            It was good until @date($quote['validUntil']). Prices may have moved since. You can
            still turn it into a sale, but you will be asked why.
        </x-alert>
    @endif

    <x-card class="mb-4">
        <dl class="grid gap-4 sm:grid-cols-4">
            <div><dt class="text-xs text-content-muted">Issued</dt><dd class="mt-0.5 text-sm font-medium text-content">@date($quote['businessDate'])</dd></div>
            <div><dt class="text-xs text-content-muted">Valid until</dt><dd @class(['mt-0.5 text-sm font-medium', 'text-warning' => $expired, 'text-content' => ! $expired])>@date($quote['validUntil'])</dd></div>
            <div><dt class="text-xs text-content-muted">Status</dt><dd class="mt-0.5 text-sm font-medium text-content">{{ $statusLabel }}</dd></div>
            <div><dt class="text-xs text-content-muted">Total</dt><dd class="tabular mt-0.5 text-sm font-semibold text-content">{{ $cash((int) $quote['totalMinor']) }}</dd></div>
        </dl>
    </x-card>

    <x-card class="mb-4">
        <h2 class="mb-3 text-sm font-semibold text-content">Items</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-xs text-content-muted">
                        <th class="pb-2 font-medium">Item</th><th class="pb-2 text-right font-medium">Qty</th><th class="pb-2 text-right font-medium">Price</th><th class="pb-2 text-right font-medium">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($quote['items'] as $item)
                        <tr class="border-b border-line">
                            <td class="py-2 text-content">{{ $item['productName'] }}</td>
                            <td class="tabular py-2 text-right text-content">{{ $lineQty($item) }}</td>
                            <td class="tabular py-2 text-right text-content">{{ $cash((int) $item['unitPriceMinor']) }}</td>
                            <td class="tabular py-2 text-right font-medium text-content">{{ $cash((int) $item['lineTotalMinor']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <dl class="mt-4 ml-auto max-w-xs space-y-1.5 text-sm">
            <div class="flex items-baseline justify-between gap-3"><dt class="text-content-muted">Subtotal</dt><dd class="tabular text-content">{{ $cash((int) $quote['subtotalMinor']) }}</dd></div>
            @if ($quote['discountMinor'] > 0)
                <div class="flex items-baseline justify-between gap-3"><dt class="text-content-muted">Discount</dt><dd class="tabular text-content">&minus;{{ $cash((int) $quote['discountMinor']) }}</dd></div>
            @endif
            @if ($quote['taxMinor'] > 0)
                <div class="flex items-baseline justify-between gap-3"><dt class="text-content-muted">Tax</dt><dd class="tabular text-content">{{ $cash((int) $quote['taxMinor']) }}</dd></div>
            @endif
            <div class="flex items-baseline justify-between gap-3 border-t border-line pt-1.5"><dt class="font-medium text-content">Total</dt><dd class="tabular font-semibold text-content">{{ $cash((int) $quote['totalMinor']) }}</dd></div>
        </dl>
        @if ($quote['notes'] !== null && $quote['notes'] !== '')
            <p class="mt-4 border-t border-line pt-3 text-sm text-content-muted">{{ $quote['notes'] }}</p>
        @endif
    </x-card>

    @if ($quote['status'] === 'OPEN')
        @can('quotations.create')
            <x-card class="mb-4">
                <h2 class="mb-1 text-sm font-semibold text-content">The customer accepted</h2>
                <p class="mb-4 text-sm text-content-muted">
                    This rings the goods up at the prices on this quote, even if the shelf price has moved
                    since. Stock leaves and the books are posted, exactly as at the till.
                </p>
                @error('convert')<x-alert tone="danger" class="mb-4">{{ $message }}</x-alert>@enderror

                <form wire:submit="convert" class="space-y-4" novalidate>
                    @if ($expired)
                        <x-field label="Why is this still being honoured?" for="overrideReason" required :hint="'The price was only promised to '.$quote['validUntil'].'. Whatever you type is kept with the quote.'">
                            <x-input id="overrideReason" wire:model="overrideReason" required :invalid="$errors->has('overrideReason')" />
                        </x-field>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label for="account" class="mb-1 block text-sm text-content-muted">Paid into</label>
                            <select id="account" wire:model="accountId" class="h-10 w-full rounded-lg border border-line bg-surface-raised px-3 text-sm text-content">
                                @foreach ($accounts as $account)<option value="{{ $account['id'] }}">{{ $account['name'] }}</option>@endforeach
                            </select>
                        </div>
                        <x-field label="Paying now" for="paying">
                            <x-input amount id="paying" wire:model.live.debounce.400ms="paying" />
                        </x-field>
                        <div class="self-end pb-2 text-sm">
                            <span class="text-content-muted">Total </span>
                            <span class="tabular font-semibold text-content">{{ $currency }} {{ Format::money((int) $quote['totalMinor'], bare: true) }}</span>
                            @if ($owing > 0)
                                <p class="mt-1 text-xs text-warning">{{ $currency }} {{ Format::money($owing, bare: true) }} will be owed</p>
                            @endif
                        </div>
                    </div>

                    {{-- A part-paid sale has to be owed by somebody: one is created from the quote's name at this moment. --}}
                    @if ($owing > 0 && $quote['customerId'] === null)
                        <div class="rounded-xl border border-line p-3">
                            <label class="flex items-start gap-3">
                                <input type="checkbox" wire:model="createCustomer" class="mt-1 h-4 w-4 accent-[var(--accent)]">
                                <span class="text-sm">
                                    <span class="block font-medium text-content">Add {{ $quote['customerName'] }} to your customers</span>
                                    <span class="mt-0.5 block text-xs text-content-muted">Needed to record what is still owed, and to send a statement later.</span>
                                </span>
                            </label>
                            <div class="mt-3 max-w-[12rem]">
                                <x-field label="Days to pay" for="termsDays">
                                    <x-input id="termsDays" inputmode="numeric" wire:model="termsDays" :invalid="$errors->has('termsDays')" />
                                </x-field>
                            </div>
                        </div>
                    @endif

                    <x-button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="convert">Turn into a sale</span><span wire:loading wire:target="convert">Working…</span>
                    </x-button>
                </form>
            </x-card>

        @can('quotations.void')
            <x-card>
                <h2 class="mb-1 text-sm font-semibold text-content">The customer went somewhere else</h2>
                <p class="mb-3 text-sm text-content-muted">
                    Cancelling closes the quote. Nothing is deleted: it stays on file, keeps its number and
                    can still be printed, because a price the shop once offered is worth being able to look
                    up.
                </p>
                @error('cancel')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror
                <div x-data="{ show: {{ $errors->has('reason') ? 'true' : 'false' }} }">
                    <form x-show="show" x-cloak wire:submit="cancel" class="space-y-3" novalidate>
                        <x-field label="Why?" for="reason" required>
                            <x-input id="reason" wire:model="reason" required :invalid="$errors->has('reason')" />
                        </x-field>
                        <div class="flex gap-2">
                            <x-button type="submit" variant="secondary" wire:loading.attr="disabled">Cancel this quote</x-button>
                            <x-button variant="ghost" x-on:click="show = false">Keep it open</x-button>
                        </div>
                    </form>
                    <x-button x-show="! show" variant="secondary" size="sm" x-on:click="show = true">Cancel this quote</x-button>
                </div>
            </x-card>
        @endcan
        @endcan
    @endif
</div>
