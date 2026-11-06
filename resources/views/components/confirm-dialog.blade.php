{{--
    Two steps before anything irreversible (the old shared/confirm-void-form.tsx):
    a card with a button, which opens the real form. Attributes go on the
    <form>, so it serves Livewire and plain forms alike; the slot holds the
    fields (a reason, say).

    <x-confirm-dialog title="Void this sale" description="The sale stays on record, marked void, and its stock and money are put back."
        trigger="Void RCP-0042…" heading="Void RCP-0042?" wire:submit="void">
        <x-field label="Reason" for="void-reason" name="reason" required>
            <x-input id="void-reason" wire:model="reason" placeholder="e.g. Rung up twice" :invalid="$errors->has('reason')" />
        </x-field>
    </x-confirm-dialog>

    It opens by itself again only for ITS OWN errors (`keys`, default the
    reason and the form-level message), never for another form's on the page.
--}}
@props(['title', 'description', 'trigger', 'heading' => null, 'note' => 'Nothing is deleted.', 'confirm' => 'Yes, void it', 'tone' => 'danger', 'keys' => ['reason', 'form']])
<div x-data="{ confirming: {{ $errors->hasAny($keys) ? 'true' : 'false' }} }" class="no-print">
    <div x-show="! confirming" class="rounded-xl border border-line bg-surface-raised p-4">
        <h2 class="text-sm font-semibold text-content">{{ $title }}</h2>
        <p class="mt-1 mb-3 text-sm text-content-muted">{{ $description }}</p>
        <x-button variant="secondary" x-on:click="confirming = true">{{ $trigger }}</x-button>
    </div>
    <form x-show="confirming" x-cloak novalidate {{ $attributes->class(['rounded-xl border p-4', 'border-danger/40 bg-danger-soft' => $tone === 'danger', 'border-line bg-surface-raised' => $tone !== 'danger']) }}>
        <h2 class="text-sm font-semibold text-content">{{ $heading ?? $title }}</h2>
        @if ($note)<p class="mt-1 mb-3 text-sm text-content-muted">{{ $note }}</p>@endif
        @error('form')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror
        {{ $slot }}
        <div class="mt-4 flex items-center gap-3">
            <x-button type="submit" :variant="$tone === 'danger' ? 'danger' : 'primary'" wire:loading.attr="disabled">{{ $confirm }}</x-button>
            <x-button variant="ghost" x-on:click="confirming = false">Cancel</x-button>
        </div>
    </form>
</div>
