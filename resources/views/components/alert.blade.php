{{-- The old ui/alert.tsx. Errors are shown, never swallowed; danger and warning announce themselves. --}}
@props(['tone' => 'info', 'title' => null])
<div role="{{ in_array($tone, ['danger', 'warning'], true) ? 'alert' : 'status' }}"
     {{ $attributes->class(['motion-appear rounded-lg border px-4 py-3 text-sm', match ($tone) {
         'success' => 'bg-success-soft border-success/40 text-content',
         'warning' => 'bg-warning-soft border-warning/40 text-content',
         'danger' => 'bg-danger-soft border-danger/40 text-content',
         default => 'bg-surface-sunken border-line-strong text-content',
     }]) }}>
    @if ($title)<p class="mb-0.5 font-semibold">{{ $title }}</p>@endif
    <div class="text-content-muted">{{ $slot }}</div>
</div>
