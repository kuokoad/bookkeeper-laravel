<div>
    @if ($supplierId === null)
        <x-page-header title="Add supplier" description="Someone you buy stock from." />
    @else
        <x-page-header title="Edit supplier" :description="$supplierName" />
    @endif

    <form wire:submit="save" class="space-y-6" novalidate>
        @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror

        <div class="rounded-xl border border-line bg-surface-raised p-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Supplier name" for="name" required class="sm:col-span-2">
                    <x-input id="name" wire:model="name" required autofocus :invalid="$errors->has('name')" />
                </x-field>
                <x-field label="Contact person" for="contactPerson">
                    <x-input id="contactPerson" wire:model="contactPerson" :invalid="$errors->has('contactPerson')" />
                </x-field>
                <x-field label="Phone" for="phone">
                    <x-input id="phone" type="tel" inputmode="tel" wire:model="phone" placeholder="e.g. 024 000 0000" :invalid="$errors->has('phone')" />
                </x-field>
                <x-field label="Email" for="email">
                    <x-input id="email" type="email" wire:model="email" :invalid="$errors->has('email')" />
                </x-field>
                <x-field label="Address" for="address">
                    <x-input id="address" wire:model="address" :invalid="$errors->has('address')" />
                </x-field>
                <x-field label="Notes" for="notes" class="sm:col-span-2">
                    <x-input id="notes" wire:model="notes" :invalid="$errors->has('notes')" />
                </x-field>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <x-button type="submit" size="lg" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">{{ $supplierId === null ? 'Add supplier' : 'Save changes' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-button>
            <x-button variant="ghost" :href="$supplierId === null ? '/suppliers' : '/suppliers/'.$supplierId">Cancel</x-button>
        </div>
    </form>
</div>
