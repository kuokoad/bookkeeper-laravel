@php
    use App\View\Format;
    use App\Domain\Quantity;
    $lineQty = fn (array $item) => $item['entryUnit'] && $item['entryQtyMilli'] !== null
        ? Format::qty((int) $item['entryQtyMilli'], $item['entryUnit'])
        : Format::qty((int) $item['qtyMilli'], $item['unit']);
@endphp
<div class="w-full">
    <x-page-header :title="$sale['receiptNo']" :description="Format::date($sale['businessDate']).' · '.Format::dateTime($sale['occurredAt'])">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/sales">All sales</x-button>
            @if ($canEdit)
                <x-button variant="secondary" size="sm" href="/sales/{{ $sale['id'] }}/edit">Edit</x-button>
            @endif
            @if ($sale['invoiceNo'])
                <x-button variant="secondary" size="sm" href="/sales/{{ $sale['id'] }}/invoice">Invoice</x-button>
            @endif
            <x-button size="sm" href="/sales/{{ $sale['id'] }}/receipt">View receipt</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($sale['status'] === 'VOIDED')
        <x-alert tone="warning" title="This sale was voided" class="mb-4">
            {{ $sale['voidReason'] }}@if ($sale['voidedAt']) — {{ Format::dateTime($sale['voidedAt']) }}@endif. The original record is kept
            exactly as it was made.
        </x-alert>
    @endif

    @if ($sale['editedAt'])
        <p class="mb-4"><x-badge tone="accent">Edited</x-badge> <span class="text-sm text-content-muted">{{ Format::dateTime($sale['editedAt']) }}</span></p>
    @endif

    @if ($sale['voidsSaleId'] !== null)
        <x-alert class="mb-4">This is the reversing entry created when an earlier sale was voided.</x-alert>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <x-card>
            <p class="text-sm text-content-muted">Total</p>
            <p class="tabular mt-1 text-xl font-semibold text-content">{{ Format::money($sale['totalMinor']) }}</p>
        </x-card>
        <x-card>
            <p class="text-sm text-content-muted">Cost of goods</p>
            <p class="tabular mt-1 text-xl font-semibold text-content">{{ Format::money($sale['cogsMinor'], bare: true) }}</p>
        </x-card>
        <x-card>
            <p class="text-sm text-content-muted">Profit</p>
            <p @class(['tabular mt-1 text-xl font-semibold', 'text-danger' => $sale['profitMinor'] < 0, 'text-success' => $sale['profitMinor'] >= 0])>{{ Format::money($sale['profitMinor'], bare: true) }}</p>
        </x-card>
        <x-card>
            <p class="text-sm text-content-muted">{{ $sale['outstandingMinor'] < 0 ? 'In credit' : 'Still owing' }}</p>
            <p @class(['tabular mt-1 text-xl font-semibold', 'text-warning' => $sale['outstandingMinor'] > 0, 'text-content' => $sale['outstandingMinor'] <= 0])>{{ Format::money(\App\Domain\Money::absolute($sale['outstandingMinor']), bare: true) }}</p>
        </x-card>
    </div>

    @if ($sale['customerName'])
        <x-card class="mb-4">
            <p class="text-sm text-content-muted">Customer</p>
            <p class="mt-0.5">
                <a href="/customers/{{ $sale['customerId'] }}" class="font-medium text-accent hover:underline">{{ $sale['customerName'] }}</a>
                @if ($sale['customerPhone'])<span class="ml-2 text-sm text-content-subtle">{{ $sale['customerPhone'] }}</span>@endif
            </p>
        </x-card>
    @endif

    <h2 class="mb-3 text-sm font-semibold text-content">Items</h2>
    <x-table class="mb-6">
        <x-slot:head>
            <x-th>Stock item</x-th><x-th numeric>Qty</x-th><x-th numeric>Price</x-th><x-th numeric>Discount</x-th><x-th numeric>Total</x-th><x-th numeric>Cost</x-th>
        </x-slot:head>
        @foreach ($sale['items'] as $item)
            <tr class="border-b border-line last:border-0">
                <x-td><span class="font-medium text-content">{{ $item['productName'] }}</span></x-td>
                <x-td numeric>{{ $lineQty($item) }}</x-td>
                <x-td numeric>{{ Format::money((int) $item['unitPriceMinor'], bare: true) }}</x-td>
                <x-td numeric>{{ $item['discountMinor'] > 0 ? Format::money((int) $item['discountMinor'], bare: true) : '—' }}</x-td>
                <x-td numeric>{{ Format::money((int) $item['lineTotalMinor'], bare: true) }}</x-td>
                <x-td numeric><span class="text-content-muted">{{ Format::money((int) $item['totalCostMinor'], bare: true) }}</span></x-td>
            </tr>
        @endforeach
        <tr class="bg-surface-sunken font-semibold">
            <x-td>Total</x-td><x-td /><x-td />
            <x-td numeric>{{ $sale['discountMinor'] > 0 ? Format::money((int) $sale['discountMinor'], bare: true) : '—' }}</x-td>
            <x-td numeric>{{ Format::money((int) $sale['totalMinor'], bare: true) }}</x-td>
            <x-td numeric>{{ Format::money((int) $sale['cogsMinor'], bare: true) }}</x-td>
        </tr>
    </x-table>

    <h2 class="mb-3 text-sm font-semibold text-content">Payment</h2>
    <x-table class="mb-6">
        <x-slot:head><x-th>Method</x-th><x-th>Reference</x-th><x-th numeric>Amount</x-th></x-slot:head>
        @if ($sale['tenders'] === [])
            <tr class="border-b border-line">
                <x-td class="text-content-muted">Nothing paid at the time of sale</x-td><x-td /><x-td numeric>0.00</x-td>
            </tr>
        @endif
        @foreach ($sale['tenders'] as $tender)
            <tr class="border-b border-line last:border-0">
                <x-td>{{ $tender['accountName'] }}</x-td>
                <x-td><span class="text-content-subtle">{{ $tender['reference'] ?? '—' }}</span></x-td>
                <x-td numeric>{{ Format::money((int) $tender['amountMinor'], bare: true) }}</x-td>
            </tr>
        @endforeach
        <tr class="bg-surface-sunken font-semibold">
            <x-td>Received at the till</x-td><x-td /><x-td numeric>{{ Format::money($tendered, bare: true) }}</x-td>
        </tr>
    </x-table>

    <h2 class="mb-3 text-sm font-semibold text-content">Accounting entry</h2>
    @if ($entry === null || $entry['lines'] === [])
        <x-card><p class="text-sm text-content-muted">No accounting entry is linked to this sale.</p></x-card>
    @else
        <x-table>
            <x-slot:head><x-th>Account</x-th><x-th numeric>Debit</x-th><x-th numeric>Credit</x-th></x-slot:head>
            @foreach ($entry['lines'] as $line)
                <tr class="border-b border-line last:border-0">
                    <x-td><span class="text-content-subtle">{{ $line['accountCode'] }}</span> <span class="font-medium text-content">{{ $line['accountName'] }}</span></x-td>
                    <x-td numeric>{{ $line['debit'] > 0 ? Format::money((int) $line['debit'], bare: true) : '—' }}</x-td>
                    <x-td numeric>{{ $line['credit'] > 0 ? Format::money((int) $line['credit'], bare: true) : '—' }}</x-td>
                </tr>
            @endforeach
            <tr class="bg-surface-sunken font-semibold">
                <x-td>Total</x-td>
                <x-td numeric>{{ Format::money($entry['totalDebit'], bare: true) }}</x-td>
                <x-td numeric>{{ Format::money($entry['totalCredit'], bare: true) }}</x-td>
            </tr>
        </x-table>
        <p class="mt-2 text-xs text-content-subtle">
            {{ $entry['balanced'] ? 'Debits equal credits — this entry balances.' : 'WARNING: this entry does not balance. Please report it.' }}
        </p>
    @endif

    @if ($returnable !== [])
        <div class="mt-8">
            @include('livewire.sales.partials.return-form', [
                'items' => $returnable,
                'title' => 'Customer return',
                'description' => 'Take some or all of these goods back. Stock returns at the cost it left at, so profit is unaffected.',
                'submitLabel' => 'Record customer return',
                'creditLabel' => 'reduces what the customer owes',
            ])
        </div>
    @endif

    @if ($sale['status'] === 'POSTED' && $sale['kind'] === 'SALE' && $sale['voidsSaleId'] === null)
        @can('sales.void')
            <div class="mt-6">
                <x-confirm-dialog title="Void this sale"
                    description="Stock goes back at the cost it left at, the money comes back out, and any balance owing is cancelled. The original sale is kept."
                    trigger="Void {{ $sale['receiptNo'] }}…" heading="Void {{ $sale['receiptNo'] }}?" confirm="Yes, void this sale" wire:submit="void">
                    <x-field label="Reason" for="void-reason" name="reason" required>
                        <x-input id="void-reason" wire:model="reason" placeholder="e.g. Wrong item scanned" :invalid="$errors->has('reason')" />
                    </x-field>
                </x-confirm-dialog>
            </div>
        @endcan
    @endif

    <x-edit-history :history="$history" />
</div>
