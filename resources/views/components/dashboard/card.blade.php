{{-- The old components/dashboard/card.tsx DashboardCard. --}}
@props(['title', 'icon', 'href' => null, 'period' => null, 'embedded' => false])
<section class="{{ $embedded ? 'min-w-0' : 'min-w-0 border border-line bg-surface-raised p-4' }}" style="border-radius: var(--card-radius);{{ $embedded ? '' : ' box-shadow: var(--card-shadow)' }}">
    <div class="mb-2 flex items-center justify-between gap-3">
        <h3 class="text-sm font-semibold text-content">
            @if ($href)
                <a href="{{ $href }}" class="rounded hover:text-accent focus-visible:outline-2 focus-visible:outline-accent">{{ $title }}</a>
            @else
                {{ $title }}
            @endif
        </h3>
        <span class="flex h-5 w-5 shrink-0 items-center justify-center text-accent"><x-icon :name="$icon" class="h-5 w-5" /></span>
    </div>
    @if ($period)<p class="mb-2 text-xs text-content-muted">{{ $period }}</p>@endif
    {{ $slot }}
</section>
