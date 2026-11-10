@php
    $select = 'h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content';
    $label = 'mb-1 block text-xs font-medium text-content-muted';
@endphp
<div>
    <x-page-header title="Add stock items" description="Something new to sell. Add one row per stock item and save them together.">
        <x-slot:actions><x-button variant="secondary" size="sm" href="/products/import">Import from a spreadsheet</x-button></x-slot:actions>
    </x-page-header>

    <form wire:submit="save" class="space-y-4" novalidate>
        @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror

        <div class="rounded-xl border border-line bg-surface-raised p-4">
            <div class="space-y-3">
                @foreach ($rows as $index => $row)
                    <div wire:key="row-{{ $index }}" class="grid gap-3 border-b border-line pb-3 last:border-0 last:pb-0 sm:grid-cols-12">
                        <div class="sm:col-span-4">
                            <label for="n-{{ $index }}" class="{{ $label }}">Stock item name <span class="text-danger">*</span></label>
                            <x-input id="n-{{ $index }}" wire:model="rows.{{ $index }}.name" :autofocus="$index === 0" :invalid="$errors->first('rows.'.$index.'.name')" />
                            <x-field-error :id="'n-'.$index" :message="$errors->first('rows.'.$index.'.name')" />
                        </div>
                        <div class="sm:col-span-2">
                            <label for="g-{{ $index }}" class="{{ $label }}">Stock group</label>
                            <select id="g-{{ $index }}" wire:model="rows.{{ $index }}.categoryId" class="{{ $select }}">
                                <option value="">None</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="u-{{ $index }}" class="{{ $label }}">Unit <span class="text-danger">*</span></label>
                            <select id="u-{{ $index }}" wire:model="rows.{{ $index }}.unit" class="{{ $select }}">
                                @foreach ($units as $u)
                                    <option value="{{ $u->symbol }}">{{ $u->symbol }}</option>
                                @endforeach
                            </select>
                            <x-field-error :id="'u-'.$index" :message="$errors->first('rows.'.$index.'.unit')" />
                        </div>
                        <div class="sm:col-span-1">
                            <label for="c-{{ $index }}" class="{{ $label }}">Cost</label>
                            <x-input amount id="c-{{ $index }}" wire:model="rows.{{ $index }}.costPrice" :invalid="$errors->first('rows.'.$index.'.costPrice')" />
                            <x-field-error :id="'c-'.$index" :message="$errors->first('rows.'.$index.'.costPrice')" />
                        </div>
                        <div class="sm:col-span-1">
                            <label for="s-{{ $index }}" class="{{ $label }}">Selling</label>
                            <x-input amount id="s-{{ $index }}" wire:model="rows.{{ $index }}.sellingPrice" :invalid="$errors->first('rows.'.$index.'.sellingPrice')" />
                            <x-field-error :id="'s-'.$index" :message="$errors->first('rows.'.$index.'.sellingPrice')" />
                        </div>
                        <div class="flex items-start justify-between gap-2 sm:col-span-2">
                            <label class="mt-7 flex items-center gap-2 text-sm text-content">
                                <input type="checkbox" wire:model="rows.{{ $index }}.trackInventory" class="h-4 w-4 rounded border-line-strong">
                                Track stock
                            </label>
                            <x-button variant="ghost" size="sm" class="mt-6" wire:click="removeRow({{ $index }})" :disabled="count($rows) === 1" aria-label="Remove row {{ $index + 1 }}">✕</x-button>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-3">
                <x-button variant="secondary" size="sm" wire:click="addRow">Add another stock item</x-button>
            </div>
            <x-alert tone="info" class="mt-4">Stock quantity is not set here. A new stock item starts empty; enter opening stock with a stock adjustment so the value can always be traced back to a record. Set SKU, barcode, alternate unit, reorder level and expiry warning later from Edit. Untick Track stock for services or anything you do not count.</x-alert>
        </div>

        <div class="flex items-center gap-3">
            <x-button type="submit" size="lg" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">Create stock items</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-button>
            <x-button variant="ghost" href="/products">Cancel</x-button>
        </div>
    </form>
</div>
