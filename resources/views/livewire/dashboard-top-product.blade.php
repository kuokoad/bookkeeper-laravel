@if ($topProduct === null)
    <p class="text-sm text-content-muted">No product sales in this period.</p>
@else
    <p class="text-lg font-semibold text-content">{{ $topProduct['productName'] }}</p>
    <p class="mt-1 text-sm text-content-muted">@money($topProduct['revenue']) revenue · @qty($topProduct['qtySold'], $topProduct['unit']) sold</p>
    <p class="mt-2 text-xs text-content-subtle">Ranked by revenue, rather than units sold.</p>
@endif
