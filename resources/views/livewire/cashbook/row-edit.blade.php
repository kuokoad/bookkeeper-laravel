{{--
    D34: the owner correcting a saved expense or income in its own row. On
    save the old entry is reversed on its own day and the corrected one is
    posted under the same number. Needs CashbookPage's $edit and saveEdit().
--}}
@php($select = 'h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content')
<form wire:submit="saveEdit" novalidate class="space-y-4 rounded-xl border border-line bg-surface-raised p-4">
    <p class="text-sm font-semibold text-content">Edit {{ $what }}</p>
    @error('edit.form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    <div class="grid gap-4 sm:grid-cols-2">
        <x-field label="Date" for="edit-businessDate" name="edit.businessDate" required>
            <x-date-input id="edit-businessDate" wire:model="edit.businessDate" :value="$edit['businessDate'] ?? ''" :invalid="$errors->has('edit.businessDate')" required />
        </x-field>
        <x-field label="Category" for="edit-category" name="edit.categoryAccountId" required>
            <select id="edit-category" wire:model="edit.categoryAccountId" required class="{{ $select }}">
                @foreach ($categories as $category)
                    <option value="{{ $category['id'] }}">{{ $category['name'] }}</option>
                @endforeach
            </select>
        </x-field>
        <x-field label="Description" for="edit-description" name="edit.description" required class="sm:col-span-2">
            <x-input id="edit-description" wire:model="edit.description" required :invalid="$errors->has('edit.description')" />
        </x-field>
        <x-field label="Amount ({{ $currency }})" for="edit-amount" name="edit.amount" required>
            <x-input amount id="edit-amount" wire:model="edit.amount" required :invalid="$errors->has('edit.amount')" />
        </x-field>
        <x-field :label="$side['accountLabel']" for="edit-account" name="edit.paymentAccountId" required>
            <select id="edit-account" wire:model="edit.paymentAccountId" required class="{{ $select }}">
                @foreach ($accounts as $account)
                    <option value="{{ $account['id'] }}">{{ $account['name'] }}</option>
                @endforeach
            </select>
        </x-field>
        <x-field label="Reference" for="edit-reference" name="edit.reference">
            <x-input id="edit-reference" wire:model="edit.reference" placeholder="Optional" />
        </x-field>
        <x-field label="Note" for="edit-note" name="edit.note">
            <x-input id="edit-note" wire:model="edit.note" placeholder="Optional" />
        </x-field>
        <x-field label="Why are you editing it?" for="edit-reason" name="edit.reason" required class="sm:col-span-2">
            <x-input id="edit-reason" wire:model="edit.reason" placeholder="e.g. Typed 50 instead of 15" required :invalid="$errors->has('edit.reason')" />
        </x-field>
    </div>
    <div class="flex items-center gap-3">
        <x-button type="submit" wire:loading.attr="disabled">Save changes</x-button>
        <x-button variant="ghost" wire:click="cancelEdit">Cancel</x-button>
    </div>
    <x-edit-history :history="$editHistory" />
</form>
