{{--
    The old ui/field.tsx: label, control, then the hint or the error. The
    error is the message for `name` (default: `for`) in Laravel's $errors bag,
    which a Livewire form fills from a ValidationError (docs/UI-CONVENTIONS.md).
--}}
@props(['label', 'for', 'name' => null, 'hint' => null, 'required' => false])
@php($message = $errors->first($name ?? $for))
<div {{ $attributes->class('space-y-1.5') }}>
    <label for="{{ $for }}" class="block text-sm font-medium text-content">
        {{ $label }}@if ($required)<span class="ml-1 text-danger" aria-hidden="true">*</span>@endif
    </label>
    {{ $slot }}
    @if ($hint && ! $message)<p class="text-xs text-content-subtle">{{ $hint }}</p>@endif
    @if ($message)<p id="{{ $for }}-error" role="alert" class="text-xs font-medium text-danger">{{ $message }}</p>@endif
</div>
