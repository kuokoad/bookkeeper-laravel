{{--
    The old products/product-form.tsx fields (ProductFields), shared by add,
    edit and replace. Expects $categories, $units, $currency, $offerExpiry,
    $unitsLocked, $altUnitLocked, $submitLabel, $showStockNotice and, when
    replacing, $moveStock = ['current' => '12 pcs', 'hasStock' => true].
--}}
@php
    $moveStock ??= null;
    $selectClass = 'h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content disabled:cursor-not-allowed disabled:opacity-60';
@endphp
<form wire:submit="save" class="space-y-6" novalidate>
    @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    @if ($moveStock)
        <x-alert tone="info">The prices, the alternate-unit prices and the reorder level below are copied from the old stock item, per its old unit. Check each one for the new unit before you save.</x-alert>
    @endif

    <div class="rounded-xl border border-line bg-surface-raised p-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field label="Stock item name" for="name" required class="sm:col-span-2">
                <x-input id="name" wire:model="name" required autofocus :invalid="$errors->has('name')" />
            </x-field>
            <x-field label="Stock group" for="categoryId" hint="Optional. Manage the list under Stock groups.">
                <select id="categoryId" wire:model="categoryId" class="{{ $selectClass }}">
                    <option value="">No stock group</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field label="Unit" for="unit" required :hint="$unitsLocked ? 'Fixed — this stock item already has stock movements.' : 'What stock is counted in. Manage the list under Units of measure.'">
                <select id="unit" wire:model.live="unit" @disabled($unitsLocked) class="{{ $selectClass }}">
                    @foreach ($units as $u)
                        <option value="{{ $u->symbol }}">{{ $u->symbol }} — {{ $u->name }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field label="SKU" for="sku" hint="Optional short code.">
                <x-input id="sku" wire:model="sku" autocomplete="off" :invalid="$errors->has('sku')" />
            </x-field>
            <x-field label="Barcode" for="barcode" hint="Optional. Used for fast scanning at the till.">
                <x-input id="barcode" wire:model="barcode" autocomplete="off" inputmode="numeric" :invalid="$errors->has('barcode')" />
            </x-field>
        </div>
    </div>

    <div class="rounded-xl border border-line bg-surface-raised p-4">
        <h2 class="mb-3 text-sm font-semibold text-content">Prices</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field label="Cost price ({{ $currency }})" for="costPrice" required hint="What you expect to pay. Used to pre-fill purchases only — it never changes past profit.">
                <x-input amount id="costPrice" wire:model="costPrice" required :invalid="$errors->has('costPrice')" />
            </x-field>
            <x-field label="Selling price ({{ $currency }})" for="sellingPrice" required>
                <x-input amount id="sellingPrice" wire:model="sellingPrice" required :invalid="$errors->has('sellingPrice')" />
            </x-field>
        </div>
    </div>

    <div class="rounded-xl border border-line bg-surface-raised p-4">
        <h2 class="text-sm font-semibold text-content">Alternate unit</h2>
        <p class="mb-3 mt-1 text-xs text-content-subtle">
            Optional. Buy or sell this stock item in a second unit too — a Carton of 24, a Dozen. Stock is still counted in the unit above.@if ($altUnitLocked) Fixed — this stock item has been bought, sold or adjusted, so the alternate unit and conversion cannot change.@endif @if ($moveStock['hasStock'] ?? false) Check them before you save: moving the stock fixes them on the new stock item.@endif
        </p>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field label="Alternate unit" for="altUnit">
                <select id="altUnit" wire:model.live="altUnit" @disabled($altUnitLocked) class="{{ $selectClass }}">
                    <option value="">None</option>
                    @foreach ($units as $u)
                        <option value="{{ $u->symbol }}">{{ $u->symbol }} — {{ $u->name }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field label="Base units in one alternate unit" for="altFactor"
                :hint="$altUnit !== '' ? 'How many '.($unit ?: 'pcs').' in one '.$altUnit.'?' : '1 Carton = 24 '.($unit ?: 'pcs').' → enter 24.'">
                <x-input amount id="altFactor" wire:model="altFactor" :disabled="$altUnitLocked" :invalid="$errors->has('altFactor')" />
            </x-field>
            <x-field label="Cost per alternate unit ({{ $currency }})" for="altCostPrice" hint="Leave blank to use the cost price times the conversion.">
                <x-input amount id="altCostPrice" wire:model="altCostPrice" :invalid="$errors->has('altCostPrice')" />
            </x-field>
            <x-field label="Selling price per alternate unit ({{ $currency }})" for="altSellingPrice" hint="Often a bulk price. Blank uses the selling price times the conversion.">
                <x-input amount id="altSellingPrice" wire:model="altSellingPrice" :invalid="$errors->has('altSellingPrice')" />
            </x-field>
        </div>
    </div>

    <div class="rounded-xl border border-line bg-surface-raised p-4">
        <h2 class="mb-3 text-sm font-semibold text-content">Stock</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field label="Reorder level" for="minStock" hint="Warn me when stock falls to this. Leave blank to use the shop default.">
                <x-input amount id="minStock" wire:model="minStock" :invalid="$errors->has('minStock')" />
            </x-field>
            <div @if (! $offerExpiry) hidden @endif>
                <x-field label="Warn me this long before it expires" for="warnDays" hint="In days. Leave blank to use the shop default. Bread wants two or three; tinned goods can wait a month.">
                    <x-input id="warnDays" wire:model="warnDays" inputmode="numeric" placeholder="Shop default" :invalid="$errors->has('warnDays')" />
                </x-field>
            </div>
            <div class="flex items-start gap-3 pt-7">
                <input id="trackInventory" type="checkbox" wire:model="trackInventory" class="mt-0.5 h-4 w-4 rounded border-line-strong">
                <label for="trackInventory" class="text-sm text-content">
                    Track stock for this stock item
                    <span class="mt-0.5 block text-xs text-content-subtle">Turn off for services or anything you do not count.</span>
                </label>
            </div>
        </div>
        @if ($showStockNotice)
            <x-alert tone="info" class="mt-4">Stock quantity is not set here. A new stock item starts empty; enter opening stock with a stock adjustment so the value can always be traced back to a record.</x-alert>
        @endif
    </div>

    @if ($moveStock['hasStock'] ?? false)
        <div class="rounded-xl border border-line bg-surface-raised p-4">
            <h2 class="mb-3 text-sm font-semibold text-content">Stock to move across</h2>
            <x-field label="Quantity in the new unit" for="newQty" required
                hint="The old stock item holds {{ $moveStock['current'] }}. Count it in the new base unit: 5 cartons of 24 are 120 pcs. Its value moves across unchanged.">
                <x-input amount id="newQty" wire:model="newQty" required :invalid="$errors->has('newQty')" />
            </x-field>
        </div>
    @endif

    <div class="flex items-center gap-3">
        <x-button type="submit" size="lg" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="save">{{ $submitLabel }}</span>
            <span wire:loading wire:target="save">Saving…</span>
        </x-button>
        <x-button variant="ghost" href="/products">Cancel</x-button>
    </div>
</form>
