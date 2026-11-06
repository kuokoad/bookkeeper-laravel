@php($F = \App\View\Format::class)
@php($labels = \App\Livewire\Accounts\Show::SOURCE_LABELS)
<div class="w-full">
    <x-page-header :title="$account['name']" :description="($account['provider'] ? $account['provider'].' · ' : '').'Ledger account '.$account['glCode']">
        <x-slot:actions>
            <x-csv-button list="account" :query="[...$list['carried'], 'id' => $account['id']]" />
            <x-button variant="secondary" size="sm" href="/accounts">All accounts</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($canEdit)
        <div class="mb-4" x-data="{ open: {{ $errors->any() ? 'true' : 'false' }} }">
            <x-button variant="secondary" size="sm" x-on:click="open = ! open" aria-controls="edit-account-form" x-bind:aria-expanded="open">
                <span x-text="open ? 'Cancel editing' : 'Edit account'">Edit account</span>
            </x-button>
            <form id="edit-account-form" x-show="open" x-cloak wire:submit="save" class="mt-3 border border-line bg-surface-raised p-4" novalidate>
                <h2 class="mb-3 text-sm font-semibold text-content">Edit account</h2>
                @error('form')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Account name" for="edit-account-name" name="name" required>
                        <x-input id="edit-account-name" wire:model="name" required maxlength="80" :invalid="$errors->has('name')" />
                    </x-field>
                    <x-field label="Provider" for="edit-account-provider" name="provider" hint="Optional: bank or mobile money provider.">
                        <x-input id="edit-account-provider" wire:model="provider" maxlength="60" :invalid="$errors->has('provider')" />
                    </x-field>
                </div>
                <x-button type="submit" class="mt-4" wire:loading.attr="disabled">Save changes</x-button>
            </form>
        </div>
    @endif

    @if ($updated)<x-alert tone="success" class="mb-4">Account updated.</x-alert>@endif

    @if ($account['balance'] < 0)
        <x-alert tone="warning" title="This account is showing less than zero" class="mb-4">
            That normally means money went out of the wrong account, or something received was never
            recorded. The movements below show exactly how it got here.
        </x-alert>
    @endif

    <div class="mb-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <x-stat icon="accounts" label="Opening balance" :value="$F::money($statement['opening'], bare: true)" hint="Before {{ $F::date($list['range']['from']) }}" :tone="$statement['opening'] < 0 ? 'danger' : 'default'" />
        <x-stat icon="income" :label="$narrowed ? 'Filtered money in' : 'Money in'" :value="$F::money($statement['moneyIn'], bare: true)" tone="success" />
        <x-stat icon="expenses" :label="$narrowed ? 'Filtered money out' : 'Money out'" :value="$F::money($statement['moneyOut'], bare: true)" tone="warning" />
        <x-stat icon="accounts" :label="$narrowed ? 'Filtered closing total' : 'Closing balance'" :value="$F::money($statement['closing'], bare: true)"
            :hint="$narrowed ? 'Opening + matching movements' : 'At '.$F::date($list['range']['to'])" :tone="$statement['closing'] < 0 ? 'danger' : 'default'" />
        <x-stat icon="accounts" label="Balance now" :value="$F::money($account['balance'], currencyCode: $currency)" :tone="$account['balance'] < 0 ? 'danger' : 'default'" />
    </div>

    <p class="mb-6 text-xs text-content-subtle">
        @if ($narrowed)
            Filtered totals are the opening balance plus matching movements. They exclude hidden movements and do not represent the actual account balance. Clear the filters other than dates to see the full period.
        @else
            Opening {{ $F::money($statement['opening'], bare: true) }} + in {{ $F::money($statement['moneyIn'], bare: true) }} − out {{ $F::money($statement['moneyOut'], bare: true) }} = closing {{ $F::money($statement['closing'], bare: true) }}.
        @endif
    </p>

    <h2 class="mb-3 text-sm font-semibold text-content">Movements</h2>

    <x-filter-bar :list="$list" :dates="true" :fields="[
        ['kind' => 'search', 'key' => 'q', 'label' => 'Search', 'placeholder' => 'Entry number, memo or description', 'wide' => true, 'value' => $list['filters']['search'] ?? ''],
        ['kind' => 'select', 'key' => 'type', 'label' => 'Transaction type', 'allLabel' => 'All types', 'options' => array_combine($sourceTypes, array_map(fn ($t) => $labels[$t] ?? $t, $sourceTypes)), 'value' => $list['filters']['sourceType'] ?? null],
        ['kind' => 'select', 'key' => 'flow', 'label' => 'Direction', 'allLabel' => 'In and out', 'options' => ['in' => 'Money in', 'out' => 'Money out'], 'value' => $list['filters']['flow'] ?? null],
        ['kind' => 'amount-range', 'minKey' => 'min', 'maxKey' => 'max', 'label' => 'Amount',
            'min' => isset($list['filters']['minAmount']) ? \App\Domain\Money::toInputString($list['filters']['minAmount']) : '',
            'max' => isset($list['filters']['maxAmount']) ? \App\Domain\Money::toInputString($list['filters']['maxAmount']) : ''],
    ]" />

    @if ($statement['movements'] === [])
        <x-empty-state :title="$isFiltered ? 'No movements match these filters' : 'Nothing has moved through this account yet'"
            :description="$isFiltered ? 'Try widening the dates, or clear a filter to see more.' : 'Sales, purchases, expenses and payments that use this account will appear here with a running balance.'" />
    @else
        <x-table>
            <x-slot:head>
                <x-th>Date</x-th>
                <x-th>What happened</x-th>
                <x-th>Reference</x-th>
                <x-th numeric>In</x-th>
                <x-th numeric>Out</x-th>
                <x-th numeric>{{ $narrowed ? 'Filtered running total' : 'Balance' }}</x-th>
            </x-slot:head>
            @foreach ($statement['movements'] as $movement)
                @php($link = \App\Livewire\Accounts\Show::sourceHref($movement['sourceType'], $movement['sourceId']))
                <tr class="border-b border-line last:border-0">
                    <x-td>
                        <span class="whitespace-nowrap text-content-muted">@date($movement['entryDate'])</span>
                        <span class="block text-xs text-content-subtle">{{ explode(', ', $F::dateTime($movement['occurredAt']))[1] ?? '' }}</span>
                    </x-td>
                    <x-td>
                        <x-badge :tone="$movement['inMinor'] > 0 ? 'success' : 'warning'">{{ $labels[$movement['sourceType']] ?? $movement['sourceType'] }}</x-badge>
                        <span class="mt-0.5 block text-xs text-content-muted">{{ $movement['description'] ?? $movement['memo'] ?? '' }}</span>
                    </x-td>
                    <x-td>
                        @if ($link)
                            <a href="{{ $link }}" class="text-xs font-medium text-accent hover:underline">{{ $movement['entryNo'] }}</a>
                        @else
                            <span class="text-xs text-content-subtle">{{ $movement['entryNo'] }}</span>
                        @endif
                    </x-td>
                    <x-td numeric>{{ $movement['inMinor'] > 0 ? $F::money($movement['inMinor'], bare: true) : '—' }}</x-td>
                    <x-td numeric>{{ $movement['outMinor'] > 0 ? $F::money($movement['outMinor'], bare: true) : '—' }}</x-td>
                    <x-td numeric><span @class(['font-medium', 'text-danger' => $movement['runningBalance'] < 0])>{{ $F::money($movement['runningBalance'], bare: true) }}</span></x-td>
                </tr>
            @endforeach
        </x-table>
        <x-pagination :list="$list" :total="$total" noun="movement" />
    @endif

    <p class="mt-4 text-xs text-content-subtle">
        {{ $narrowed
            ? 'The table shows only matching movements. Its running total starts at the opening balance and excludes hidden movements.'
            : 'The table shows movements in the selected period, newest first, with the balance after each one. The balance carries across pages from the opening balance.' }}
        Everything here reads the ledger; nothing on this page can change a balance.
    </p>
</div>
