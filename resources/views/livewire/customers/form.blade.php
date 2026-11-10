@php($editing = $customerId !== null)
<div>
    @if ($editing)
        <x-page-header title="Edit customer" :description="$name" />
    @else
        <x-page-header title="Add customer" description="Someone who buys from you regularly, or on credit." />
    @endif

    <form wire:submit="save" class="space-y-6" novalidate>
        @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror

        <div class="rounded-xl border border-line bg-surface-raised p-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Name" for="name" required class="sm:col-span-2">
                    <x-input id="name" wire:model="name" required autofocus :invalid="$errors->has('name')" />
                </x-field>
                <x-field label="Phone" for="phone">
                    <x-input id="phone" type="tel" inputmode="tel" wire:model="phone" placeholder="e.g. 024 000 0000" :invalid="$errors->has('phone')" />
                </x-field>
                <x-field label="Email" for="email">
                    <x-input id="email" type="email" wire:model="email" :invalid="$errors->has('email')" />
                </x-field>
                <x-field label="Address" for="address" class="sm:col-span-2">
                    <x-input id="address" wire:model="address" />
                </x-field>
                <x-field label="Notes" for="notes" class="sm:col-span-2">
                    <x-input id="notes" wire:model="notes" />
                </x-field>
            </div>
        </div>

        <div class="rounded-xl border border-line bg-surface-raised p-4">
            <h2 class="mb-3 text-sm font-semibold text-content">Credit</h2>
            <x-field label="Credit limit ({{ $currency }})" for="creditLimit" hint="Leave blank for no limit. Enter 0 to stop this customer buying on credit at all.">
                <x-input amount id="creditLimit" wire:model="creditLimit" placeholder="No limit" class="max-w-xs" :invalid="$errors->has('creditLimit')" />
            </x-field>
        </div>

        <div class="flex items-center gap-3">
            <x-button type="submit" size="lg" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">{{ $editing ? 'Save changes' : 'Add customer' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-button>
            <x-button variant="ghost" :href="$editing ? '/customers/'.$customerId : '/customers'">Cancel</x-button>
        </div>
    </form>
</div>
