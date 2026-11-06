{{-- The fields shared by adding a tax and editing one (the old TaxFields). $prefix is "tax" or "newTax". --}}
<div class="grid gap-4 sm:grid-cols-2">
    <x-field label="Name" for="{{ $prefix }}-name" name="{{ $prefix }}.name" hint="Appears on the receipt." required>
        <x-input id="{{ $prefix }}-name" wire:model="{{ $prefix }}.name" maxlength="60" required />
    </x-field>
    <x-field label="Code" for="{{ $prefix }}-code" name="{{ $prefix }}.code" hint="Short, for returns and reports. VAT, NHIL." required>
        <x-input id="{{ $prefix }}-code" wire:model="{{ $prefix }}.code" maxlength="20" class="uppercase" required />
    </x-field>
    <x-field label="Rate" for="{{ $prefix }}-rate" name="{{ $prefix }}.rate" hint="A percentage, like 15 or 2.5." required>
        <x-input id="{{ $prefix }}-rate" wire:model="{{ $prefix }}.rate" inputmode="decimal" class="tabular" :invalid="$errors->has($prefix.'.rate')" required />
    </x-field>
    <x-field label="Charged on" for="{{ $prefix }}-basis" name="{{ $prefix }}.basis" hint="Ghana's GRA charges VAT on the value plus the levies.">
        <select id="{{ $prefix }}-basis" wire:model="{{ $prefix }}.basis" class="h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-sm text-content">
            <option value="NET">the goods value</option>
            <option value="NET_PLUS_LEVIES">the value plus the taxes above it</option>
        </select>
    </x-field>
    <x-field label="Held in" for="{{ $prefix }}-account" name="{{ $prefix }}.glAccountId" hint="Where it waits until it is remitted. Must be a liability." required>
        <select id="{{ $prefix }}-account" wire:model="{{ $prefix }}.glAccountId" required class="h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-sm text-content">
            <option value="">Choose an account…</option>
            @foreach ($accounts as $account)
                <option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>
            @endforeach
        </select>
    </x-field>
    <x-field label="Order" for="{{ $prefix }}-order" name="{{ $prefix }}.sortOrder" hint="Where it sits on the receipt. Lower is higher.">
        <x-input id="{{ $prefix }}-order" wire:model="{{ $prefix }}.sortOrder" inputmode="numeric" class="tabular" />
    </x-field>
    <div class="sm:col-span-2">
        @include('livewire.settings.partials.toggle', ['model' => "{$prefix}.isRecoverable", 'label' => 'Reclaimable on purchases', 'hint' => 'On for VAT. Off for the levies, which are part of what the goods cost.'])
    </div>
</div>
