<div class="w-full">
    <x-page-header :title="$side['title']" :description="$side['description']">
        <x-slot:actions>
            <x-csv-button :list="$side['csv']" :query="$list['carried']" />
            @if ($side['otherIncomeLink'])
                <x-button variant="secondary" size="sm" href="/income">Other income</x-button>
            @endif
            <x-create-menu size="sm" />
        </x-slot:actions>
    </x-page-header>

    @if ($created)<x-alert tone="success" class="mb-4">{{ $side['created'] }}</x-alert>@endif
    @if ($voided)<x-alert tone="success" class="mb-4">{{ $side['voided'] }}</x-alert>@endif
    @if ($edited)<x-alert tone="success" class="mb-4">{{ $side['edited'] }}</x-alert>@endif

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <x-stat :label="$side['countLabel']" :icon="$side['icon']" :value="(string) $summary['count']" :hint="$periodHint" />
        <x-stat :label="$side['totalLabel']" :icon="$side['icon']" :value="\App\View\Format::money($summary['total'], currencyCode: $currency)" />
        <x-stat :label="$side['averageLabel']" :value="\App\View\Format::money($summary['average'], currencyCode: $currency)" />
    </div>

    <x-filter-bar compact :list="$list" :dates="true" :fields="array_values(array_filter([
        ['kind' => 'search', 'key' => 'q', 'label' => 'Search', 'placeholder' => 'Description, reference or note', 'wide' => true, 'value' => $list['filters']['search'] ?? ''],
        ['kind' => 'select', 'key' => 'category', 'label' => 'Category', 'allLabel' => 'All categories', 'options' => array_column($categories, 'name', 'id'), 'value' => $list['filters']['categoryAccountId'] ?? null],
        ['kind' => 'select', 'key' => 'account', 'label' => $side['accountLabel'], 'allLabel' => 'Any account', 'options' => array_column($accounts, 'name', 'id'), 'value' => $list['filters']['paymentAccountId'] ?? null],
        $side['staffFilter'] ? ['kind' => 'select', 'key' => 'staff', 'label' => 'Recorded by', 'allLabel' => 'Anyone', 'options' => array_column($staff, 'displayName', 'id'), 'value' => $list['filters']['staffId'] ?? null] : null,
        ['kind' => 'select', 'key' => 'status', 'label' => 'Status', 'allLabel' => 'All', 'options' => ['POSTED' => 'Posted', 'VOIDED' => 'Voided'], 'value' => $list['filters']['status'] ?? null],
        ['kind' => 'amount-range', 'minKey' => 'min', 'maxKey' => 'max', 'label' => 'Amount',
            'min' => isset($list['filters']['minAmount']) ? \App\Domain\Money::toInputString($list['filters']['minAmount']) : '',
            'max' => isset($list['filters']['maxAmount']) ? \App\Domain\Money::toInputString($list['filters']['maxAmount']) : ''],
    ]))" />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            @if ($rows === [])
                <x-empty-state :title="$isFiltered ? $side['emptyFiltered'] : $side['emptyTitle']"
                    :description="$isFiltered ? 'Try widening the dates, or clear a filter to see more.' : $side['emptyDescription']" />
            @else
                <x-table>
                    <x-slot:head>
                        <x-th sort="date" :sorts="\App\Services\CashbookService::CASHBOOK_SORTS" :list="$list">Date</x-th>
                        <x-th>Description</x-th>
                        <x-th sort="category" :sorts="\App\Services\CashbookService::CASHBOOK_SORTS" :list="$list" default-direction="asc">Category</x-th>
                        <x-th>{{ $side['accountLabel'] }}</x-th>
                        <x-th sort="amount" :sorts="\App\Services\CashbookService::CASHBOOK_SORTS" :list="$list" numeric>Amount</x-th>
                        <x-th />
                    </x-slot:head>
                    @foreach ($rows as $row)
                        <tr class="border-b border-line last:border-0" wire:key="row-{{ $row['id'] }}">
                            <x-td><span class="whitespace-nowrap text-content-muted">@date($row['businessDate'])</span></x-td>
                            <x-td>
                                <span class="font-medium text-content">{{ $row['description'] }}</span>
                                @if ($row['editedAt'])<x-badge tone="accent" class="ml-2">Edited</x-badge>@endif
                                @if ($row['reference'])<span class="ml-2 text-xs text-content-subtle">{{ $row['reference'] }}</span>@endif
                            </x-td>
                            <x-td><span class="text-content-muted">{{ $row['categoryName'] }}</span></x-td>
                            <x-td><span class="text-content-muted">{{ $row['paymentAccountName'] }}</span></x-td>
                            <x-td numeric><span @class(['line-through opacity-60' => $row['status'] === 'VOIDED'])>{{ \App\View\Format::money($row['amountMinor'], bare: true) }}</span></x-td>
                            <x-td>
                                @if ($row['status'] === 'VOIDED')
                                    <x-badge tone="danger">Voided</x-badge>
                                @else
                                    <div class="flex items-start gap-1">
                                        @if ($canEdit && $editId !== (int) $row['id'])
                                            <x-button variant="ghost" size="sm" wire:click="startEdit({{ $row['id'] }})" aria-label="Edit {{ $row['description'] }}">Edit</x-button>
                                        @endif
                                        @if ($canVoid)
                                            @include('livewire.cashbook.row-void', ['id' => $row['id'], 'what' => $row['description']])
                                        @endif
                                    </div>
                                @endif
                            </x-td>
                        </tr>
                        @if ($editId === (int) $row['id'])
                            <tr wire:key="edit-{{ $row['id'] }}"><td colspan="6" class="p-3">
                                @include('livewire.cashbook.row-edit', ['what' => $row['documentNo']])
                            </td></tr>
                        @endif
                    @endforeach
                </x-table>
                <x-pagination :list="$list" :total="$total" :noun="$side['noun']" :noun-plural="$side['nounPlural']" />
            @endif

            @if ($byCategory !== [])
                <h2 class="mt-8 mb-3 text-sm font-semibold text-content">Where the money went</h2>
                <x-table>
                    <x-slot:head>
                        <x-th>Category</x-th>
                        <x-th numeric>Entries</x-th>
                        <x-th numeric>Total</x-th>
                    </x-slot:head>
                    @foreach ($byCategory as $row)
                        <tr class="border-b border-line last:border-0">
                            <x-td><span class="font-medium text-content">{{ $row['categoryName'] }}</span></x-td>
                            <x-td numeric>{{ $row['count'] }}</x-td>
                            <x-td numeric>{{ \App\View\Format::money($row['total'], bare: true) }}</x-td>
                        </tr>
                    @endforeach
                    <tr class="bg-surface-sunken font-semibold">
                        <x-td>Total</x-td>
                        <x-td numeric>{{ $summary['count'] }}</x-td>
                        <x-td numeric>{{ \App\View\Format::money($summary['total'], bare: true) }}</x-td>
                    </tr>
                </x-table>
            @endif
        </div>

        <div>
            @if ($canCreate && ($side['recordPage'] ?? null))
                <x-button size="lg" :href="$side['recordPage']">Record expenses</x-button>
            @elseif ($canCreate)
                <h2 id="record-income" class="mb-3 scroll-mt-20 text-sm font-semibold text-content">{{ $side['formHeading'] }}</h2>
                <form wire:submit="record" class="space-y-4" novalidate>
                    @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
                    <div class="rounded-xl border border-line bg-surface-raised p-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-field label="Date" for="businessDate" required>
                                <x-date-input id="businessDate" wire:model="businessDate" :value="$businessDate" :invalid="$errors->has('businessDate')" required />
                            </x-field>
                            <x-field label="Category" for="categoryAccountId" required :hint="$categories === [] ? 'Add a category first.' : null">
                                <select id="categoryAccountId" wire:model="categoryAccountId" required @disabled($categories === [])
                                    class="h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content disabled:opacity-60">
                                    <option value="">Choose…</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category['id'] }}">{{ $category['name'] }}</option>
                                    @endforeach
                                </select>
                            </x-field>
                            <x-field label="Description" for="description" required class="sm:col-span-2">
                                <x-input id="description" wire:model="description" :placeholder="$side['descriptionPlaceholder']" required :invalid="$errors->has('description')" />
                            </x-field>
                            <x-field label="Amount ({{ $currency }})" for="amount" required>
                                <x-input amount id="amount" wire:model="amount" placeholder="0.00" required :invalid="$errors->has('amount')" />
                            </x-field>
                            <x-field :label="$side['accountLabel']" for="paymentAccountId" required>
                                <select id="paymentAccountId" wire:model="paymentAccountId" required
                                    class="h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content">
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account['id'] }}">{{ $account['name'] }}</option>
                                    @endforeach
                                </select>
                            </x-field>
                            <x-field label="Reference" for="reference" hint="Receipt or transaction number.">
                                <x-input id="reference" wire:model="reference" placeholder="Optional" />
                            </x-field>
                            <x-field label="Note" for="note">
                                <x-input id="note" wire:model="note" placeholder="Optional" />
                            </x-field>
                        </div>
                    </div>
                    <x-button type="submit" size="lg" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="record">{{ $side['submit'] }}</span>
                        <span wire:loading wire:target="record">Saving…</span>
                    </x-button>
                </form>
            @endif
            @if ($canCreate)
                <div class="mt-3" x-data="{ open: false }" x-on:category-added.window="open = false">
                    <x-button variant="ghost" size="sm" x-show="! open" x-on:click="open = true">+ Add a category</x-button>
                    <form x-show="open" x-cloak wire:submit="addCategory" class="flex flex-wrap items-end gap-2" novalidate>
                        <div class="min-w-[12rem]">
                            <x-field :label="$side['newCategoryLabel']" for="new-category" name="name">
                                <x-input id="new-category" wire:model="newCategory" :placeholder="$side['newCategoryPlaceholder']" class="h-10" :invalid="$errors->has('name')" />
                            </x-field>
                        </div>
                        <x-button type="submit" size="sm">Add</x-button>
                        <x-button size="sm" variant="ghost" x-on:click="open = false">Cancel</x-button>
                        @error('category')<x-alert tone="danger" class="w-full">{{ $message }}</x-alert>@enderror
                    </form>
                </div>
            @else
                <x-card><p class="text-sm text-content-muted">{{ $side['noRight'] }}</p></x-card>
            @endif
        </div>
    </div>
</div>
