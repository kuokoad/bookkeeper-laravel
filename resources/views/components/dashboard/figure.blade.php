{{-- The old DashboardFigure: an amount in minor units, formatted by the domain, red when negative. --}}
@props(['value', 'hint'])
<div>
    <p class="tabular break-words text-2xl font-semibold {{ $value < 0 ? 'text-danger' : 'text-content' }}">@money($value)</p>
    <p class="mt-1 text-xs text-content-muted">{{ $hint }}</p>
</div>
