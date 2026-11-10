@php
    $select = 'h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content';
    $label = 'mb-1 block text-xs font-medium text-content-muted';
@endphp
<div>
    <x-page-header title="Record expenses" description="Money spent running the shop — rent, power, transport, wages. Add one row per expense and save them together.">
        <x-slot:actions><x-button variant="secondary" size="sm" href="/expenses">All expenses</x-button></x-slot:actions>
    </x-page-header>

    @if ($categories === [])
        <x-empty-state title="Add an expense category first" description="Every expense goes under a category, so the profit report can show where the money went.">
            <x-slot:action><x-button href="/expenses">Add a category</x-button></x-slot:action>
        </x-empty-state>
    @else
        <form wire:submit="save" class="space-y-4" novalidate>
            @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror

            <div class="rounded-xl border border-line bg-surface-raised p-4">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-field label="Date" for="businessDate" required>
                        <x-date-input id="businessDate" wire:model="businessDate" :value="$businessDate" :invalid="$errors->has('businessDate')" required />
                    </x-field>
                    <x-field label="Paid from" for="paymentAccountId" required>
                        <select id="paymentAccountId" wire:model="paymentAccountId" required class="{{ $select }}">
                            @foreach ($accounts as $account)
                                <option value="{{ $account['id'] }}">{{ $account['name'] }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>
            </div>

            <div class="rounded-xl border border-line bg-surface-raised p-4">
                <div class="space-y-3">
                    @foreach ($rows as $index => $row)
                        <div wire:key="row-{{ $index }}" class="grid gap-3 border-b border-line pb-3 last:border-0 last:pb-0 sm:grid-cols-12">
                            <div class="sm:col-span-3">
                                <label for="cat-{{ $index }}" class="{{ $label }}">Category <span class="text-danger">*</span></label>
                                <select id="cat-{{ $index }}" wire:model="rows.{{ $index }}.categoryAccountId" class="{{ $select }}" @if ($errors->has('rows.'.$index.'.categoryAccountId')) aria-invalid="true" aria-describedby="cat-{{ $index }}-error" @endif>
                                    <option value="">Choose…</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category['id'] }}">{{ $category['name'] }}</option>
                                    @endforeach
                                </select>
                                <x-field-error :id="'cat-'.$index" :message="$errors->first('rows.'.$index.'.categoryAccountId')" />
                            </div>
                            <div class="sm:col-span-4">
                                <label for="desc-{{ $index }}" class="{{ $label }}">Description <span class="text-danger">*</span></label>
                                <x-input id="desc-{{ $index }}" wire:model="rows.{{ $index }}.description" placeholder="e.g. Taxi to the market" :invalid="$errors->first('rows.'.$index.'.description')" />
                                <x-field-error :id="'desc-'.$index" :message="$errors->first('rows.'.$index.'.description')" />
                            </div>
                            <div class="sm:col-span-2">
                                <label for="amt-{{ $index }}" class="{{ $label }}">Amount ({{ $currency }}) <span class="text-danger">*</span></label>
                                <x-input amount id="amt-{{ $index }}" wire:model.live.debounce.400ms="rows.{{ $index }}.amount" placeholder="0.00" :invalid="$errors->first('rows.'.$index.'.amount')" />
                                <x-field-error :id="'amt-'.$index" :message="$errors->first('rows.'.$index.'.amount')" />
                            </div>
                            <div class="flex items-start gap-2 sm:col-span-3">
                                <div class="flex-1">
                                    <label for="ref-{{ $index }}" class="{{ $label }}">Reference</label>
                                    <x-input id="ref-{{ $index }}" wire:model="rows.{{ $index }}.reference" placeholder="Optional" :invalid="$errors->first('rows.'.$index.'.reference')" />
                                    <x-field-error :id="'ref-'.$index" :message="$errors->first('rows.'.$index.'.reference')" />
                                </div>
                                <x-button variant="ghost" size="sm" class="mt-6" wire:click="removeRow({{ $index }})" :disabled="count($rows) === 1" aria-label="Remove row {{ $index + 1 }}">✕</x-button>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3 flex items-center justify-between gap-3">
                    <x-button variant="secondary" size="sm" wire:click="addRow">Add another expense</x-button>
                    <p class="text-sm text-content-muted">Total <span class="tabular font-semibold text-content">{{ \App\View\Format::money($total, currencyCode: $currency) }}</span></p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <x-button type="submit" size="lg" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="save">Record expenses</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-button>
                <x-button variant="ghost" href="/expenses">Cancel</x-button>
            </div>
        </form>
    @endif
</div>
