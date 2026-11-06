{{-- The old (app)/search page. The form still works as a plain GET without JavaScript; with it, results follow the box. --}}
@use('App\View\Format')
<div class="w-full">
    <x-page-header title="Search" description="Stock items, customers, suppliers, receipts, purchases and journal entries." />

    <form method="GET" action="/search" role="search" class="mb-6 flex gap-2" wire:submit.prevent>
        <label for="q" class="sr-only">What are you looking for?</label>
        <input id="q" name="q" type="search" autofocus autocomplete="off" wire:model.live.debounce.180ms="q"
               placeholder="A stock item name, a customer, a receipt number…"
               class="h-11 flex-1 rounded-lg border border-line-strong bg-surface-raised px-3 text-content placeholder:text-content-subtle">
        <x-button type="submit">Search</x-button>
    </form>

    @if ($throttled)
        <x-alert tone="warning" class="mb-4">That is more searches than this can serve at once. Wait a moment and try again.</x-alert>
    @endif

    @if ($tooShort)
        <p class="mb-4 text-sm text-content-muted">Type at least two characters — a single letter would match almost everything.</p>
    @endif

    @if (! $throttled && mb_strlen($results['query']) >= 2 && $results['total'] === 0)
        <x-empty-state :title="'Nothing matches “'.$results['query'].'”'" description="Check the spelling, or try part of a name or number instead of the whole thing." />
    @endif

    @if ($results['total'] > 0)
        <p class="mb-4 text-sm text-content-muted">{{ $results['total'] }} match{{ $results['total'] === 1 ? '' : 'es' }} for “{{ $results['query'] }}”{{ $results['truncated'] ? ' — showing the first few of each kind' : '' }}</p>
        <div class="space-y-6">
            @foreach ($results['groups'] as $group)
                <section wire:key="group-{{ $group['label'] }}">
                    <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-content-subtle">{{ $group['label'] }}</h2>
                    <ul class="overflow-hidden rounded-xl border border-line bg-surface-raised">
                        @foreach ($group['hits'] as $hit)
                            <li class="border-b border-line last:border-b-0" wire:key="{{ $hit['kind'] }}-{{ $hit['id'] }}">
                                <a href="{{ $hit['href'] }}" class="flex items-center justify-between gap-3 px-4 py-3 transition-colors hover:bg-surface-sunken">
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-content">{{ $hit['title'] }}</span>
                                        <span class="block truncate text-xs text-content-muted">{{ $hit['detail'] }}</span>
                                    </span>
                                    @isset($hit['amount'])
                                        <span class="tabular shrink-0 text-sm font-medium text-content">{{ Format::money($hit['amount']) }}</span>
                                    @endisset
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif

    <p class="mt-6 text-xs text-content-subtle">You only see records you are allowed to open. Anything outside your access is not searched at all.</p>
</div>
