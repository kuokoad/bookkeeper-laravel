{{-- The old ui/page.tsx Card. Radius and shadow are look tokens, so a look can restyle every card. --}}
@props(['title' => null])
<section {{ $attributes->class('border border-line bg-surface-raised p-4') }} style="border-radius: var(--card-radius); box-shadow: var(--card-shadow)">
    @if ($title)<h2 class="mb-3 text-sm font-semibold text-content">{{ $title }}</h2>@endif
    {{ $slot }}
</section>
