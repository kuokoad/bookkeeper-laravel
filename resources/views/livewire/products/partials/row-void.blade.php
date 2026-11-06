{{--
    The old shared/row-void-form.tsx: voiding a row that has no page of its
    own. Two deliberate steps and a written reason, typed in the row itself.
    Errors arrive under "void.<id>".
    @include('livewire.products.partials.row-void', ['id' => 7, 'what' => 'SPY-0007', 'action' => 'voidPayment', 'model' => 'voidReasons', 'placeholder' => 'e.g. Paid the wrong supplier'])
--}}
@php($error = $errors->first("void.{$id}"))
<div x-data="{ confirming: {{ $error ? 'true' : 'false' }} }" class="no-print">
    <x-button x-show="! confirming" variant="ghost" size="sm" x-on:click="confirming = true" aria-label="Void {{ $what }}">Void</x-button>
    <form x-show="confirming" x-cloak wire:submit="{{ $action }}({{ $id }})" novalidate class="min-w-56">
        <p class="mb-1.5 text-xs text-content-muted">Void {{ $what }}? Nothing is deleted — a reversing entry is posted.</p>
        <div class="flex flex-wrap items-center gap-2">
            <x-input wire:model="{{ $model }}.{{ $id }}" :placeholder="$placeholder" required class="h-9 w-44" :invalid="(bool) $error" aria-label="Reason for voiding {{ $what }}" />
            <x-button type="submit" variant="danger" size="sm" wire:loading.attr="disabled">Void</x-button>
            <x-button variant="ghost" size="sm" x-on:click="confirming = false">Cancel</x-button>
        </div>
        @if ($error)<p class="mt-1 text-xs font-medium text-danger">{{ $error }}</p>@endif
    </form>
</div>
