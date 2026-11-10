@php
    use App\Domain\Quantity;
    use App\View\Format;
    $fmt = fn (int $v) => Format::money($v, currencyCode: $currency);
@endphp
<div>
    @if ($quotationId === null)
        <x-page-header title="New quote" description="A price the customer can take away. Nothing is sold and no stock moves until they accept." />
    @else
        <x-page-header :title="'Change quote '.$quoteNo" description="The number stays the same, because the customer may be holding paper with it on." />
    @endif

    <form wire:submit="save" class="space-y-6" novalidate>
        @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror

        <x-card>
            <h2 class="mb-4 text-sm font-semibold text-content">Who it is for</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Customer" for="customerName" required hint="Type any name. They do not need to be on your customer list yet.">
                    <x-input id="customerName" wire:model.blur="customerName" list="quote-customers" :invalid="$errors->has('customerName')" />
                    <datalist id="quote-customers">
                        @foreach ($customers as $customer)<option value="{{ $customer['name'] }}"></option>@endforeach
                    </datalist>
                </x-field>
                <x-field label="Phone" for="customerPhone">
                    <x-input id="customerPhone" type="tel" wire:model="customerPhone" />
                </x-field>
                <x-field label="Job or site" for="reference" hint="Optional. “Adenta site”, “Block C roofing”.">
                    <x-input id="reference" wire:model="reference" />
                </x-field>
                <div class="grid grid-cols-2 gap-3">
                    <x-field label="Date" for="businessDate" required>
                        <x-date-input id="businessDate" aria-label="Quote date" wire:model="businessDate" :value="$businessDate" :invalid="$errors->has('businessDate')" />
                    </x-field>
                    <x-field label="Valid until" for="validUntil" required>
                        <x-date-input id="validUntil" aria-label="Valid until" wire:model="validUntil" :value="$validUntil" :invalid="$errors->has('validUntil')" />
                    </x-field>
                </div>
            </div>
            <p class="mt-3 text-xs text-content-muted">
                After the valid-until date the quote can still be turned into a sale, but only if you say
                why. Prices move, and an old quote honoured without a second look is how stock is sold
                below what it now costs to replace.
            </p>
        </x-card>

        <x-card>
            <h2 class="mb-4 text-sm font-semibold text-content">What is being quoted</h2>
            @error('lines')<x-alert tone="danger" class="mb-4">{{ $message }}</x-alert>@enderror

            <div class="space-y-3">
                @foreach ($lines as $key => $line)
                    @php
                        $product = $products[$line['productId']] ?? null;
                        $total = $lineTotals[$key] ?? null;
                        $quoted = $line['quoted'];
                        $cartonChanged = $line['alt'] && $product && $quoted
                            && ($quoted['factorMilli'] !== $product['altFactor'] || $quoted['unit'] !== $product['unit'] || $quoted['altUnit'] !== $product['altUnit']);
                    @endphp
                    <div class="grid gap-3 rounded-xl border border-line p-3 sm:grid-cols-[2fr_1fr_1fr_1fr_auto]" wire:key="qline-{{ $key }}">
                        <div>
                            <label for="product-{{ $key }}" class="mb-1 block text-xs text-content-muted">Item</label>
                            <select id="product-{{ $key }}" wire:model.live="lines.{{ $key }}.productId" class="h-10 w-full rounded-lg border border-line bg-surface-raised px-3 text-sm text-content">
                                <option value="">Choose…</option>
                                @foreach ($products as $option)<option value="{{ $option['id'] }}">{{ $option['name'] }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label for="qty-{{ $key }}" class="mb-1 block text-xs text-content-muted">Qty {{ $product ? '('.($line['alt'] && $product['altUnit'] ? $product['altUnit'] : $product['unit']).')' : '' }}</label>
                            <x-input amount id="qty-{{ $key }}" class="h-10" wire:model.live.debounce.400ms="lines.{{ $key }}.qty" :invalid="$total === null && $line['productId'] !== '' && trim($line['qty']) !== ''" />
                            @if ($product && $product['altUnit'])
                                <div class="mt-1">
                                    <select id="unit-{{ $key }}" aria-label="Unit" wire:change="setUnit({{ $key }}, $event.target.value)" class="h-10 w-full rounded-lg border border-line-strong bg-surface-raised px-2 text-sm text-content">
                                        <option value="base" @selected(! $line['alt'])>{{ $product['unit'] }}</option>
                                        <option value="alt" @selected($line['alt'])>{{ $product['altUnit'] }} ({{ $product['altFactor'] === null ? '' : Quantity::format($product['altFactor']) }} {{ $product['unit'] }})</option>
                                    </select>
                                </div>
                            @endif
                            @if ($cartonChanged)
                                <p role="alert" class="mt-1 text-xs text-danger">
                                    Quoted at {{ Quantity::format($quoted['factorMilli']) }} {{ $quoted['unit'] }} a {{ $quoted['altUnit'] }}; a {{ $product['altUnit'] ?? $quoted['altUnit'] }} is now
                                    {{ $product['altFactor'] === null ? 'not offered' : Quantity::format($product['altFactor']).' '.$product['unit'] }}. Check the quantity and price before saving.
                                </p>
                            @endif
                        </div>
                        <div>
                            <label for="price-{{ $key }}" class="mb-1 block text-xs text-content-muted">Price</label>
                            <x-input amount id="price-{{ $key }}" class="h-10" wire:model.live.debounce.400ms="lines.{{ $key }}.unitPrice" />
                        </div>
                        <div>
                            <label for="disc-{{ $key }}" class="mb-1 block text-xs text-content-muted">Less</label>
                            <x-input amount id="disc-{{ $key }}" class="h-10" wire:model.live.debounce.400ms="lines.{{ $key }}.discount" />
                        </div>
                        <div class="flex items-end justify-between gap-2 sm:flex-col sm:items-end">
                            <span class="tabular text-sm font-medium text-content">{{ $total === null ? '—' : $fmt($total) }}</span>
                            @if (count($lines) > 1)
                                <button type="button" wire:click="removeLine({{ $key }})" class="text-xs font-medium text-danger hover:underline">Remove</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            <x-button variant="secondary" size="sm" class="mt-3" wire:click="addLine">Add item</x-button>
        </x-card>

        <x-card>
            <h2 class="mb-4 text-sm font-semibold text-content">Totals</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Discount on the whole quote" for="quoteDiscount">
                    <x-input amount id="quoteDiscount" wire:model.live.debounce.400ms="quoteDiscount" />
                </x-field>
                <dl class="space-y-2 self-end text-sm">
                    <div class="flex items-baseline justify-between gap-3"><dt class="text-content-muted">Items</dt><dd class="tabular font-medium text-content">{{ $fmt($subtotal) }}</dd></div>
                    @if ($off > 0)
                        <div class="flex items-baseline justify-between gap-3"><dt class="text-content-muted">Less</dt><dd class="tabular font-medium text-content">−{{ $fmt($off) }}</dd></div>
                    @endif
                    <div class="flex items-baseline justify-between gap-3 border-t border-line pt-2"><dt class="font-medium text-content">Before tax</dt><dd class="tabular font-semibold text-content">{{ $fmt($beforeTax) }}</dd></div>
                </dl>
            </div>
            <p class="mt-3 text-xs text-content-muted">Any tax the shop charges is added when the quote is saved, and appears on the printed copy.</p>
            <x-field label="Notes" for="notes" hint="Anything the customer should read. Optional." class="mt-3">
                <x-input id="notes" wire:model="notes" />
            </x-field>
        </x-card>

        <div class="flex items-center gap-3">
            <x-button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">{{ $quotationId === null ? 'Save quote' : 'Save changes' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-button>
            @if ($bad)<span class="text-sm text-danger">Check the quantities and prices above.</span>@endif
        </div>
    </form>
</div>
