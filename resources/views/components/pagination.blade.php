{{--
    The old shared/pagination.tsx. `list` is the ListFilters parse result
    (page, pageSize, carried); `total` the service's count for the SAME
    filters. Links keep every filter and change only the page.
    <x-pagination :list="$list" :total="$total" noun="sale" />
--}}
@props(['list', 'total', 'noun' => 'result', 'nounPlural' => null])
@php
    $page = $list['page'];
    $pageSize = $list['pageSize'];
    $lastPage = max(1, (int) ceil($total / $pageSize));
    $first = $total === 0 ? 0 : ($page - 1) * $pageSize + 1;
    $last = min($page * $pageSize, $total);
    $plural = $nounPlural ?? $noun.'s';
    $href = fn (int $target): string => '?'.http_build_query([...\Illuminate\Support\Arr::except($list['carried'], ['page']), ...($target === 1 ? [] : ['page' => $target])]);
@endphp
<nav aria-label="Pagination" class="no-print mt-4 flex flex-wrap items-center justify-between gap-3">
    <p class="text-xs text-content-subtle" aria-live="polite">
        {{ $total === 0 ? "No {$plural}" : "Showing {$first}–{$last} of {$total} ".($total === 1 ? $noun : $plural) }}
    </p>
    @if ($lastPage > 1)
        <div class="flex items-center gap-2">
            @if ($page > 1)
                <x-button size="sm" variant="secondary" :href="$href($page - 1)" rel="prev">Previous</x-button>
            @else
                <x-button size="sm" variant="secondary" disabled>Previous</x-button>
            @endif
            <span class="text-xs text-content-muted">Page {{ $page }} of {{ $lastPage }}</span>
            @if ($page < $lastPage)
                <x-button size="sm" variant="secondary" :href="$href($page + 1)" rel="next">Next</x-button>
            @else
                <x-button size="sm" variant="secondary" disabled>Next</x-button>
            @endif
        </div>
    @endif
</nav>
