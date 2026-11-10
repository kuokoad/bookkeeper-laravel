<div>
    <x-page-header title="Edit stock item" :description="$product['name']" />

    @include('livewire.products.partials.product-form', ['submitLabel' => 'Save changes'])

    @if ($offerReplace)
        <section class="mt-8 rounded-xl border border-line bg-surface-raised p-4">
            <h2 class="text-sm font-semibold text-content">Change units</h2>
            <p class="mt-1 mb-3 text-sm text-content-muted">
                The base unit is fixed because stock has moved. To count this stock item in another unit, replace it with a new one: its stock moves across at the same value, the SKU and barcode go with it, and this one is archived with its history.
            </p>
            <x-button variant="secondary" href="/products/{{ $product['id'] }}/replace">Replace with new units…</x-button>
        </section>
    @endif

    {{-- remove-product.tsx: delete what nothing refers to; archive what has history. --}}
    <section class="mt-8 rounded-xl border border-line bg-surface-raised p-4">
        <h2 class="text-sm font-semibold text-content">{{ $inUse ? ($product['isActive'] ? 'Archive stock item' : 'Restore stock item') : 'Delete stock item' }}</h2>
        <p class="mt-1 mb-3 text-sm text-content-muted">
            @if (! $product['isActive'])
                This stock item is archived. Restore it to sell it again.
            @elseif ($inUse)
                This stock item has history, so it cannot be deleted. Archive it to hide it from sales and lists; its history stays. A stock item that still has stock cannot be archived.
            @else
                This stock item has never been sold, bought, quoted or counted, so it can be deleted, for example if it is a duplicate.
            @endif
        </p>
        <div class="flex flex-wrap items-start gap-3">
            @if ($inUse || ! $product['isActive'])
                <div>
                    @error('remove')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror
                    <x-button variant="secondary" wire:click="setActive({{ $product['isActive'] ? 'false' : 'true' }})" wire:loading.attr="disabled">
                        {{ $product['isActive'] ? 'Archive stock item' : 'Restore stock item' }}
                    </x-button>
                </div>
            @endif
            @if (! $inUse)
                <div x-data="{ confirming: {{ $errors->has('delete') ? 'true' : 'false' }} }">
                    <x-button x-show="! confirming" variant="secondary" x-on:click="confirming = true">Delete stock item…</x-button>
                    <form x-show="confirming" x-cloak wire:submit="delete" class="rounded-xl border border-danger/40 bg-danger-soft p-4" novalidate>
                        <h3 class="text-sm font-semibold text-content">Delete “{{ $product['name'] }}”?</h3>
                        <p class="mt-1 mb-3 text-sm text-content-muted">This cannot be undone.</p>
                        @error('delete')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror
                        <div class="flex items-center gap-3">
                            <x-button type="submit" variant="danger" wire:loading.attr="disabled">Yes, delete it</x-button>
                            <x-button variant="ghost" x-on:click="confirming = false">Cancel</x-button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </section>
</div>
