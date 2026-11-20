{{--
    Pages through a secondary list that lives on a page with other things on
    it, by Livewire action instead of a link (x-pagination is for the page's
    own list, in the address). `list` is ['page', 'pageSize']; `total` the
    count of the SAME rows; `newer` / `older` the component's actions.
    <x-step-pager :list="$historyList" :total="$historyTotal" noun="count" newer="newerCounts" older="olderCounts" />
--}}
@props(['list', 'total', 'noun' => 'record', 'newer', 'older'])
@php
    $page = $list['page'];
    $pageSize = $list['pageSize'];
    $first = $total === 0 ? 0 : ($page - 1) * $pageSize + 1;
    $last = min($page * $pageSize, $total);
@endphp
@if ($total > $pageSize)
    <nav aria-label="Older and newer {{ $noun }}s" class="no-print mt-3 flex flex-wrap items-center justify-between gap-3">
        <p class="text-xs text-content-subtle" aria-live="polite">Showing {{ $first }}–{{ $last }} of {{ $total }} {{ $noun }}s</p>
        <div class="flex items-center gap-2">
            <x-button size="sm" variant="secondary" wire:click="{{ $newer }}" :disabled="$page <= 1">Newer</x-button>
            <x-button size="sm" variant="secondary" wire:click="{{ $older }}" :disabled="$last >= $total">Older</x-button>
        </div>
    </nav>
@endif
