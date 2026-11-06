<div class="w-full">
    <x-page-header title="Units" description="What stock is counted in, and how many decimals each allows. Pcs takes 0, Kg takes 3.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/products">Back to products</x-button>
        </x-slot:actions>
    </x-page-header>

    @php
        $canCreate = Gate::allows('products.create');
        $canEdit = Gate::allows('products.edit');
    @endphp

    <div class="space-y-2">
        @foreach ($forms as $i => $form)
            @php
                $adding = $form['original'] === null;
            @endphp
            @continue($adding && ! $canCreate)
            @if ($adding && $i === 0)<div class="mb-6">@endif
            @if ($adding || $canEdit)
                <form wire:submit="saveUnit({{ $i }})" wire:key="unit-{{ $i }}-{{ $form['original'] }}" class="rounded-xl border border-line bg-surface-raised p-4" novalidate>
                    @error("forms.{$i}")<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror
                    <div class="flex flex-wrap items-end gap-3">
                        <x-field :label="$adding ? 'New unit' : 'Symbol'" for="symbol-{{ $i }}" name="forms.{{ $i }}.symbol" required class="w-32">
                            <x-input id="symbol-{{ $i }}" wire:model="forms.{{ $i }}.symbol" placeholder="e.g. ctn" required autocomplete="off" :invalid="$errors->has('forms.'.$i.'.symbol')" />
                        </x-field>
                        <x-field label="Name" for="name-{{ $i }}" name="forms.{{ $i }}.name" required class="min-w-[10rem] flex-1">
                            <x-input id="name-{{ $i }}" wire:model="forms.{{ $i }}.name" placeholder="e.g. Carton" required autocomplete="off" :invalid="$errors->has('forms.'.$i.'.name')" />
                        </x-field>
                        <x-field label="Decimal places" for="decimalPlaces-{{ $i }}" name="forms.{{ $i }}.decimalPlaces" class="w-40">
                            <select id="decimalPlaces-{{ $i }}" wire:model="forms.{{ $i }}.decimalPlaces" class="h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content">
                                <option value="0">0 (whole)</option>
                                <option value="1">1</option>
                                <option value="2">2</option>
                                <option value="3">3</option>
                            </select>
                        </x-field>
                        <x-button type="submit" :variant="$adding ? 'primary' : 'secondary'" wire:loading.attr="disabled">{{ $adding ? 'Add unit' : 'Save' }}</x-button>
                        @unless ($adding)
                            {{-- One fixed-width slot whether or not Delete shows, so every row's columns line up. --}}
                            <div class="flex w-44 items-end gap-3">
                                <span class="tabular w-20 whitespace-nowrap pb-3 text-xs text-content-subtle">{{ $form['productCount'] }} {{ \App\View\Format::plural($form['productCount'], 'product') }}</span>
                                @if ($form['productCount'] === 0)
                                    <x-button variant="ghost" wire:click="deleteUnit({{ $i }})">Delete</x-button>
                                @endif
                            </div>
                        @endunless
                    </div>
                </form>
            @else
                <div wire:key="unit-{{ $i }}" class="flex items-center justify-between rounded-xl border border-line bg-surface-raised px-4 py-3 text-sm">
                    <span class="font-medium text-content">{{ $form['symbol'] }} <span class="font-normal text-content-subtle">— {{ $form['name'] }}</span></span>
                    <span class="tabular text-content-subtle">{{ $form['decimalPlaces'] }} dp · {{ $form['productCount'] }} products</span>
                </div>
            @endif
            @if ($adding && $i === 0)</div>@endif
        @endforeach
    </div>

    <p class="mt-4 text-xs text-content-subtle">
        Renaming a unit renames it on every product that uses it. Receipts already printed keep the name they were printed with. A unit in use cannot be deleted.
    </p>
</div>
