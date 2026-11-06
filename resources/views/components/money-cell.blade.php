{{--
    An amount in a table: minor units in, formatted by the domain (Format), red
    when negative. `bare` drops the currency code in a column headed GHS;
    `accounting` writes negatives as (1,250.00).
    <x-money-cell :value="$row['total']" />
--}}
@props(['value', 'bare' => false, 'accounting' => false])
<td {{ $attributes->class(['tabular px-3 py-2.5 text-right align-middle', 'text-danger' => $value < 0]) }}>{{ $accounting ? \App\View\Format::moneyAccounting($value, $bare) : \App\View\Format::money($value, $bare) }}</td>
