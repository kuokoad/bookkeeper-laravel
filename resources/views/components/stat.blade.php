{{--
    The old ui/page.tsx Stat: one figure and its label. `value` arrives
    already formatted (Format::money); this never computes it.
--}}
@props(['label', 'value', 'hint' => null, 'tone' => 'default', 'icon' => null, 'size' => 'default'])
<div {{ $attributes->class('border border-line bg-surface-raised p-4') }} style="border-radius: var(--card-radius); box-shadow: var(--card-shadow)">
    <div class="flex items-center justify-between gap-2">
        <p class="text-[11px] font-semibold uppercase tracking-wider text-content-subtle">{{ $label }}</p>
        @if ($icon)
            <span @class(['flex shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent', 'h-7 w-7' => $size === 'compact', 'h-8 w-8' => $size !== 'compact']) aria-hidden="true">
                <x-icon :name="$icon" :class="$size === 'compact' ? 'h-4 w-4' : 'h-[18px] w-[18px]'" />
            </span>
        @endif
    </div>
    <p @class(['tabular mt-2 font-semibold', 'text-lg' => $size === 'compact', 'text-2xl' => $size !== 'compact',
        'text-danger' => $tone === 'danger', 'text-warning' => $tone === 'warning', 'text-success' => $tone === 'success', 'text-content' => $tone === 'default'])>{{ $value }}</p>
    @if ($hint)<p class="mt-0.5 text-xs text-content-muted">{{ $hint }}</p>@endif
</div>
