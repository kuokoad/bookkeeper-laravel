{{-- The old ui/page.tsx EmptyState: say what to do next, never a blank panel. Put a button in the `action` slot. --}}
@props(['title', 'description'])
<div {{ $attributes->class('rounded-[var(--panel-radius,var(--radius-xl))] border border-dashed border-line-strong bg-surface-raised px-6 py-12 text-center') }}>
    <p class="font-medium text-content">{{ $title }}</p>
    <p class="mx-auto mt-1 max-w-md text-sm text-content-muted">{{ $description }}</p>
    @isset($action)<div class="mt-4 flex justify-center">{{ $action }}</div>@endisset
</div>
