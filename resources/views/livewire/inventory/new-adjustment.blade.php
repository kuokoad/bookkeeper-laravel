@php
    $select = 'h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content disabled:opacity-60';
    $label = 'mb-1 block text-xs font-medium text-content-muted';
@endphp
<div class="mx-auto max-w-4xl">
    <x-page-header title="Stock adjustment" description="Record stock that came in or went out for any reason other than a sale or purchase." />

    @if ($products === [])
        <x-empty-state title="No stock-tracked stock items yet" description="Add a stock item first, then come back to record its opening stock.">
            <x-slot:action><x-button href="/products/new">Add a stock item</x-button></x-slot:action>
        </x-empty-state>
    @else
        <form wire:submit="save" class="space-y-6" novalidate>
            @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror

            <div class="rounded-xl border border-line bg-surface-raised p-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Date" for="businessDate" required>
                        <x-date-input id="businessDate" wire:model="businessDate" :value="$businessDate" required :invalid="$errors->has('businessDate')" />
                    </x-field>
                    <x-field label="Reason" for="reason" required>
                        <select id="reason" wire:model.live="reason" class="{{ $select }}">
                            @foreach ($reasons as $value => $text)
                                <option value="{{ $value }}">{{ $text }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <x-field label="Note" for="note" hint="Optional, but future-you will thank you." class="sm:col-span-2">
                        <x-input id="note" wire:model="note" placeholder="e.g. Crate dropped during delivery" :invalid="$errors->has('note')" />
                    </x-field>
                </div>
            </div>

            <div class="rounded-xl border border-line bg-surface-raised p-4">
                <h2 class="mb-3 text-sm font-semibold text-content">Stock items</h2>

                <div class="space-y-3">
                    @foreach ($rows as $i => $row)
                        @php
                            $product = $row['productId'] === '' ? null : ($products[(int) $row['productId']] ?? null);
                            $unit = $product === null ? '' : ($row['alt'] && $product['altUnit'] ? $product['altUnit'] : $product['unit']);
                            $stockOut = ($forcedDirection ?? $row['direction']) === 'OUT';
                            $cost = \App\Support\CostFields::of($row['costSource'], (string) $row['value'], (string) $row['qty']);
                            $unitError = ! $stockOut && $row['costSource'] === 'unit' ? ($cost['error'] ?? null) : null;
                            $totalError = ! $stockOut && $row['costSource'] === 'total' ? ($cost['error'] ?? null) : null;
                        @endphp
                        <div class="grid gap-3 rounded-lg border border-line p-3 sm:grid-cols-12" wire:key="row-{{ $i }}">
                            <div class="sm:col-span-3">
                                <label for="product-{{ $i }}" class="{{ $label }}">Stock item</label>
                                <select id="product-{{ $i }}" wire:model.live="rows.{{ $i }}.productId" class="{{ $select }}">
                                    <option value="">Choose a stock item…</option>
                                    @foreach ($products as $option)
                                        <option value="{{ $option['id'] }}">{{ $option['name'] }} ({{ $option['qtyOnHandLabel'] }})</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="sm:col-span-2">
                                <label for="direction-{{ $i }}" class="{{ $label }}">Direction</label>
                                <select id="direction-{{ $i }}" wire:model.live="rows.{{ $i }}.direction" @disabled($forcedDirection !== null) class="{{ $select }}">
                                    <option value="IN">Stock in</option>
                                    <option value="OUT">Stock out</option>
                                </select>
                            </div>

                            <div class="sm:col-span-2">
                                <label for="qty-{{ $i }}" class="{{ $label }}">Quantity {{ $product ? "({$unit})" : '' }}</label>
                                <x-input amount id="qty-{{ $i }}" wire:model.live.debounce.400ms="rows.{{ $i }}.qty" placeholder="0" />
                                @if ($product && $product['altUnit'])
                                    <select id="unit-{{ $i }}" aria-label="Unit" wire:model.live="rows.{{ $i }}.alt" class="mt-1 h-9 w-full rounded-lg border border-line-strong bg-surface-raised px-2 text-sm text-content">
                                        <option value="0">{{ $product['unit'] }}</option>
                                        <option value="1">{{ $product['altUnit'] }}</option>
                                    </select>
                                @endif
                            </div>

                            @if ($reason === 'EXPIRED' && $product && $product['expiredBatches'] !== [])
                                <div class="sm:order-last sm:col-span-12">
                                    <label for="batch-{{ $i }}" class="{{ $label }}">Which batch expired</label>
                                    <select id="batch-{{ $i }}" wire:model="rows.{{ $i }}.batchId" class="{{ $select }}">
                                        <option value="">Whatever has expired</option>
                                        @foreach ($product['expiredBatches'] as $batch)
                                            <option value="{{ $batch['id'] }}">{{ $batch['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            <div class="sm:col-span-2">
                                <label for="unit-cost-{{ $i }}" class="{{ $label }}">Unit cost {{ $product ? "(per {$unit})" : '' }}</label>
                                <x-input amount id="unit-cost-{{ $i }}" :value="$stockOut ? '' : $cost['unitCost']" :disabled="$stockOut" :placeholder="$stockOut ? 'Automatic' : '0.00'" :invalid="$unitError"
                                    x-on:input.debounce.400ms="$wire.setCost({{ $i }}, 'unit', $event.target.value)" />
                                @if ($unitError)<p id="unit-cost-{{ $i }}-error" class="mt-1 text-xs font-medium text-danger">{{ $unitError }}</p>@endif
                            </div>

                            <div class="sm:col-span-2">
                                <label for="value-{{ $i }}" class="{{ $label }}">Total value</label>
                                <x-input amount id="value-{{ $i }}" :value="$stockOut ? '' : $cost['total']" :disabled="$stockOut" :placeholder="$stockOut ? 'Automatic' : '0.00'" :invalid="$totalError"
                                    x-on:input.debounce.400ms="$wire.setCost({{ $i }}, 'total', $event.target.value)" />
                                @if ($totalError)<p id="value-{{ $i }}-error" class="mt-1 text-xs font-medium text-danger">{{ $totalError }}</p>@endif
                            </div>

                            <div class="flex items-end sm:col-span-1">
                                <x-button variant="ghost" size="sm" wire:click="removeRow({{ $i }})" :disabled="count($rows) === 1" aria-label="Remove line {{ $i + 1 }}">✕</x-button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-3">
                    <x-button variant="secondary" size="sm" wire:click="addRow">Add another stock item</x-button>
                </div>

                <x-alert tone="info" class="mt-4">
                    When stock goes <strong>out</strong>, its value is calculated automatically from the weighted average cost — you never type it. When stock comes <strong>in</strong>, enter either the {{ $currency }} cost of one unit or the total value of the goods, and the other is worked out for you, so inventory and the accounts stay in step.
                </x-alert>
            </div>

            <div class="flex items-center gap-3">
                <x-button type="submit" size="lg" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="save">Save adjustment</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-button>
                <x-button variant="ghost" href="/inventory">Cancel</x-button>
            </div>
        </form>
    @endif
</div>
