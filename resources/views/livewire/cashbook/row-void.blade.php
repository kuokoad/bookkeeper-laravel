{{--
    The old shared/row-void-form.tsx: voiding a row that has no page of its own.
    Two deliberate steps and a written reason, typed in the row itself.
    @include('livewire.cashbook.row-void', ['id' => $row['id'], 'what' => 'EXP-0001'])
    The component needs `public array $reasons` and `void(int $id)`; errors are
    reason.{id} and void.{id}.
--}}
@php($open = $errors->has("reason.{$id}") || $errors->has("void.{$id}"))
<div x-data="{ confirming: {{ $open ? 'true' : 'false' }} }">
    <x-button variant="ghost" size="sm" x-show="! confirming" x-on:click="confirming = true" aria-label="Void {{ $what }}">Void</x-button>
    <form x-show="confirming" x-cloak wire:submit="{{ $action ?? 'void' }}({{ $id }})" novalidate class="min-w-56">
        <p class="mb-1.5 text-xs text-content-muted">Void {{ $what }}? Nothing is deleted — a reversing entry is posted.</p>
        <div class="flex flex-wrap items-center gap-2">
            <x-input wire:model="reasons.{{ $id }}" :placeholder="$placeholder ?? 'e.g. Entered twice'" required class="h-9 w-44"
                :invalid="$errors->has('reason.'.$id)" aria-label="Reason for voiding {{ $what }}" />
            <x-button type="submit" variant="danger" size="sm" wire:loading.attr="disabled">Void</x-button>
            <x-button variant="ghost" size="sm" x-on:click="confirming = false">Cancel</x-button>
        </div>
        @error("reason.{$id}")<p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>@enderror
        @error("void.{$id}")<p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>@enderror
    </form>
</div>
