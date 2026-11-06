@php
    use App\Domain\Quantity;
    use App\View\Format;
    $fmt = fn (int $v) => Format::money($v, bare: true);
@endphp
<div class="mx-auto max-w-6xl" x-data x-on:pos-focus-search.window="$nextTick(() => document.getElementById('pos-search')?.focus())">
    <x-page-header title="New sale" description="Search or scan, take payment, done.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/sales">All sales</x-button>
        </x-slot:actions>
    </x-page-header>

    @if (! $hasProducts)
        <x-empty-state title="No products to sell yet" description="Add what you sell first, then record its opening stock. After that this screen is ready.">
            <x-slot:action><x-button href="/products/new">Add a product</x-button></x-slot:action>
        </x-empty-state>
    @else
        <div class="grid gap-4 lg:grid-cols-5">
            {{-- left: catalogue + cart --}}
            <div class="space-y-4 lg:col-span-3">
                @if ($saved)
                    <x-alert tone="success" :title="'Sale saved — '.$saved['receiptNo']">
                        @if ($saved['change'] > 0)
                            <p class="text-base font-semibold text-content">Give change: {{ $currency }} {{ $fmt($saved['change']) }}</p>
                        @else
                            <p>Payment received in full.</p>
                        @endif
                        <p class="mt-2 flex items-center gap-3">
                            <x-button size="sm" href="/sales/{{ $saved['saleId'] }}/receipt?print=1">Print receipt</x-button>
                            <a href="/sales/{{ $saved['saleId'] }}" class="text-accent hover:underline">View sale</a>
                        </p>
                    </x-alert>
                @endif

                @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror

                @if ($staleUnit)
                    <x-alert tone="warning" :title="$staleUnit['productName'].': carton size changed'">
                        <p>
                            One {{ $staleUnit['altUnit'] }} is now {{ Quantity::format((int) $staleUnit['altFactorMilli']) }}
                            {{ $staleUnit['unit'] }}, not {{ Quantity::format((int) $staleUnit['shownFactorMilli']) }}. Nothing was
                            sold. The line below now uses the new size and carton price: check the quantity and
                            price, then charge again.
                        </p>
                    </x-alert>
                @endif

                <div class="rounded-xl border border-line bg-surface-raised p-4">
                    <label for="pos-search" class="mb-1.5 block text-sm font-medium text-content">Find a product</label>
                    <x-input id="pos-search" wire:model.live.debounce.150ms="search" wire:keydown.enter.prevent="scan($event.target.value)"
                        placeholder="Type a name, or scan a barcode" autocomplete="off" autofocus class="h-12 text-base" />

                    @if ($matches !== [])
                        <ul class="mt-2 divide-y divide-line overflow-hidden rounded-lg border border-line">
                            @foreach ($matches as $product)
                                <li wire:key="match-{{ $product['id'] }}">
                                    <button type="button" wire:click="add({{ $product['id'] }})" class="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left hover:bg-surface-sunken">
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium text-content">{{ $product['name'] }}</span>
                                            <span class="block text-xs text-content-subtle">
                                                {{ $product['trackInventory'] ? Quantity::formatInBothUnits($product['qtyOnHand'], $product['unit'], $product['altUnit'], $product['altFactor']).' in stock' : 'Not stock-tracked' }}{{ $product['sku'] ? ' · '.$product['sku'] : '' }}
                                            </span>
                                        </span>
                                        <span class="flex shrink-0 items-center gap-2">
                                            @if ($product['trackInventory'] && $product['qtyOnHand'] <= 0)<x-badge tone="danger">Out</x-badge>@endif
                                            <span class="tabular font-semibold text-content">{{ $fmt($product['sellingPrice']) }}</span>
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="rounded-xl border border-line bg-surface-raised">
                    <div class="flex items-center justify-between border-b border-line px-4 py-3">
                        <h2 class="text-sm font-semibold text-content">Cart{{ $lines !== [] ? ' ('.count($lines).')' : '' }}</h2>
                        @if ($lines !== [])
                            <x-button variant="ghost" size="sm" wire:click="clearSale">Clear</x-button>
                        @endif
                    </div>

                    @if ($lines === [])
                        <p class="px-4 py-10 text-center text-sm text-content-muted">Search above to add the first item.</p>
                    @else
                        <ul class="divide-y divide-line">
                            @foreach ($lines as $key => $line)
                                @php($s = $status[$key])
                                <li class="px-4 py-3" wire:key="line-{{ $key }}">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="truncate font-medium text-content">{{ $line['name'] }}</p>
                                            <p class="text-xs text-content-subtle">{{ Quantity::formatInBothUnits((int) $line['qtyOnHandMilli'], $line['unit'], $line['altUnit'], $line['altFactorMilli']) }} in stock</p>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="tabular font-semibold text-content">{{ $fmt($totals['lineTotals'][$key] ?? 0) }}</span>
                                            <x-button variant="ghost" size="sm" wire:click="removeLine({{ $key }})" aria-label="Remove {{ $line['name'] }}">✕</x-button>
                                        </div>
                                    </div>

                                    <div @class(['mt-2 grid gap-2', 'grid-cols-3' => $mayOverridePrice, 'grid-cols-2' => ! $mayOverridePrice])>
                                        <div>
                                            <label for="qty-{{ $key }}" class="mb-1 block text-xs text-content-muted">Qty ({{ $line['alt'] && $line['altUnit'] ? $line['altUnit'] : $line['unit'] }})</label>
                                            <x-input amount id="qty-{{ $key }}" class="h-10" wire:model.live.debounce.300ms="lines.{{ $key }}.qty" :invalid="$totals['lineTotals'][$key] === null || $s['short']" />
                                            @if ($line['altUnit'])
                                                <div class="mt-1">
                                                    <select id="unit-{{ $key }}" aria-label="Unit" wire:change="setUnit({{ $key }}, $event.target.value)" class="h-10 w-full rounded-lg border border-line-strong bg-surface-raised px-2 text-sm text-content">
                                                        <option value="base" @selected(! $line['alt'])>{{ $line['unit'] }}</option>
                                                        <option value="alt" @selected($line['alt'])>{{ $line['altUnit'] }} ({{ $line['altFactorMilli'] === null ? '' : Quantity::format((int) $line['altFactorMilli']) }} {{ $line['unit'] }})</option>
                                                    </select>
                                                </div>
                                            @endif
                                        </div>
                                        <div>
                                            {{-- Somebody who may not change the price is shown the price, not a box that refuses one. --}}
                                            @if ($mayOverridePrice)
                                                <label for="price-{{ $key }}" class="mb-1 block text-xs text-content-muted">Price</label>
                                                <x-input amount id="price-{{ $key }}" class="h-10" wire:model.live.debounce.300ms="lines.{{ $key }}.unitPrice" />
                                            @else
                                                <p class="mb-1 text-xs text-content-muted">Price</p>
                                                <p class="tabular flex h-10 items-center justify-end rounded-lg bg-surface-sunken px-3 text-content-muted">{{ $line['unitPrice'] }}</p>
                                            @endif
                                        </div>
                                        @if ($mayOverridePrice)
                                            <div>
                                                <label for="disc-{{ $key }}" class="mb-1 block text-xs text-content-muted">Discount</label>
                                                <x-input amount id="disc-{{ $key }}" class="h-10" placeholder="0.00" wire:model.live.debounce.300ms="lines.{{ $key }}.discount" />
                                            </div>
                                        @endif
                                    </div>

                                    @if ($line['cartonWas'] !== null && $line['altFactorMilli'] !== null)
                                        <p role="alert" class="mt-1.5 text-xs font-medium text-warning">
                                            Now {{ Quantity::format((int) $line['altFactorMilli']) }} {{ $line['unit'] }} a {{ $line['altUnit'] }}, not {{ Quantity::format((int) $line['cartonWas']) }}. Check the quantity and price.
                                        </p>
                                    @endif
                                    @if ($s['short'])
                                        <p class="mt-1.5 text-xs font-medium text-warning">Only {{ Quantity::format((int) $line['qtyOnHandMilli']) }} {{ $line['unit'] }} in stock.</p>
                                    @endif
                                    @if ($s['needsApproval'])
                                        <p class="mt-1.5 text-xs font-medium text-warning">
                                            {{ $line['goodQtyMilli'] > 0 ? 'Only '.Quantity::format((int) $line['goodQtyMilli']).' '.$line['unit'].' still in date.' : 'None of this is still in date.' }}{{ $maySellExpired
                                                ? ($line['goodQtyMilli'] > 0 ? ' You will be asked to approve the rest.' : ' You will be asked to approve it.')
                                                : ($line['goodQtyMilli'] > 0 ? ' The rest needs approval from someone senior.' : ' Selling it needs approval from someone senior.') }}
                                        </p>
                                    @endif
                                    @if ($s['expiringSoon'])
                                        <p class="mt-1.5 text-xs text-content-muted">Expires @date($line['soonestExpiry'])</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            {{-- right: totals + payment --}}
            <form wire:submit="complete" class="lg:col-span-2" novalidate>
                <div class="space-y-4 lg:sticky lg:top-4">
                    <div class="rounded-xl border border-line bg-surface-raised p-4">
                        <dl class="space-y-1.5 text-sm">
                            <div class="flex justify-between"><dt class="text-content-muted">Subtotal</dt><dd class="tabular text-content">{{ $fmt($totals['subtotal']) }}</dd></div>
                            @if ($totals['discount'] > 0)
                                <div class="flex justify-between"><dt class="text-content-muted">Discount</dt><dd class="tabular text-content">−{{ $fmt($totals['discount']) }}</dd></div>
                            @endif
                            {{-- One line per tax, named: the customer at the counter is entitled to the breakdown. --}}
                            @foreach ($totals['taxLines'] as $taxLine)
                                @if ($taxLine->amount !== 0)
                                    <div class="flex justify-between"><dt class="text-content-muted">{{ $taxLine->component->name }}</dt><dd class="tabular text-content">{{ $fmt($taxLine->amount) }}</dd></div>
                                @endif
                            @endforeach
                            <div class="flex justify-between border-t border-line pt-2 text-lg font-semibold"><dt class="text-content">Total</dt><dd class="tabular text-content">{{ $currency }} {{ $fmt($totals['total']) }}</dd></div>
                        </dl>
                        @if ($mayOverridePrice)
                            <div class="mt-3">
                                <label for="invoice-discount" class="mb-1 block text-xs text-content-muted">Discount on the whole sale</label>
                                <x-input amount id="invoice-discount" class="h-10" placeholder="0.00" wire:model.live.debounce.300ms="invoiceDiscount" />
                            </div>
                        @endif
                    </div>

                    <div class="rounded-xl border border-line bg-surface-raised p-4">
                        <h2 class="mb-3 text-sm font-semibold text-content">Payment</h2>
                        <div class="space-y-3">
                            <div>
                                <label for="tender-account" class="mb-1 block text-xs text-content-muted">Method</label>
                                <select id="tender-account" wire:model.live="tenderAccountId" class="h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content">
                                    @foreach ($accounts as $account)<option value="{{ $account['id'] }}">{{ $account['name'] }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label for="tender-amount" class="mb-1 block text-xs text-content-muted">Amount received</label>
                                <div class="flex gap-2">
                                    <x-input amount id="tender-amount" class="h-11 text-base" placeholder="0.00" wire:model.live.debounce.300ms="tenderAmount" />
                                    <x-button variant="secondary" wire:click="exact" :disabled="$totals['total'] === 0">Exact</x-button>
                                </div>
                            </div>

                            @if ($secondAccountId === '')
                                <x-button variant="ghost" size="sm" wire:click="splitSecond" :disabled="count($accounts) < 2">+ Split across a second method</x-button>
                            @else
                                <div class="rounded-lg border border-line p-3">
                                    <div class="mb-2 flex items-center justify-between">
                                        <span class="text-xs font-medium text-content-muted">Second method</span>
                                        <x-button variant="ghost" size="sm" wire:click="removeSecond">Remove</x-button>
                                    </div>
                                    <select wire:model.live="secondAccountId" aria-label="Second payment method" class="mb-2 h-10 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content">
                                        @foreach ($accounts as $account)<option value="{{ $account['id'] }}">{{ $account['name'] }}</option>@endforeach
                                    </select>
                                    <x-input amount class="h-10" placeholder="0.00" aria-label="Second amount" wire:model.live.debounce.300ms="secondAmount" />
                                </div>
                            @endif

                            <div>
                                <label for="reference" class="mb-1 block text-xs text-content-muted">Reference (optional)</label>
                                <x-input id="reference" class="h-10" :placeholder="$referencePlaceholder" wire:model="reference" />
                            </div>
                        </div>

                        <dl class="mt-3 space-y-1.5 border-t border-line pt-3 text-sm">
                            <div class="flex justify-between"><dt class="text-content-muted">Received</dt><dd class="tabular text-content">{{ $fmt($totals['tendered']) }}</dd></div>
                            @if ($totals['change'] > 0)
                                <div class="flex justify-between text-base font-semibold"><dt class="text-success">Change</dt><dd class="tabular text-success">{{ $fmt($totals['change']) }}</dd></div>
                            @endif
                            @if ($totals['outstanding'] > 0)
                                <div class="flex justify-between text-base font-semibold"><dt class="text-warning">Balance owing</dt><dd class="tabular text-warning">{{ $fmt($totals['outstanding']) }}</dd></div>
                            @endif
                        </dl>
                    </div>

                    <div class="rounded-xl border border-line bg-surface-raised p-4">
                        @if ($mayBackdate)
                            <div class="mb-3">
                                <label for="sale-date" class="mb-1 block text-xs text-content-muted">Sale date</label>
                                <x-date-input id="sale-date" :value="$saleDate" :max="$today" :min="$lockedBefore ? \App\Domain\BusinessDate::addDays($lockedBefore, 1) : null" wire:model.live="pickedDate" />
                                @if ($lockedBefore)<p class="mt-1 text-xs text-content-subtle">The books are closed up to @date($lockedBefore).</p>@endif
                                @if ($pickedDate !== null)
                                    <x-alert tone="warning" class="mt-2">
                                        This sale will be recorded on @date($saleDate), not today.
                                        <button type="button" wire:click="useToday" class="font-medium text-accent hover:underline">Use today</button>
                                    </x-alert>
                                @endif
                            </div>
                        @endif

                        <label for="customer" class="mb-1 block text-xs text-content-muted">Customer @if ($totals['outstanding'] > 0)<span class="text-danger">(required)</span>@endif</label>
                        <select id="customer" wire:model.live="customerId" class="h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content">
                            <option value="">Walk-in customer</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer['id'] }}">{{ $customer['name'] }}{{ $customer['balance'] > 0 ? ' — owes '.$fmt($customer['balance']) : '' }}</option>
                            @endforeach
                        </select>

                        <label for="note" class="mt-3 mb-1 block text-xs text-content-muted">Note (optional)</label>
                        <x-input id="note" class="h-10" wire:model="note" />
                    </div>

                    @if ($needsCustomer)
                        <x-alert tone="warning">This sale is not fully paid. Choose a customer so the balance can be recorded against them.</x-alert>
                    @endif
                    @if ($overselling)
                        <x-alert tone="warning">Some items are more than you have in stock. The sale will be refused unless negative stock is switched on in Settings.</x-alert>
                    @endif
                    @if ($totals['discountTooLarge'])
                        <x-alert tone="warning">The discount is more than the sale is worth. Lower it to {{ $fmt($totals['subtotal']) }} or less, or take the items off the sale.</x-alert>
                    @endif

                    {{-- The block, and the one way past it: the last thing between this person and the sale. --}}
                    @if ($expired)
                        <x-alert :tone="$expired['mayOverride'] ? 'warning' : 'danger'" :title="$expired['productName'].' has passed its date'">
                            <p>
                                {{ $expired['qtyExpired'] }} {{ $expired['qtyExpired'] === '1' ? 'unit' : 'units' }} of
                                this sale can only come from {{ implode(', ', $expired['batchRefs']) }}, which is out of
                                date. Nothing has been saved.
                            </p>
                            @if ($expired['mayOverride'])
                                <div class="mt-3 space-y-2">
                                    <label for="override-reason" class="block text-xs font-medium text-content-muted">Why (optional)</label>
                                    <x-input id="override-reason" class="h-10" placeholder="Customer was told" wire:model="overrideReason" />
                                    {{-- The approval is this click only: an ordinary "Complete sale" afterwards never carries it. --}}
                                    <x-button variant="secondary" class="w-full" wire:click="complete(true)" wire:loading.attr="disabled">
                                        <span wire:loading.remove wire:target="complete">Sell it anyway</span><span wire:loading wire:target="complete">Saving…</span>
                                    </x-button>
                                </div>
                            @else
                                <p class="mt-2">Someone who can approve it has to allow this sale, or the goods should be written off.</p>
                            @endif
                        </x-alert>
                    @endif

                    <x-button type="submit" size="lg" class="w-full" :disabled="! $canSubmit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="complete">{{ $totals['outstanding'] > 0 ? 'Save on credit' : 'Complete sale' }} — {{ $currency }} {{ $fmt($totals['total']) }}</span>
                        <span wire:loading wire:target="complete">Saving…</span>
                    </x-button>
                </div>
            </form>
        </div>
    @endif
</div>
