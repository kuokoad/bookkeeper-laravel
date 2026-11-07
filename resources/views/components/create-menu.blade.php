{{-- Create Transaction: every kind of entry this person may start, on every look.
     A native <details>: opens with Enter or Space, no JavaScript needed; Alpine only closes it on Escape or a click elsewhere.
     With `primary` (a menu label) and `label`, it becomes a split button: the main part starts that entry, the arrow opens the menu.
     If this person may not start the primary entry, it falls back to the plain Create Transaction menu. --}}
@props(['size' => 'md', 'primary' => null, 'label' => null])
@php
    $items = \App\View\CreateMenu::items();
    $main = $primary === null ? null : collect($items)->firstWhere('label', $primary);
    $sizing = ['min-h-11 px-4 py-2 text-sm' => $size === 'md', 'h-9 px-3 text-sm' => $size === 'sm'];
    $button = 'inline-flex cursor-pointer list-none items-center gap-2 border border-accent bg-accent font-semibold text-accent-text hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent [&::-webkit-details-marker]:hidden';
@endphp
@if ($items !== [])
    <div class="relative inline-flex">
        @if ($main)
            <a href="{{ $main['href'] }}" @class([$button, 'rounded-l-lg', ...$sizing])>
                <x-icon name="add" class="h-4 w-4" />
                {{ $label }}
            </a>
        @endif
        <details class="create-menu" x-data x-on:click.outside="$el.open = false" x-on:keydown.escape="$el.open = false; $refs.summary.focus()">
            <summary x-ref="summary" @if ($main) aria-label="More entries" @endif @class([$button, ...$sizing,
                'rounded-lg' => ! $main,
                'rounded-r-lg border-l-accent-text/30 !px-2' => $main])>
                @unless ($main)
                    <x-icon name="add" class="h-4 w-4" />
                    Create Transaction
                @endunless
                <svg aria-hidden="true" viewBox="0 0 16 16" class="create-menu-chevron h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="m4 6 4 4 4-4"/></svg>
            </summary>
            <ul class="dropdown-panel absolute z-30 mt-2 w-[17.5rem] max-w-[calc(100vw-2rem)] max-sm:left-0 sm:right-0 border border-line bg-surface-raised p-1.5" aria-label="Create transaction">
                @foreach ($items as $item)
                    <li><a href="{{ $item['href'] }}" class="dropdown-item block rounded px-3 py-2 text-sm text-content hover:bg-accent-soft hover:text-accent focus-visible:bg-accent-soft focus-visible:text-accent focus-visible:outline-none">{{ $item['label'] }}</a></li>
                @endforeach
            </ul>
        </details>
    </div>
@endif
