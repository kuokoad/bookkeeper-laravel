{{--
    A column heading, sortable when given `sort` (the old SortLink).

    <x-th sort="amount" :sorts="SaleService::SALE_SORTS" :list="$list" numeric>Total</x-th>

    `sorts` is the module's whitelist (the same constant ListFilters::parseX
    passes to Filters::parseSort), and a key outside it is refused here, so a
    heading can never offer a sort the server would quietly ignore. `list` is
    the parse result: its filters.sort / filters.direction say which way the
    rows ARE sorted (the resolved value, not the raw URL), and its `carried`
    keeps every other filter. The link drops `page`: a new order starts at 1.
--}}
@props(['sort' => null, 'sorts' => [], 'list' => null, 'numeric' => false, 'defaultDirection' => 'desc'])
@php
    if ($sort !== null && ! in_array($sort, $sorts, true)) {
        throw new InvalidArgumentException("Sort key \"{$sort}\" is not in this list's whitelist.");
    }
    $activeSort = $list['filters']['sort'] ?? null;
    $activeDirection = $list['filters']['direction'] ?? 'desc';
    $isActive = $sort !== null && $activeSort === $sort;
    $next = $isActive ? ($activeDirection === 'asc' ? 'desc' : 'asc') : $defaultDirection;
@endphp
<th scope="col" {{ $attributes->class(['px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-content-subtle', 'text-right' => $numeric]) }}
    @if ($sort !== null) aria-sort="{{ $isActive ? ($activeDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}" @endif>
    @if ($sort !== null)
        <a href="?{{ http_build_query([...\Illuminate\Support\Arr::except($list['carried'] ?? [], ['page']), 'sort' => $sort, 'direction' => $next]) }}" class="inline-flex items-center gap-1 hover:text-content">
            {{ $slot }}
            <span aria-hidden="true" class="{{ $isActive ? 'text-accent' : 'text-content-subtle/40' }}">{{ $isActive ? ($activeDirection === 'asc' ? '▲' : '▼') : '⇅' }}</span>
        </a>
    @else
        {{ $slot }}
    @endif
</th>
