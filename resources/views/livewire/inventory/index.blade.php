@php
    use App\Domain\Quantity;
    use App\Reports\Csv\ListExport;
    use App\View\Format;
    use Illuminate\Support\Str;
@endphp
<div class="w-full">
    <x-page-header title="Inventory" description="Every movement of stock, and why it moved.">
        <x-slot:actions>
            <x-csv-button list="stock-movements" :query="$list['carried']" />
            <x-button variant="secondary" size="sm" href="/inventory/adjustments">Adjustments</x-button>
            @can('inventory.create')
                <x-button size="sm" href="/inventory/adjustments/new">New adjustment</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($drifted !== [])
        <x-alert tone="danger" title="Stock records disagree with the ledger" class="mb-4">
            {{ count($drifted) }} product(s) have a cached stock figure that does not match the last balance recorded in the ledger. This should never happen. Affected:
            {{ implode(', ', array_column($drifted, 'productName')) }}. Run <code>php artisan nuna:preflight</code> to replay the full movement history and see where the two parted company.
        </x-alert>
    @endif

    @if ($inventoryCache !== $inventoryGl)
        <x-alert tone="danger" title="Inventory does not match the accounts" class="mb-4">
            Stock is valued at {{ Format::money($inventoryCache) }} but the Inventory ledger account holds {{ Format::money($inventoryGl) }}. Please report this before recording anything else.
        </x-alert>
    @endif

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Stock value" icon="inventory" :value="Format::money($summary['totalStockValue'])" hint="Weighted average cost" />
        <x-stat label="Tracked stock items" icon="products" :value="(string) $summary['trackedCount']" />
        <x-stat label="Low stock" icon="warning" :value="(string) $summary['lowStockCount']" :tone="$summary['lowStockCount'] > 0 ? 'warning' : 'default'" />
        <x-stat label="Out of stock" icon="warning" :value="(string) $summary['outOfStockCount']" :tone="$summary['outOfStockCount'] > 0 ? 'danger' : 'default'" />
    </div>

    @if ($focus !== null && $batches !== [])
        <section class="mb-6" aria-labelledby="batches-heading">
            <h2 id="batches-heading" class="mb-3 text-sm font-semibold text-content">Batches on the shelf</h2>
            <x-table>
                <x-slot:head>
                    <x-th>Batch</x-th>
                    <x-th>Expires</x-th>
                    <x-th>From</x-th>
                    <x-th numeric>Remaining</x-th>
                    <x-th numeric>Days left</x-th>
                </x-slot:head>
                @foreach ($batches as $batch)
                    <tr class="border-b border-line last:border-0" wire:key="batch-{{ $batch['id'] }}">
                        <x-td><a href="/inventory/batches/{{ $batch['id'] }}" class="font-medium text-accent hover:underline">{{ $batch['batchRef'] }}</a></x-td>
                        <x-td>
                            @if ($batch['expiryDate'] === null)
                                <span class="text-content-subtle">No date</span>
                            @else
                                @date($batch['expiryDate'])
                            @endif
                        </x-td>
                        <x-td>{!! $batch['supplierName'] === null ? '<span class="text-content-subtle">—</span>' : e($batch['supplierName']) !!}</x-td>
                        <x-td numeric>{{ Format::qty($batch['qtyMilli'], $focus['unit']) }}</x-td>
                        <x-td numeric>
                            @if ($batch['daysLeft'] === null)
                                <span class="text-content-subtle">—</span>
                            @elseif ($batch['daysLeft'] < 0)
                                <x-badge tone="danger">Expired</x-badge>
                            @elseif ($batch['daysLeft'] <= 30)
                                <span class="font-medium text-warning">{{ $batch['daysLeft'] }}</span>
                            @else
                                <span class="text-content">{{ $batch['daysLeft'] }}</span>
                            @endif
                        </x-td>
                    </tr>
                @endforeach
            </x-table>
        </section>
    @endif

    @if ($lowStock !== [])
        <section class="mb-6" aria-labelledby="low-stock-heading">
            <h2 id="low-stock-heading" class="mb-3 text-sm font-semibold text-content">Running low</h2>
            <x-table>
                <x-slot:head>
                    <x-th>Stock item</x-th>
                    <x-th numeric>In stock</x-th>
                    <x-th numeric>Reorder at</x-th>
                    <x-th />
                </x-slot:head>
                @foreach ($lowStock as $item)
                    <tr class="border-b border-line last:border-0" wire:key="low-{{ $item['id'] }}">
                        <x-td><span class="font-medium text-content">{{ $item['name'] }}</span></x-td>
                        <x-td numeric>
                            <span class="inline-flex items-center gap-2">
                                {{ Quantity::formatInBothUnits($item['qtyOnHand'], $item['unit'], $item['altUnit'], $item['altFactor']) }}
                                <x-badge :tone="$item['outOfStock'] ? 'danger' : 'warning'">{{ $item['outOfStock'] ? 'Out' : 'Low' }}</x-badge>
                            </span>
                        </x-td>
                        <x-td numeric>
                            @if ($item['minStock'] === null)
                                <span class="text-content-subtle">Shop default</span>
                            @else
                                {{ Format::qty($item['minStock'], $item['unit']) }}
                            @endif
                        </x-td>
                        <x-td><div class="flex justify-end"><a href="/inventory?product={{ $item['id'] }}" class="text-xs font-medium text-accent hover:underline">History</a></div></x-td>
                    </tr>
                @endforeach
            </x-table>
        </section>
    @endif

    <section aria-labelledby="ledger-heading">
        <h2 id="ledger-heading" class="mb-3 text-sm font-semibold text-content">
            Stock ledger
            @if ($focus !== null)<span class="ml-2 font-normal text-content-muted">— {{ $focus['name'] }}</span>@endif
        </h2>

        @php
            $quick = [
            ['label' => 'Sales', 'params' => ['movement' => 'SALE']],
            ['label' => 'Purchases', 'params' => ['movement' => 'PURCHASE']],
            ['label' => 'Customer returns', 'params' => ['movement' => 'SALE_RETURN']],
            ['label' => 'Adjustments out', 'params' => ['movement' => 'ADJUSTMENT_OUT']],
        ];
        @endphp

        <x-filter-bar compact :quick="$quick" :list="$list" :dates="true" :fields="[
            ['kind' => 'search', 'key' => 'q', 'label' => 'Search', 'placeholder' => 'Stock item, SKU, reference or note', 'wide' => true, 'value' => $list['filters']['search'] ?? ''],
            ['kind' => 'select', 'key' => 'product', 'label' => 'Stock item', 'allLabel' => 'All stock items', 'options' => collect($products)->pluck('name', 'id')->all(), 'value' => $list['filters']['productId'] ?? null],
            ['kind' => 'select', 'key' => 'category', 'label' => 'Stock group', 'allLabel' => 'All stock groups', 'options' => collect($categories)->pluck('name', 'id')->all(), 'value' => $list['filters']['categoryId'] ?? null],
            ['kind' => 'select', 'key' => 'movement', 'label' => 'Movement type', 'allLabel' => 'All movements', 'options' => ListExport::MOVEMENT_LABELS, 'value' => $list['filters']['movementType'] ?? null],
            ['kind' => 'select', 'key' => 'user', 'label' => 'Recorded by', 'allLabel' => 'Anyone', 'options' => collect($staff)->pluck('displayName', 'id')->all(), 'value' => $list['filters']['userId'] ?? null],
        ]" />

        @if ($ledger === [])
            <x-empty-state :title="$isFiltered ? 'No movements match these filters' : 'No stock movements yet'"
                :description="$isFiltered ? 'Try widening the dates, or clear a filter to see more.' : 'Once you record opening stock, a purchase or a sale, every change will appear here with its running balance.'">
                @if (! $isFiltered && Gate::allows('inventory.create'))
                    <x-slot:action><x-button href="/inventory/adjustments/new">Record opening stock</x-button></x-slot:action>
                @endif
            </x-empty-state>
        @else
            <x-table>
                <x-slot:head>
                    <x-th>When</x-th>
                    <x-th>Stock item</x-th>
                    <x-th>Movement</x-th>
                    <x-th>Reference</x-th>
                    <x-th numeric>In</x-th>
                    <x-th numeric>Out</x-th>
                    <x-th numeric>Value</x-th>
                    <x-th numeric>Balance</x-th>
                    <x-th numeric>Stock value</x-th>
                </x-slot:head>
                @foreach ($ledger as $row)
                    <tr class="border-b border-line last:border-0" wire:key="move-{{ $row['id'] }}">
                        {{-- The day the stock moved (what the filter uses); only the time comes from occurredAt. --}}
                        <x-td><span class="whitespace-nowrap text-content-muted">@date($row['businessDate']) {{ Str::after(Format::dateTime($row['occurredAt']), ', ') }}</span></x-td>
                        <x-td><span class="font-medium text-content">{{ $row['productName'] }}</span></x-td>
                        <x-td><x-badge :tone="$row['qtyIn'] > 0 ? 'success' : 'warning'">{{ ListExport::MOVEMENT_LABELS[$row['movementType']] ?? $row['movementType'] }}</x-badge></x-td>
                        <x-td><span class="text-xs text-content-subtle">{{ $row['sourceRef'] ?? '—' }}</span></x-td>
                        <x-td numeric>{{ $row['qtyIn'] > 0 ? Format::qty($row['qtyIn'], $row['productUnit']) : '—' }}</x-td>
                        <x-td numeric>{{ $row['qtyOut'] > 0 ? Format::qty($row['qtyOut'], $row['productUnit']) : '—' }}</x-td>
                        <x-td numeric>{{ Format::money($row['totalCost'], bare: true) }}</x-td>
                        <x-td numeric>{{ Format::qty($row['balanceQty'], $row['productUnit']) }}</x-td>
                        <x-td numeric>{{ Format::money($row['balanceValue'], bare: true) }}</x-td>
                    </tr>
                @endforeach
            </x-table>
            <x-pagination :list="$list" :total="$total" noun="movement" />
        @endif
    </section>

    <p class="mt-4 text-xs text-content-subtle">
        This ledger is the source of truth for stock. Movements are listed in the order they were entered, newest first, so a backdated entry appears where it was recorded, under the date the stock moved. The balance shown on each row is the position immediately after that movement, so any figure on the Stock items page can be traced back to the exact line that produced it. Filtering changes what you see, never what the shop holds.
    </p>
</div>
