@php
    use App\View\Format;
    $fmt = fn (int $v) => Format::money($v, bare: true);
@endphp
<div class="w-full">
    <x-page-header :title="$customer['name']" :description="$customer['phone']">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/customers">All customers</x-button>
            <x-button variant="secondary" size="sm" href="/customers/{{ $customer['id'] }}/statement">Statement</x-button>
            @can('customers.edit')
                <x-button variant="secondary" size="sm" href="/customers/{{ $customer['id'] }}/edit">Edit</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($customer['overLimit'])
        <x-alert tone="danger" title="Over their credit limit" class="mb-4">
            {{ $customer['name'] }} owes {{ Format::money($customer['balance'], currencyCode: $currency) }} against a limit
            of {{ Format::money($customer['creditLimit'] ?? 0, currencyCode: $currency) }}. New credit sales
            will be refused.
        </x-alert>
    @endif

    <div class="mb-6 grid gap-3 sm:grid-cols-4">
        <x-stat icon="owed" label="Currently owes" :value="Format::money($customer['balance'], currencyCode: $currency)" :tone="$customer['balance'] > 0 ? 'warning' : 'default'" />
        <x-stat icon="sales" label="Total bought" :value="$fmt($totalBought)" />
        <x-stat icon="income" label="Total paid later" :value="$fmt($totalPaid)" />
        <x-stat icon="owed" label="Credit limit" :value="$customer['creditLimit'] === null ? 'No limit' : $fmt($customer['creditLimit'])"
            :hint="$customer['headroom'] === null ? null : $fmt($customer['headroom']).' available'" />
    </div>

    @if ($customer['balance'] > 0)
        @can('customers.create')
            {{-- Applied to the oldest unpaid sale first, which is what the customer usually means. --}}
            <form wire:submit="receivePayment" class="mb-6 rounded-xl border border-line bg-surface-raised p-4" novalidate>
                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-sm font-semibold text-content">Receive payment</h2>
                    <p class="text-sm text-content-muted">{{ $customer['name'] }} owes <span class="tabular font-semibold text-warning">{{ $currency }} {{ $fmt($customer['balance']) }}</span></p>
                </div>
                @error('payment')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror

                <div class="grid gap-3 sm:grid-cols-4">
                    <x-field label="Date" for="businessDate" required>
                        <x-date-input id="businessDate" wire:model="businessDate" :value="$businessDate" :invalid="$errors->has('businessDate')" />
                    </x-field>
                    <x-field label="Method" for="paymentAccountId" required>
                        <select id="paymentAccountId" wire:model="paymentAccountId" class="h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content">
                            @foreach ($accounts as $account)<option value="{{ $account['id'] }}">{{ $account['name'] }}</option>@endforeach
                        </select>
                    </x-field>
                    <x-field label="Amount ({{ $currency }})" for="amount" required>
                        <div class="flex gap-2">
                            <x-input amount id="amount" wire:model="amount" placeholder="0.00" required :invalid="$errors->has('amount')" />
                            <x-button variant="secondary" wire:click="payAll">All</x-button>
                        </div>
                    </x-field>
                    <x-field label="Reference" for="reference">
                        <x-input id="reference" wire:model="reference" placeholder="Optional" />
                    </x-field>
                </div>

                @if ($openSales !== [])
                    <p class="mt-3 text-xs text-content-subtle">
                        Applied to the oldest unpaid sale first: {{ $openSales[0]['receiptNo'] }}
                        ({{ $currency }} {{ $fmt((int) $openSales[0]['outstandingMinor']) }} outstanding){{ count($openSales) > 1 ? ', then '.(count($openSales) - 1).' more' : '' }}.
                    </p>
                @endif

                <div class="mt-4">
                    <x-button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="receivePayment">Record payment</span>
                        <span wire:loading wire:target="receivePayment">Recording…</span>
                    </x-button>
                </div>
            </form>
        @endcan
    @endif

    <h2 class="mb-3 text-sm font-semibold text-content">Sales</h2>
    @if ($sales === [])
        <x-card class="mb-6"><p class="text-sm text-content-muted">No sales recorded for this customer yet.</p></x-card>
    @else
        <x-table class="mb-6">
            <x-slot:head><x-th>Receipt</x-th><x-th>Date</x-th><x-th numeric>Total</x-th><x-th numeric>Owing</x-th><x-th>Status</x-th></x-slot:head>
            @foreach ($sales as $sale)
                <tr class="border-b border-line last:border-0" wire:key="sale-{{ $sale['id'] }}">
                    <x-td><a href="/sales/{{ $sale['id'] }}" class="font-medium text-accent hover:underline">{{ $sale['receiptNo'] }}</a></x-td>
                    <x-td><span class="whitespace-nowrap text-content-muted">@date($sale['businessDate'])</span></x-td>
                    <x-td numeric>{{ $fmt((int) $sale['totalMinor']) }}</x-td>
                    <x-td numeric>
                        @if ($sale['outstandingMinor'] > 0)
                            <span class="font-medium text-warning">{{ $fmt($sale['outstandingMinor']) }}</span>
                        @else
                            <span class="text-content-subtle">—</span>
                        @endif
                    </x-td>
                    <x-td>
                        @if ($sale['status'] === 'VOIDED')
                            <x-badge tone="danger">Voided</x-badge>
                        @elseif ($sale['outstandingMinor'] > 0)
                            <x-badge tone="warning">Credit</x-badge>
                        @else
                            <x-badge tone="success">Paid</x-badge>
                        @endif
                    </x-td>
                </tr>
            @endforeach
        </x-table>
    @endif

    <h2 class="mb-3 text-sm font-semibold text-content">Payments received</h2>
    @if ($payments === [])
        <x-card><p class="text-sm text-content-muted">No separate payments yet. Money taken at the time of a sale is shown on that receipt.</p></x-card>
    @else
        <x-table>
            <x-slot:head><x-th>Reference</x-th><x-th>Date</x-th><x-th>Method</x-th><x-th numeric>Amount</x-th><x-th>Status</x-th><x-th /></x-slot:head>
            @foreach ($payments as $payment)
                <tr class="border-b border-line last:border-0" wire:key="payment-{{ $payment['id'] }}">
                    <x-td><span class="font-medium text-content">{{ $payment['paymentNo'] }}</span></x-td>
                    <x-td><span class="whitespace-nowrap text-content-muted">@date($payment['businessDate'])</span></x-td>
                    <x-td>{{ $payment['accountName'] }}</x-td>
                    <x-td numeric>{{ $fmt((int) $payment['amountMinor']) }}</x-td>
                    <x-td>
                        @if ($payment['status'] === 'VOIDED')
                            <x-badge tone="danger">Voided</x-badge>
                        @else
                            <x-badge tone="success">Received</x-badge>
                        @endif
                    </x-td>
                    <x-td>
                        @if ($payment['status'] !== 'VOIDED')
                            @can('customers.void')
                                {{-- Two deliberate actions and a written reason, typed in the row itself. --}}
                                @php($rowError = $errors->first('voidReasons.'.$payment['id']))
                                <div x-data="{ confirming: {{ $rowError ? 'true' : 'false' }} }" class="no-print">
                                    <x-button x-show="! confirming" variant="ghost" size="sm" x-on:click="confirming = true" aria-label="Void {{ $payment['paymentNo'] }}">Void</x-button>
                                    <form x-show="confirming" x-cloak wire:submit="voidPayment({{ $payment['id'] }})" novalidate class="min-w-56">
                                        <p class="mb-1.5 text-xs text-content-muted">Void {{ $payment['paymentNo'] }}? Nothing is deleted — a reversing entry is posted.</p>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-input wire:model="voidReasons.{{ $payment['id'] }}" placeholder="e.g. Wrong customer" class="h-9 w-44" :invalid="(bool) $rowError" aria-label="Reason for voiding {{ $payment['paymentNo'] }}" />
                                            <x-button type="submit" variant="danger" size="sm" wire:loading.attr="disabled">Void</x-button>
                                            <x-button variant="ghost" size="sm" x-on:click="confirming = false">Cancel</x-button>
                                        </div>
                                        @if ($rowError)<p class="mt-1 text-xs font-medium text-danger">{{ $rowError }}</p>@endif
                                    </form>
                                </div>
                            @endcan
                        @endif
                    </x-td>
                </tr>
            @endforeach
        </x-table>
    @endif
</div>
