<div class="w-full">
    <x-page-header title="Stock groups" description="Group your stock items however suits the shop. Nothing here is fixed.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/products">Back to stock items</x-button>
        </x-slot:actions>
    </x-page-header>

    @can('products.create')
        <form wire:submit="add" class="rounded-xl border border-line bg-surface-raised p-4" novalidate>
            @error('form')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror
            <div class="flex flex-wrap items-end gap-3">
                <x-field label="New stock group" for="name" required class="min-w-[12rem] flex-1">
                    <x-input id="name" wire:model="name" placeholder="e.g. Drinks" required autocomplete="off" :invalid="$errors->has('name')" />
                </x-field>
                <x-field label="Description" for="description" class="min-w-[12rem] flex-1">
                    <x-input id="description" wire:model="description" placeholder="Optional" autocomplete="off" :invalid="$errors->has('description')" />
                </x-field>
                <x-button type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="add">Add stock group</span>
                    <span wire:loading wire:target="add">Adding…</span>
                </x-button>
            </div>
        </form>
    @endcan

    <x-table class="mt-6">
        <x-slot:head>
            <x-th>Stock group</x-th>
            <x-th numeric>Stock items</x-th>
            <x-th>Status</x-th>
        </x-slot:head>
        @forelse ($categories as $category)
            <tr class="border-b border-line last:border-0" wire:key="category-{{ $category->id }}">
                <x-td>
                    <div class="font-medium text-content">{{ $category->name }}</div>
                    @if ($category->description)<div class="mt-0.5 text-xs text-content-subtle">{{ $category->description }}</div>@endif
                </x-td>
                <x-td numeric>{{ $category->productCount }}</x-td>
                <x-td>
                    @if ($category->is_active)
                        <x-badge tone="success">Active</x-badge>
                    @else
                        <x-badge tone="neutral">Archived</x-badge>
                    @endif
                </x-td>
            </tr>
        @empty
            <x-empty-row colspan="3">No stock groups yet. Drinks, Food, Household — whatever fits your shop.</x-empty-row>
        @endforelse
    </x-table>

    <p class="mt-4 text-xs text-content-subtle">
        Stock groups are archived rather than deleted, so stock items keep their history and old reports stay readable.
    </p>
</div>
