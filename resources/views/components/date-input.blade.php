{{--
    A trading date (YYYY-MM-DD, the shop's own day). Native <input type="date">:
    the browser's own picker, keyboard and phone wheel. `value` must be the
    resolved date the server used, not the raw query string (filter rule 5).
    <x-date-input id="from" name="from" :value="$list['range']['from']" :max="$today" />
--}}
@props(['value' => null, 'invalid' => false])
@aware(['for' => null, 'name' => null, 'hint' => null])
@php
    // As <x-input>: `invalid` may be the message shown as "<id>-error"; inside <x-field>, its error or hint.
    $id = $attributes->get('id');
    $inField = $id !== null && $id === $for;
    $message = $inField ? $errors->first($name ?? $for) : (is_string($invalid) ? $invalid : '');
    $describedBy = $message !== '' ? "{$id}-error" : ($inField && $hint ? "{$for}-hint" : null);
    $invalid = $invalid !== false && $invalid !== null && $invalid !== '';
@endphp
<span class="date-input-frame block">
    <input type="date" value="{{ $value }}" {{ $attributes->class([
        'tabular h-11 w-full rounded-lg border bg-surface-raised px-3 text-content focus:outline-none focus-visible:border-accent disabled:cursor-not-allowed disabled:opacity-60',
        'border-danger' => $invalid,
        'border-line-strong' => ! $invalid,
    ]) }} @if ($invalid) aria-invalid="true" @endif @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
</span>
