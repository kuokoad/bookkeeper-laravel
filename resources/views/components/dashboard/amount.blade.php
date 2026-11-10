{{-- The Modern look's amount: a small currency code and the figure on one line, red when negative. Formatting only. --}}
@props(['value'])
@use('App\View\Format')
<span {{ $attributes->class(['modern-amount tabular', 'text-danger' => $value < 0]) }}><span class="modern-cur">{{ Format::shopCurrency() }}</span>{{ Format::money($value, true) }}</span>
