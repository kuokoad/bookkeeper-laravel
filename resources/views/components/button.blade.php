{{-- The old ui/button.tsx. Touch targets stay >= 44px on md/lg. Pass href to render a link styled as a button. --}}
@props(['variant' => 'primary', 'size' => 'md', 'href' => null])
@php
    $classes = 'inline-flex items-center justify-center gap-2 rounded-lg border font-medium transition-[colors,transform] duration-120 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100 '
        .match ($variant) {
            'primary' => 'bg-accent text-accent-text hover:opacity-90 border-transparent',
            'secondary' => 'bg-surface-raised text-content border-line-strong hover:bg-surface-sunken',
            'ghost' => 'bg-transparent text-content-muted border-transparent hover:bg-surface-sunken',
            'danger' => 'bg-danger text-white hover:opacity-90 border-transparent',
        }.' '
        .match ($size) {
            'sm' => 'h-9 px-3 text-sm',
            'md' => 'h-11 px-4 text-sm',
            'lg' => 'h-12 px-6 text-base',
        };
@endphp
@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($classes) }}>{{ $slot }}</button>
@endif
