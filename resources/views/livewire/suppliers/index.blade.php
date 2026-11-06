<div class="w-full">
    <x-page-header title="Suppliers" description="Who you buy from, and what you owe them.">
        <x-slot:actions>
            <x-csv-button list="suppliers" :query="$list['carried']" />
            @can('suppliers.create')
                <x-button size="sm" href="/suppliers/new">Add supplier</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <x-stat label="Suppliers" icon="suppliers" :value="(string) $supplierCount" />
        <x-stat label="Total you owe" icon="owes" :value="\App\View\Format::money($payables)" :tone="$payables > 0 ? 'warning' : 'default'" hint="Accounts payable" />
        <x-stat label="Suppliers owed" icon="owes" :value="(string) $owedCount" />
    </div>

    @php
        $quick = [
        ['label' => 'Suppliers we owe', 'params' => ['balance' => 'owing']],
        ['label' => 'Nothing owing', 'params' => ['balance' => 'zero']],
        ['label' => 'Archived', 'params' => ['archived' => 'archived']],
    ];
    @endphp

    <x-filter-bar :quick="$quick" :list="$list" :fields="[
        ['kind' => 'search', 'key' => 'q', 'label' => 'Search', 'placeholder' => 'Name, contact, phone or email', 'wide' => true, 'value' => $list['filters']['search'] ?? ''],
        ['kind' => 'select', 'key' => 'balance', 'label' => 'Balance', 'allLabel' => 'Any balance', 'options' => \App\Livewire\Suppliers\Index::BALANCE_LABELS, 'value' => $list['filters']['balanceState'] ?? null],
        ['kind' => 'select', 'key' => 'archived', 'label' => 'Status', 'allLabel' => 'Active only', 'options' => ['active' => 'Active', 'archived' => 'Archived'], 'value' => $list['filters']['supplierStatus'] ?? null],
    ]" />

    @if ($suppliers === [])
        <x-empty-state :title="$isFiltered ? 'No suppliers match' : 'No suppliers yet'"
            :description="$isFiltered ? 'Try a different search or clear the filter.' : 'Add the people and businesses you buy stock from, so purchases and what you owe can be tracked.'">
            @if (! $isFiltered && Gate::allows('suppliers.create'))
                <x-slot:action><x-button href="/suppliers/new">Add your first supplier</x-button></x-slot:action>
            @endif
        </x-empty-state>
    @else
        <x-table>
            <x-slot:head>
                <x-th sort="name" :sorts="\App\Services\SupplierService::SUPPLIER_SORTS" :list="$list" default-direction="asc">Supplier</x-th>
                <x-th>Contact</x-th>
                <x-th>Phone</x-th>
                <x-th sort="balance" :sorts="\App\Services\SupplierService::SUPPLIER_SORTS" :list="$list" numeric>You owe</x-th>
                <x-th>Status</x-th>
            </x-slot:head>
            @foreach ($suppliers as $supplier)
                <tr class="border-b border-line last:border-0" wire:key="supplier-{{ $supplier['id'] }}">
                    <x-td><a href="/suppliers/{{ $supplier['id'] }}" class="font-medium text-accent hover:underline">{{ $supplier['name'] }}</a></x-td>
                    <x-td><span class="text-content-muted">{{ $supplier['contactPerson'] ?? '—' }}</span></x-td>
                    <x-td><span class="text-content-muted">{{ $supplier['phone'] ?? '—' }}</span></x-td>
                    <x-td numeric>
                        @if ($supplier['balance'] > 0)
                            <span class="font-medium text-warning">{{ \App\View\Format::money($supplier['balance'], bare: true) }}</span>
                        @else
                            <span class="text-content-subtle">—</span>
                        @endif
                    </x-td>
                    <x-td>
                        @if (! $supplier['isActive'])
                            <x-badge tone="neutral">Archived</x-badge>
                        @elseif ($supplier['balance'] > 0)
                            <x-badge tone="warning">Owed</x-badge>
                        @else
                            <x-badge tone="success">Clear</x-badge>
                        @endif
                    </x-td>
                </tr>
            @endforeach
        </x-table>
        <x-pagination :list="$list" :total="$total" noun="supplier" />
    @endif

    <p class="mt-4 text-xs text-content-subtle">
        What you owe is calculated from the accounts-payable entries tagged to each supplier, so this total always equals the payables figure on the balance sheet — and so does the &ldquo;Suppliers we owe&rdquo; filter.
    </p>
</div>
