<div class="w-full">
    <x-page-header :title="$supplier['name']" :description="$supplier['contactPerson'] ?? $supplier['phone']">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/suppliers">All suppliers</x-button>
            @can('suppliers.edit')
                <x-button variant="secondary" size="sm" href="/suppliers/{{ $supplier['id'] }}/edit">Edit</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <x-stat icon="owes" label="You currently owe" :value="\App\View\Format::money($supplier['balance'], currencyCode: $currency)" :tone="$supplier['balance'] > 0 ? 'warning' : 'default'" />
        <x-stat icon="expenses" label="Total bought" :value="\App\View\Format::money($totalBought, bare: true)" />
        <x-stat icon="expenses" label="Total paid later" :value="\App\View\Format::money($totalPaid, bare: true)" />
    </div>

    @if ($supplier['balance'] > 0 && Gate::allows('suppliers.create'))
        <form wire:submit="pay" class="mb-6 rounded-xl border border-line bg-surface-raised p-4" novalidate>
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold text-content">Pay this supplier</h2>
                <p class="text-sm text-content-muted">
                    You owe {{ $supplier['name'] }}
                    <span class="tabular font-semibold text-warning">{{ $currency }} {{ \App\Domain\Money::toDecimalString($supplier['balance']) }}</span>
                </p>
            </div>

            @error('form')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror

            <div class="grid gap-3 sm:grid-cols-4">
                <x-field label="Date" for="businessDate" required>
                    <x-date-input id="businessDate" wire:model="businessDate" :value="$businessDate" required :invalid="$errors->has('businessDate')" />
                </x-field>
                <x-field label="Paid from" for="paymentAccountId" required>
                    <select id="paymentAccountId" wire:model="paymentAccountId" class="h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content">
                        @foreach ($accounts as $account)
                            <option value="{{ $account['id'] }}">{{ $account['name'] }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field label="Amount ({{ $currency }})" for="amount" required>
                    <div class="flex gap-2">
                        <x-input amount id="amount" wire:model="amount" placeholder="0.00" required :invalid="$errors->has('amount')" />
                        <x-button variant="secondary" x-on:click="$wire.amount = '{{ \App\Domain\Money::toDecimalString($supplier['balance']) }}'">All</x-button>
                    </div>
                </x-field>
                <x-field label="Reference" for="reference">
                    <x-input id="reference" wire:model="reference" placeholder="Optional" :invalid="$errors->has('reference')" />
                </x-field>
            </div>

            <p class="mt-3 text-xs text-content-subtle">Applied to the oldest unpaid delivery first.</p>

            <div class="mt-4">
                <x-button type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="pay">Record payment</span>
                    <span wire:loading wire:target="pay">Recording…</span>
                </x-button>
            </div>
        </form>
    @endif

    <h2 class="mb-3 text-sm font-semibold text-content">Purchases</h2>
    @if ($purchases === [])
        <x-card class="mb-6"><p class="text-sm text-content-muted">No purchases recorded from this supplier yet.</p></x-card>
    @else
        <x-table class="mb-6">
            <x-slot:head>
                <x-th>Reference</x-th>
                <x-th>Date</x-th>
                <x-th numeric>Total</x-th>
                <x-th numeric>Owing</x-th>
                <x-th>Status</x-th>
            </x-slot:head>
            @foreach ($purchases as $purchase)
                <tr class="border-b border-line last:border-0" wire:key="purchase-{{ $purchase['id'] }}">
                    <x-td><a href="/purchases/{{ $purchase['id'] }}" class="font-medium text-accent hover:underline">{{ $purchase['purchaseNo'] }}</a></x-td>
                    <x-td><span class="whitespace-nowrap text-content-muted">@date($purchase['businessDate'])</span></x-td>
                    <x-td numeric>{{ \App\View\Format::money($purchase['totalMinor'], bare: true) }}</x-td>
                    <x-td numeric>
                        @if ($purchase['outstandingMinor'] > 0)
                            <span class="font-medium text-warning">{{ \App\View\Format::money($purchase['outstandingMinor'], bare: true) }}</span>
                        @else
                            <span class="text-content-subtle">—</span>
                        @endif
                    </x-td>
                    <x-td>
                        @if ($purchase['status'] === 'VOIDED')
                            <x-badge tone="danger">Voided</x-badge>
                        @elseif ($purchase['kind'] === 'RETURN')
                            <x-badge tone="accent">Return</x-badge>
                        @elseif ($purchase['outstandingMinor'] > 0)
                            <x-badge tone="warning">Credit</x-badge>
                        @else
                            <x-badge tone="success">Paid</x-badge>
                        @endif
                    </x-td>
                </tr>
            @endforeach
        </x-table>
    @endif

    <h2 class="mb-3 text-sm font-semibold text-content">Payments made</h2>
    @if ($payments === [])
        <x-card><p class="text-sm text-content-muted">No separate payments yet. Money paid at the time of a purchase is shown on that record.</p></x-card>
    @else
        <x-table>
            <x-slot:head>
                <x-th>Reference</x-th>
                <x-th>Date</x-th>
                <x-th>Method</x-th>
                <x-th numeric>Amount</x-th>
                <x-th>Status</x-th>
                <x-th />
            </x-slot:head>
            @foreach ($payments as $payment)
                <tr class="border-b border-line last:border-0" wire:key="payment-{{ $payment['id'] }}">
                    <x-td><span class="font-medium text-content">{{ $payment['paymentNo'] }}</span></x-td>
                    <x-td><span class="whitespace-nowrap text-content-muted">@date($payment['businessDate'])</span></x-td>
                    <x-td>{{ $payment['accountName'] }}</x-td>
                    <x-td numeric>{{ \App\View\Format::money($payment['amountMinor'], bare: true) }}</x-td>
                    <x-td>
                        @if ($payment['status'] === 'VOIDED')
                            <x-badge tone="danger">Voided</x-badge>
                        @else
                            <x-badge tone="success">Paid</x-badge>
                        @endif
                    </x-td>
                    <x-td>
                        @if ($payment['status'] !== 'VOIDED' && Gate::allows('suppliers.void'))
                            @include('livewire.products.partials.row-void', ['id' => $payment['id'], 'what' => $payment['paymentNo'], 'action' => 'voidPayment', 'model' => 'voidReasons', 'placeholder' => 'e.g. Paid the wrong supplier'])
                        @endif
                    </x-td>
                </tr>
            @endforeach
        </x-table>
    @endif
</div>
