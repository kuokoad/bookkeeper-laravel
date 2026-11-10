{{-- The old PeriodPicker: 7d / 30d / 90d for one card, keeping the other card's window. --}}
@php($current = $param === 'sales' ? $this->sales : $this->spend)
<nav aria-label="{{ $label }} reporting period" class="mb-3 flex flex-wrap items-center gap-1 text-xs">
    <span class="sr-only">{{ $label }}</span>
    @foreach (['week' => '7d', 'month' => '30d', 'quarter' => '90d'] as $key => $text)
        <a href="{{ \App\Domain\Dashboard::dashboardRangeHref($param === 'sales' ? $key : $this->sales, $param === 'spend' ? $key : $this->spend, $this->activity) }}"
           @if ($current === $key) aria-current="true" @endif
           @class(['rounded-lg px-3 py-2 focus-visible:outline-2 focus-visible:outline-accent', 'bg-accent-soft font-semibold text-accent' => $current === $key, 'text-content-muted hover:bg-surface-sunken' => $current !== $key])>{{ $text }}</a>
    @endforeach
</nav>
