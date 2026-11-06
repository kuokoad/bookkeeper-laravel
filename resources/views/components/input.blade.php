{{-- The old TextInput. `amount` makes it the right-aligned tabular box for money and quantities (AmountInput). --}}
@props(['invalid' => false, 'amount' => false])
<input {{ $attributes->merge($amount ? ['inputmode' => 'decimal', 'autocomplete' => 'off'] : [])->class([
    'h-11 w-full rounded-lg border bg-surface-raised px-3 text-content placeholder:text-content-subtle focus:outline-none focus-visible:border-accent disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:opacity-60',
    'tabular text-right' => $amount,
    'border-danger' => $invalid,
    'border-line-strong' => ! $invalid,
]) }} @if ($invalid) aria-invalid="true" aria-describedby="{{ $attributes->get('id') }}-error" @endif>
