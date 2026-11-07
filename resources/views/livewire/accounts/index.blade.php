@php($F = \App\View\Format::class)
@php($href = \App\Livewire\Accounts\Index::href(...))
@php($select = 'h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content')
<div class="w-full">
    <x-page-header title="Accounts" description="Every pot of money the shop holds — cash, mobile money, bank.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/accounts/transfers">Transfers</x-button>
            <x-create-menu size="sm" primary="Transfer between accounts" label="Transfer" />
        </x-slot:actions>
    </x-page-header>

    @if (! $trial['balanced'])
        <x-alert tone="danger" title="The books do not balance" class="mb-4">
            Total debits ({{ $F::money($trial['totalDebit']) }}) do not equal total credits ({{ $F::money($trial['totalCredit']) }}). Please report this before recording anything else.
        </x-alert>
    @endif

    @if ($negative !== [])
        <x-alert tone="warning" title="An account is showing a negative balance" class="mb-4">
            {{ implode(', ', array_column($negative, 'name')) }} shows less than zero. That usually
            means a payment was recorded from the wrong account, or money went in without being
            recorded. Check the movements before trusting the figure.
        </x-alert>
    @endif

    <div class="mb-6 grid gap-3 sm:grid-cols-2">
        <x-stat label="Total money held" icon="accounts" :value="$F::money($totalHeld, currencyCode: $currency)" hint="Across all active accounts" />
        <x-stat label="Accounts" icon="accounts" :value="(string) count($active)" />
    </div>

    @if ($accounts === [])
        <div class="mb-6">
            <x-empty-state title="No money accounts yet" description="Add the places money actually sits — cash in the drawer, a mobile money wallet, a bank account. Every payment has to land somewhere." />
        </div>
    @else
        <x-table class="mb-6">
            <x-slot:head>
                <x-th>Account</x-th>
                <x-th>Type</x-th>
                <x-th>Provider</x-th>
                <x-th numeric>Balance</x-th>
                <x-th />
            </x-slot:head>
            @foreach ($accounts as $account)
                <tr class="border-b border-line last:border-0">
                    <x-td>
                        <a href="{{ $href($account['id']) }}" class="font-medium text-accent hover:underline">{{ $account['name'] }}</a>
                        @if ($account['isDefault'])<x-badge tone="accent" class="ml-2">Default</x-badge>@endif
                        @if (! $account['isActive'])<x-badge tone="neutral" class="ml-2">Archived</x-badge>@endif
                        <div class="mt-0.5 text-xs text-content-subtle">Ledger account {{ $account['glCode'] }}{{ $account['accountNumber'] ? ' · '.$account['accountNumber'] : '' }}</div>
                    </x-td>
                    <x-td><span class="text-content-muted">{{ \App\Livewire\Accounts\Index::KIND_LABELS[$account['kind']] ?? $account['kind'] }}</span></x-td>
                    <x-td><span class="text-content-muted">{{ $account['provider'] ?? '—' }}</span></x-td>
                    <x-td numeric><span @class(['font-medium text-danger' => $account['balance'] < 0])>{{ $F::money($account['balance'], bare: true) }}</span></x-td>
                    <x-td><div class="flex justify-end"><a href="{{ $href($account['id']) }}" class="text-xs font-medium text-accent hover:underline">Movements</a></div></x-td>
                </tr>
            @endforeach
        </x-table>
    @endif

    @if ($canCreate)
        <div class="space-y-4">
            {{-- Add an account. `provider` is free text: a new mobile money network is data entry, not a code change. --}}
            <div class="flex flex-wrap gap-2" x-data="{ open: {{ $errors->hasAny(['name', 'kind', 'provider', 'accountNumber', 'account']) ? 'true' : 'false' }} }" x-on:account-added.window="open = false">
                <x-button variant="secondary" x-show="! open" x-on:click="open = true">Add an account</x-button>
                <form x-show="open" x-cloak wire:submit="createAccount" class="w-full rounded-xl border border-line bg-surface-raised p-4" novalidate>
                    <h2 class="mb-3 text-sm font-semibold text-content">Add a payment account</h2>
                    @error('account')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field label="Name" for="name" required>
                            <x-input id="name" wire:model="name" placeholder="e.g. Telecel Cash" required :invalid="$errors->has('name')" />
                        </x-field>
                        <x-field label="Type" for="kind" required>
                            <select id="kind" wire:model="kind" class="{{ $select }}">
                                @foreach (\App\Livewire\Accounts\Index::KIND_LABELS as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </x-field>
                        <x-field label="Provider" for="provider" hint="e.g. MTN, Telecel, AirtelTigo, GCB. Anything you like.">
                            <x-input id="provider" wire:model="provider" placeholder="Optional" />
                        </x-field>
                        <x-field label="Account or wallet number" for="accountNumber">
                            <x-input id="accountNumber" wire:model="accountNumber" placeholder="Optional" />
                        </x-field>
                        <div class="flex items-start gap-3 sm:col-span-2">
                            <input id="isDefault" type="checkbox" wire:model="isDefault" class="mt-0.5 h-4 w-4 rounded border-line-strong">
                            <label for="isDefault" class="text-sm text-content">
                                Make this the default at the till
                                <span class="mt-0.5 block text-xs text-content-subtle">Pre-selected when taking payment. Only one account can be the default.</span>
                            </label>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center gap-3">
                        <x-button type="submit" wire:loading.attr="disabled">Add account</x-button>
                        <x-button variant="ghost" x-on:click="open = false">Cancel</x-button>
                    </div>
                </form>
            </div>

            {{-- Move money between accounts: starts on the weekly case, the cash drawer to the bank. --}}
            @if (count($active) >= 2)
                <div x-data="{ open: {{ $errors->hasAny(['transferDate', 'fromAccountId', 'toAccountId', 'transferAmount', 'transferReference', 'transferNote', 'transfer']) || $transferDone ? 'true' : 'false' }} }">
                    <x-button variant="secondary" x-show="! open" x-on:click="open = true">Move money between accounts</x-button>
                    <form x-show="open" x-cloak wire:submit="recordTransfer" class="rounded-xl border border-line bg-surface-raised p-4" novalidate>
                        <h2 class="mb-3 text-sm font-semibold text-content">Move money between accounts</h2>
                        @if ($transferDone)<x-alert tone="success" class="mb-3">{{ $transferDone }} Both balances have been updated.</x-alert>@endif
                        @error('transfer')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror
                        <div class="grid gap-3 sm:grid-cols-4">
                            <x-field label="Date" for="transfer-date" name="transferDate" required>
                                <x-date-input id="transfer-date" wire:model="transferDate" :value="$transferDate" aria-label="Transfer date" required :invalid="$errors->has('transferDate')" />
                            </x-field>
                            <x-field label="From" for="fromAccountId" required>
                                <select id="fromAccountId" wire:model="fromAccountId" required class="{{ $select }}">
                                    @foreach ($active as $account)<option value="{{ $account['id'] }}">{{ $account['name'] }}</option>@endforeach
                                </select>
                            </x-field>
                            <x-field label="To" for="toAccountId" required>
                                <select id="toAccountId" wire:model="toAccountId" required class="{{ $select }}">
                                    @foreach ($active as $account)<option value="{{ $account['id'] }}">{{ $account['name'] }}</option>@endforeach
                                </select>
                            </x-field>
                            <x-field label="Amount ({{ $currency }})" for="transfer-amount" name="transferAmount" required>
                                <x-input amount id="transfer-amount" wire:model="transferAmount" placeholder="0.00" required :invalid="$errors->has('transferAmount')" />
                            </x-field>
                            <x-field label="Reference" for="transfer-reference" name="transferReference">
                                <x-input id="transfer-reference" wire:model="transferReference" placeholder="e.g. Deposit slip no." />
                            </x-field>
                            <x-field label="Note" for="transfer-note" name="transferNote">
                                <x-input id="transfer-note" wire:model="transferNote" />
                            </x-field>
                        </div>
                        <p class="mt-3 text-xs text-content-subtle">Moving money between your own accounts is not income or spending. The total you hold stays the same, and your profit is not touched.</p>
                        <div class="mt-4 flex items-center gap-3">
                            <x-button type="submit" wire:loading.attr="disabled">Record transfer</x-button>
                            <x-button variant="ghost" x-on:click="open = false">Cancel</x-button>
                        </div>
                    </form>
                </div>
            @endif

            {{-- Owner money: what the owner takes out is NOT an expense, and what they put in is NOT income. --}}
            <div x-data="{ open: {{ $errors->hasAny(['ownerDate', 'ownerAccountId', 'ownerAmount', 'ownerDescription', 'owner']) ? 'true' : 'false' }} }">
                <x-button variant="secondary" x-show="! open" x-on:click="open = true">Owner put money in / took money out</x-button>
                <form x-show="open" x-cloak wire:submit="recordOwnerMoney" class="rounded-xl border border-line bg-surface-raised p-4" novalidate>
                    <h2 class="mb-3 text-sm font-semibold text-content">Owner money</h2>
                    <div class="mb-4 flex gap-2">
                        <x-button size="sm" :variant="$direction === 'IN' ? 'primary' : 'secondary'" wire:click="setDirection('IN')" aria-pressed="{{ $direction === 'IN' ? 'true' : 'false' }}">Put money in</x-button>
                        <x-button size="sm" :variant="$direction === 'OUT' ? 'primary' : 'secondary'" wire:click="setDirection('OUT')" aria-pressed="{{ $direction === 'OUT' ? 'true' : 'false' }}">Take money out</x-button>
                    </div>
                    @error('owner')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror
                    <div class="grid gap-3 sm:grid-cols-4">
                        <x-field label="Date" for="ownerDate" required>
                            <x-date-input id="ownerDate" wire:model="ownerDate" :value="$ownerDate" required :invalid="$errors->has('ownerDate')" />
                        </x-field>
                        <x-field :label="$direction === 'IN' ? 'Into' : 'Out of'" for="ownerAccountId" required>
                            <select id="ownerAccountId" wire:model="ownerAccountId" required class="{{ $select }}">
                                @foreach ($active as $account)<option value="{{ $account['id'] }}">{{ $account['name'] }}</option>@endforeach
                            </select>
                        </x-field>
                        <x-field label="Amount ({{ $currency }})" for="ownerAmount" required>
                            <x-input amount id="ownerAmount" wire:model="ownerAmount" placeholder="0.00" required :invalid="$errors->has('ownerAmount')" />
                        </x-field>
                        <x-field label="What for" for="ownerDescription">
                            <x-input id="ownerDescription" wire:model="ownerDescription" :placeholder="$direction === 'IN' ? 'e.g. Extra float' : 'e.g. School fees'" />
                        </x-field>
                    </div>
                    <p class="mt-3 text-xs text-content-subtle">
                        {{ $direction === 'IN'
                            ? 'Money you put in increases your stake in the business. It is not counted as income, so it will not inflate your profit.'
                            : 'Money you take out reduces your stake in the business. It is not a business expense, so it will not reduce your reported profit.' }}
                    </p>
                    <div class="mt-4 flex items-center gap-3">
                        <x-button type="submit" wire:loading.attr="disabled">{{ $direction === 'IN' ? 'Record money in' : 'Record money out' }}</x-button>
                        <x-button variant="ghost" x-on:click="open = false">Cancel</x-button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <p class="mt-4 text-xs text-content-subtle">
        Balances are calculated from the ledger every time this page loads — no total is stored
        anywhere. Open an account to see exactly which transactions produced its balance.
    </p>
</div>
