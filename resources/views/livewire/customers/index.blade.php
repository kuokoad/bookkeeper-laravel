@php
    use App\Services\CustomerService;
    use App\View\Format;
    $f = $list['filters'];
    $isFiltered = array_intersect_key($f, array_flip(['search', 'balanceState', 'customerStatus'])) !== [];
@endphp
<div class="w-full">
    <x-page-header title="Customers" description="Who buys from you, and who owes you money.">
        <x-slot:actions>
            <x-csv-button list="customers" :query="$list['carried']" />
            @can('customers.create')
                <x-button size="sm" href="/customers/new">Add customer</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <x-stat label="Customers" icon="customers" :value="(string) $customerCount" />
        <x-stat label="Total owed to you" icon="owed" :value="Format::money($totalOwed)" :tone="$totalOwed > 0 ? 'warning' : 'default'" hint="Accounts receivable" />
        <x-stat label="Customers owing" icon="owed" :value="(string) $owingCount" />
    </div>

    @php
        $quick = [
        ['label' => 'Customers who owe', 'params' => ['balance' => 'owing'], 'match' => ['balance' => 'owing']],
        ['label' => 'Nothing owing', 'params' => ['balance' => 'zero'], 'match' => ['balance' => 'zero']],
        ['label' => 'Archived', 'params' => ['archived' => 'archived'], 'match' => ['archived' => 'archived']],
    ];
    @endphp

    <x-filter-bar :quick="$quick" :list="$list" :fields="[
        ['kind' => 'search', 'key' => 'q', 'label' => 'Search', 'placeholder' => 'Name, phone or email', 'wide' => true, 'value' => $f['search'] ?? ''],
        ['kind' => 'select', 'key' => 'balance', 'label' => 'Balance', 'allLabel' => 'Any balance', 'options' => ['owing' => 'Owes money', 'zero' => 'Nothing owing', 'credit' => 'In credit'], 'value' => $f['balanceState'] ?? null],
        ['kind' => 'select', 'key' => 'archived', 'label' => 'Status', 'allLabel' => 'Active only', 'options' => ['active' => 'Active', 'archived' => 'Archived'], 'value' => $f['customerStatus'] ?? null],
    ]" />

    @if ($rows === [])
        <x-empty-state :title="$isFiltered ? 'No customers match' : 'No customers yet'"
            :description="$isFiltered ? 'Try a different search or clear the filter.' : 'Add a customer when you need to sell on credit or keep a record of who bought what. Walk-in cash sales do not need one.'">
            @if (! $isFiltered)
                @can('customers.create')
                    <x-slot:action><x-button href="/customers/new">Add your first customer</x-button></x-slot:action>
                @endcan
            @endif
        </x-empty-state>
    @else
        <x-table>
            <x-slot:head>
                <x-th sort="name" :sorts="CustomerService::CUSTOMER_SORTS" :list="$list" default-direction="asc">Customer</x-th>
                <x-th>Phone</x-th>
                <x-th sort="balance" :sorts="CustomerService::CUSTOMER_SORTS" :list="$list" numeric>Owes</x-th>
                <x-th numeric>Credit limit</x-th>
                <x-th>Status</x-th>
            </x-slot:head>
            @foreach ($rows as $customer)
                <tr class="border-b border-line last:border-0" wire:key="customer-{{ $customer['id'] }}">
                    <x-td><a href="/customers/{{ $customer['id'] }}" class="font-medium text-accent hover:underline">{{ $customer['name'] }}</a></x-td>
                    <x-td><span class="text-content-muted">{{ $customer['phone'] ?? '—' }}</span></x-td>
                    <x-td numeric>
                        @if ($customer['balance'] > 0)
                            <span class="font-medium text-warning">{{ Format::money($customer['balance'], bare: true) }}</span>
                        @else
                            <span class="text-content-subtle">—</span>
                        @endif
                    </x-td>
                    <x-td numeric>
                        @if ($customer['creditLimit'] === null)
                            <span class="text-content-subtle">No limit</span>
                        @else
                            {{ Format::money($customer['creditLimit'], bare: true) }}
                        @endif
                    </x-td>
                    <x-td>
                        @if (! $customer['isActive'])
                            <x-badge>Archived</x-badge>
                        @elseif ($customer['overLimit'])
                            <x-badge tone="danger">Over limit</x-badge>
                        @elseif ($customer['balance'] > 0)
                            <x-badge tone="warning">Owing</x-badge>
                        @else
                            <x-badge tone="success">Clear</x-badge>
                        @endif
                    </x-td>
                </tr>
            @endforeach
        </x-table>
        <x-pagination :list="$list" :total="$total" noun="customer" />
    @endif

    <p class="mt-4 text-xs text-content-subtle">
        What a customer owes is calculated from the accounts-receivable entries tagged to them, so
        this total always equals the receivables figure on the balance sheet — and so does the
        &ldquo;Customers who owe&rdquo; filter.
    </p>
</div>
