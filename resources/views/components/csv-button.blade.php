{{--
    "Download CSV" for the list or report on screen, through the one export
    controller (GET /export/list/{list}, /export/report/{report}). Pass the
    SAME query the page parsed (the ListFilters result's `carried`, or the
    report's from/to), so the file is exactly the rows shown.

    <x-csv-button list="sales" :query="$list['carried']" />
    <x-csv-button report="profit-and-loss" :query="['from' => $range['from'], 'to' => $range['to']]" />
--}}
@props(['list' => null, 'report' => null, 'query' => []])
@php
    $path = $list !== null ? '/export/list/'.rawurlencode($list) : '/export/report/'.rawurlencode((string) $report);
    $query = \Illuminate\Support\Arr::except($query, ['page']);
@endphp
<x-button variant="secondary" size="sm" :href="$path.($query === [] ? '' : '?'.http_build_query($query))" download {{ $attributes }}>Download CSV</x-button>
