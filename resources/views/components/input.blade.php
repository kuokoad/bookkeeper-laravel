{{-- The old TextInput. `amount` makes it the right-aligned tabular box for money and quantities (AmountInput). --}}
{{-- `invalid` is true/false, or the message the caller shows under it as "<id>-error". Inside <x-field> the field's own error and hint are used. --}}
@props(['invalid' => false, 'amount' => false])
@aware(['for' => null, 'name' => null, 'hint' => null])
@php
    $id = $attributes->get('id');
    $inField = $id !== null && $id === $for;
    $message = $inField ? $errors->first($name ?? $for) : (is_string($invalid) ? $invalid : '');
    // Point only at text that is on screen: the error when there is one, else the field's hint.
    $describedBy = $message !== '' ? "{$id}-error" : ($inField && $hint ? "{$for}-hint" : null);
    $invalid = $invalid !== false && $invalid !== null && $invalid !== '';
@endphp
<input {{ $attributes->merge($amount ? ['inputmode' => 'decimal', 'autocomplete' => 'off'] : [])->class([
    'h-11 w-full rounded-lg border bg-surface-raised px-3 text-content placeholder:text-content-subtle focus:outline-none focus-visible:border-accent disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:opacity-60',
    'tabular text-right' => $amount,
    'border-danger' => $invalid,
    'border-line-strong' => ! $invalid,
]) }} @if ($invalid) aria-invalid="true" @endif @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
