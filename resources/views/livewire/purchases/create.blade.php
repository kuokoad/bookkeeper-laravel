@use('App\Domain\Money')
@use('App\Domain\Quantity')
@php
    $unitLabel = fn (bool $alt, array $p): string => $alt && $p['altUnit'] ? $p['altUnit'] : $p['unit'];
    $err = fn (string $id, ?string $message) => $message ? '<p id="'.e($id).'-error" class="mt-1 text-xs font-medium text-danger">'.e($message).'</p>' : '';
    $select = 'h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content';
@endphp
<div @class(['mx-auto', 'max-w-4xl' => $suppliers === [] || $products === [], 'max-w-5xl' => $suppliers !== [] && $products !== []])>
    @if ($suppliers === [])
        <x-page-header title="New purchase" />
        <x-empty-state title="Add a supplier first" description="A purchase records what you bought and from whom, so the money you owe can be tracked.">
            <x-slot:action><x-button href="/suppliers/new">Add a supplier</x-button></x-slot:action>
        </x-empty-state>
    @elseif ($products === [])
        <x-page-header title="New purchase" />
        <x-empty-state title="Add a product first" description="You can only record a purchase of something already in your product list.">
            <x-slot:action><x-button href="/products/new">Add a product</x-button></x-slot:action>
        </x-empty-state>
    @else
        <x-page-header title="New purchase" description="Record a delivery. Stock goes up at the price you actually paid.">
            <x-slot:actions><x-button variant="secondary" size="sm" href="/purchases">All purchases</x-button></x-slot:actions>
        </x-page-header>

        <form wire:submit="save" class="space-y-4" novalidate>
            @if ($savedNo)
                <x-alert tone="success" :title="'Purchase saved — '.$savedNo">
                    Stock was added and the accounts updated. <a href="/purchases/{{ $savedId }}" class="text-accent hover:underline">View it</a>
                </x-alert>
            @endif
            @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror

            <div class="rounded-xl border border-line bg-surface-raised p-4">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label for="supplier" class="mb-1 block text-sm font-medium text-content">Supplier <span class="text-danger">*</span></label>
                        <select id="supplier" wire:model.live="supplierId" class="{{ $select }}">
                            <option value="">Choose a supplier…</option>
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier['id'] }}">{{ $supplier['name'] }}{{ $supplier['balance'] > 0 ? ' — owed '.Money::toDecimalString($supplier['balance']) : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="date" class="mb-1 block text-sm font-medium text-content">Date <span class="text-danger">*</span></label>
                        <x-date-input id="date" wire:model.live="businessDate" :value="$businessDate" :invalid="$reading['dateError']"
                            :min="$lockedBefore ? \App\Domain\BusinessDate::addDays($lockedBefore, 1) : null" aria-label="Delivery date" />
                        {!! $err('date', $reading['dateError']) !!}
                        @if ($lockedBefore)<p class="mt-1 text-xs text-content-subtle">The books are closed up to @date($lockedBefore).</p>@endif
                    </div>
                    <div>
                        <label for="dueDate" class="mb-1 block text-sm font-medium text-content">Due date</label>
                        <x-date-input id="dueDate" wire:model.live="dueDate" :value="$dueDate" :min="$businessDate !== '' ? $businessDate : null" aria-describedby="dueDate-hint" />
                        <p id="dueDate-hint" class="mt-1 text-xs text-content-subtle">Optional: when the supplier wants paying. Payables ages the debt from this day.</p>
                    </div>
                    <div>
                        <label for="invoiceNo" class="mb-1 block text-sm font-medium text-content">Their invoice no.</label>
                        <x-input id="invoiceNo" wire:model.live.debounce.400ms="invoiceNo" placeholder="Optional" :invalid="$reading['invoiceNoError']" />
                        {!! $err('invoiceNo', $reading['invoiceNoError']) !!}
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-line bg-surface-raised p-4">
                <h2 class="mb-3 text-sm font-semibold text-content">What arrived</h2>
                <div class="space-y-3">
                    @foreach ($rows as $index => $row)
                        @php
                            $product = $products[$row['productId']] ?? null;
                            $line = $reading['lines'][$index];
                            $errors_ = $line['errors'];
                            $typedCost = ! $row['costFilled'] && trim($row['unitCost']) !== '';
                            $costProduct = $typedCost && $row['costFor'] !== '' && $row['costFor'] !== $row['productId'] ? ($products[$row['costFor']] ?? null) : null;
                            $costUnitChanged = $typedCost && $costProduct === null && $product !== null && $unitLabel($row['costAlt'], $product) !== $unitLabel($row['alt'], $product);
                            $k = $row['key'];
                        @endphp
                        <div wire:key="row-{{ $k }}" class="grid gap-3 rounded-lg border border-line p-3 sm:grid-cols-12">
                            <div class="sm:col-span-4">
                                <label for="p-{{ $k }}" class="mb-1 block text-xs font-medium text-content-muted">Product</label>
                                <select id="p-{{ $k }}" wire:model.live="rows.{{ $index }}.productId" @class([$select, 'border-danger' => isset($errors_['product'])]) @if (isset($errors_['product'])) aria-invalid="true" aria-describedby="p-{{ $k }}-error" @endif>
                                    <option value="">Choose…</option>
                                    @foreach ($products as $item)
                                        <option value="{{ $item['id'] }}">{{ $item['name'] }}</option>
                                    @endforeach
                                </select>
                                {!! $err("p-{$k}", $errors_['product'] ?? null) !!}
                            </div>

                            <div class="sm:col-span-2">
                                <label for="q-{{ $k }}" class="mb-1 block text-xs font-medium text-content-muted">Quantity {{ $product ? '('.$unitLabel($row['alt'], $product).')' : '' }}</label>
                                <x-input amount id="q-{{ $k }}" wire:model.live.debounce.400ms="rows.{{ $index }}.qty" :invalid="$errors_['qty'] ?? false" />
                                {!! $err("q-{$k}", $errors_['qty'] ?? null) !!}
                                @if ($product && $product['altUnit'])
                                    <div class="mt-1">
                                        <select id="u-{{ $k }}" aria-label="Unit" wire:model.live="rows.{{ $index }}.alt" class="h-10 w-full rounded-lg border border-line-strong bg-surface-raised px-2 text-sm text-content">
                                            <option value="0">{{ $product['unit'] }}</option>
                                            <option value="1">{{ $product['altUnit'] }} ({{ Quantity::format($product['altFactorMilli']) }} {{ $product['unit'] }})</option>
                                        </select>
                                    </div>
                                @endif
                            </div>

                            <div class="sm:col-span-2">
                                <label for="c-{{ $k }}" class="mb-1 block text-xs font-medium text-content-muted">{{ $product ? 'Cost per '.$unitLabel($row['alt'], $product) : 'Cost each' }}</label>
                                <x-input amount id="c-{{ $k }}" wire:model.live.debounce.400ms="rows.{{ $index }}.unitCost" :invalid="$errors_['cost'] ?? false" />
                                {!! $err("c-{$k}", $errors_['cost'] ?? null) !!}
                                @if ($line['free'])
                                    <p class="mt-1 text-xs font-medium text-warning">{{ $product && ! $product['trackInventory'] ? 'Free: nothing is charged for this line.' : 'Free goods. These bring the average cost down.' }}</p>
                                @endif
                                @if ($costProduct)
                                    <p class="mt-1 text-xs font-medium text-warning">Check this cost: it was typed for {{ $costProduct['name'] }}.</p>
                                @endif
                                @if ($costUnitChanged)
                                    <p class="mt-1 text-xs font-medium text-warning">Check this cost: it was typed per {{ $unitLabel($row['costAlt'], $product) }}, and the line is now per {{ $unitLabel($row['alt'], $product) }}.</p>
                                @endif
                            </div>

                            <div class="sm:col-span-2">
                                <label for="d-{{ $k }}" class="mb-1 block text-xs font-medium text-content-muted">Discount</label>
                                <x-input amount id="d-{{ $k }}" wire:model.live.debounce.400ms="rows.{{ $index }}.discount" placeholder="0.00" :invalid="$errors_['discount'] ?? false" />
                                {!! $err("d-{$k}", $errors_['discount'] ?? null) !!}
                            </div>

                            <div class="flex items-start justify-between gap-2 sm:col-span-2">
                                <div>
                                    <span class="mb-1 block text-xs font-medium text-content-muted">Line total</span>
                                    <span class="tabular flex h-11 items-center text-sm font-semibold text-content">{{ $line['total'] === null ? '—' : Money::toDecimalString($line['total']) }}</span>
                                </div>
                                <x-button variant="ghost" size="sm" class="mt-6" wire:click="removeRow({{ $index }})" :disabled="count($rows) === 1" aria-label="Remove line {{ $index + 1 }}">✕</x-button>
                            </div>

                            @if ($row['productId'] !== '')
                                <div class="sm:col-span-12">
                                    @if ($row['showExpiry'] || $row['expiryDate'] !== '')
                                        <div class="flex flex-wrap items-end gap-3">
                                            <div class="w-44">
                                                <label for="e-{{ $k }}" class="mb-1 block text-xs font-medium text-content-muted">Expires</label>
                                                <x-date-input id="e-{{ $k }}" wire:model.live="rows.{{ $index }}.expiryDate" :value="$row['expiryDate']" />
                                            </div>
                                            <x-button variant="ghost" size="sm" wire:click="clearExpiry({{ $index }})">No date</x-button>
                                            @if ($row['expiryDate'] !== '' && $row['expiryDate'] < $businessDate)
                                                <p class="text-xs font-medium text-warning">That date has already passed — these goods will count as expired.</p>
                                            @endif
                                        </div>
                                    @elseif ($offerExpiry)
                                        <button type="button" wire:click="showExpiry({{ $index }})" class="text-xs font-medium text-accent hover:underline">+ Expiry date</button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="mt-3">
                    <x-button variant="secondary" size="sm" wire:click="addRow">Add another line</x-button>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-line bg-surface-raised p-4">
                    <h2 class="mb-3 text-sm font-semibold text-content">Totals</h2>
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-content-muted">Subtotal</dt>
                            <dd class="tabular text-content">{{ $reading['subtotal'] === null ? '—' : Money::toDecimalString($reading['subtotal']) }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="pt-2 text-content-muted">Discount on the whole invoice</dt>
                            <dd class="w-40 text-right">
                                <x-input amount id="invoiceDiscount" wire:model.live.debounce.400ms="invoiceDiscount" placeholder="0.00" aria-label="Invoice discount" :invalid="$reading['invoiceDiscountError']" />
                                {!! $err('invoiceDiscount', $reading['invoiceDiscountError']) !!}
                            </dd>
                        </div>
                        @if ($reading['total'] === null)
                            {{-- The taxes still apply while the total cannot be worked out, so their rows stay, with a dash. --}}
                            @foreach ($taxComponents as $tax)
                                @if ($tax->rateBp > 0)
                                    <div class="flex justify-between"><dt class="text-content-muted">{{ $tax->name }}</dt><dd class="tabular text-content">—</dd></div>
                                @endif
                            @endforeach
                        @else
                            @foreach ($reading['taxLines'] as $tax)
                                @if ($tax->amount !== 0)
                                    <div class="flex justify-between"><dt class="text-content-muted">{{ $tax->component->name }}</dt><dd class="tabular text-content">{{ Money::toDecimalString($tax->amount) }}</dd></div>
                                @endif
                            @endforeach
                        @endif
                        <div class="flex justify-between border-t border-line pt-2 text-lg font-semibold">
                            <dt class="text-content">Total</dt>
                            <dd class="tabular text-content">{{ $reading['total'] === null ? '—' : $currency.' '.Money::toDecimalString($reading['total']) }}</dd>
                        </div>
                    </dl>
                    @if ($reading['totalError'])
                        <p id="total-error" class="mt-1.5 text-right text-xs font-medium text-danger">{{ $reading['totalError'] }}</p>
                    @endif
                </div>

                <div class="rounded-xl border border-line bg-surface-raised p-4">
                    <h2 class="mb-3 text-sm font-semibold text-content">Payment</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="payAccount" class="mb-1 block text-xs text-content-muted">Paid from</label>
                            <select id="payAccount" wire:model.live="payAccountId" class="{{ $select }}">
                                @foreach ($accounts as $account)
                                    <option value="{{ $account['id'] }}">{{ $account['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="payAmount" class="mb-1 block text-xs text-content-muted">Amount paid now</label>
                            <div class="flex gap-2">
                                <x-input amount id="payAmount" wire:model.live.debounce.400ms="payAmount" placeholder="0.00" :invalid="$reading['paidError']" />
                                <x-button variant="secondary" wire:click="payAll" :disabled="$reading['total'] === null || $reading['total'] === 0">All</x-button>
                            </div>
                            {!! $err('payAmount', $reading['paidError']) !!}
                        </div>
                        <div class="sm:col-span-2">
                            <label for="ref" class="mb-1 block text-xs text-content-muted">Reference</label>
                            <x-input id="ref" wire:model.live.debounce.400ms="reference" placeholder="Optional" :invalid="$reading['referenceError']" />
                            {!! $err('ref', $reading['referenceError']) !!}
                        </div>
                    </div>
                    <dl class="mt-3 space-y-1.5 border-t border-line pt-3 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-content-muted">Paid</dt>
                            <dd class="tabular text-content">{{ $reading['paid'] === null ? '—' : Money::toDecimalString($reading['paid']) }}</dd>
                        </div>
                        @if ($reading['outstanding'] !== null && $reading['outstanding'] > 0)
                            <div class="flex justify-between text-base font-semibold">
                                <dt class="text-warning">Still owed to supplier</dt>
                                <dd class="tabular text-warning">{{ Money::toDecimalString($reading['outstanding']) }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-button type="submit" size="lg" :disabled="$blocker !== null" wire:loading.attr="disabled" :aria-describedby="$blocker ? 'save-reason' : null">
                    <span wire:loading.remove wire:target="save">{{ $reading['total'] === null ? 'Save purchase' : 'Save purchase — '.$currency.' '.Money::toDecimalString($reading['total']) }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-button>
                <x-button variant="ghost" href="/purchases">Cancel</x-button>
                @if ($blocker)
                    <p id="save-reason" class="text-sm text-content-muted">{{ $blocker }}</p>
                @endif
            </div>
        </form>
    @endif
</div>
