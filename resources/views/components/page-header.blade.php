{{-- The old ui/page.tsx PageHeader. Put buttons in the `actions` slot. --}}
@props(['title', 'description' => null])
<header {{ $attributes->class('mb-6 flex flex-wrap items-start justify-between gap-3') }}>
    <div class="min-w-0">
        <h1 class="text-2xl font-semibold text-content">{{ $title }}</h1>
        @if ($description)<p class="mt-1 text-sm text-content-muted">{{ $description }}</p>@endif
    </div>
    @isset($actions)
        <div class="no-print flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</header>
