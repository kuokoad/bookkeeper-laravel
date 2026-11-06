{{-- One checkbox with its label and hint: the old settings-form Toggle. Expects $model, $label, $hint; optional $live. --}}
@php($id = 'toggle-'.str_replace('.', '-', $model))
<div class="flex items-start gap-3">
    <input id="{{ $id }}" type="checkbox" @if ($live ?? false) wire:model.live="{{ $model }}" @else wire:model="{{ $model }}" @endif
           class="mt-1 h-4 w-4 shrink-0 rounded border-line-strong accent-[var(--accent)]">
    <label for="{{ $id }}" class="text-sm">
        <span class="font-medium text-content">{{ $label }}</span>
        <span class="mt-0.5 block text-content-muted">{{ $hint }}</span>
    </label>
</div>
