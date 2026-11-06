{{-- The old ui/badge.tsx. --}}
@props(['tone' => 'neutral'])
<span {{ $attributes->class(['inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap', match ($tone) {
    'success' => 'bg-success-soft text-content border-success/40',
    'warning' => 'bg-warning-soft text-content border-warning/40',
    'danger' => 'bg-danger-soft text-content border-danger/40',
    'accent' => 'bg-accent-soft text-content border-accent/40',
    default => 'bg-surface-sunken text-content-muted border-line',
}]) }}>{{ $slot }}</span>
