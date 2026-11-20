@php($F = \App\View\Format::class)
@php($kinds = \App\Livewire\Accounts\Index::KIND_LABELS)
<div class="w-full">
    <x-page-header title="Reconciliation" description="Check what the books say against what is actually there." />

    @if ($voided)<x-alert tone="success" class="mb-4">Count voided. Any adjustment was reversed and the record kept.</x-alert>@endif

    @if ($unresolved !== 0)
        <x-alert tone="warning" title="There is money still unaccounted for" class="mb-4">
            {{ $F::money($unresolved, currencyCode: $currency) }} of differences have been recorded but not
            resolved. Either find the money, or record a count that corrects the books.
        </x-alert>
    @endif

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <x-stat icon="accounts" label="Accounts" :value="(string) count($overview)" :hint="$neverCounted > 0 ? $neverCounted.' never counted' : 'All counted'" />
        <x-stat icon="warning" label="Unresolved differences" :value="$F::money($unresolved)" :tone="$unresolved !== 0 ? 'warning' : 'success'" />
        <x-stat icon="books" label="Counts recorded" :value="(string) $historyTotal" />
    </div>

    <h2 class="mb-3 text-sm font-semibold text-content">Where each account stands</h2>
    <x-table class="mb-8">
        <x-slot:head>
            <x-th>Account</x-th>
            <x-th>Type</x-th>
            <x-th numeric>Books say</x-th>
            <x-th>Last counted</x-th>
            <x-th numeric>Last difference</x-th>
            <x-th numeric>Unresolved</x-th>
        </x-slot:head>
        @foreach ($overview as $account)
            <tr class="border-b border-line last:border-0">
                <x-td><a href="{{ \App\Livewire\Accounts\Index::href($account['paymentAccountId']) }}" class="font-medium text-accent hover:underline">{{ $account['accountName'] }}</a></x-td>
                <x-td><span class="text-content-muted">{{ $kinds[$account['kind']] ?? $account['kind'] }}</span></x-td>
                <x-td numeric>{{ $F::money($account['currentBalance'], bare: true) }}</x-td>
                <x-td>
                    @if ($account['lastCountedOn'] === null)
                        <x-badge tone="neutral">Never</x-badge>
                    @else
                        <span class="whitespace-nowrap text-content-muted">@date($account['lastCountedOn'])</span>
                    @endif
                </x-td>
                <x-td numeric>
                    @if ($account['lastDifference'] === null)
                        <span class="text-content-subtle">—</span>
                    @elseif ($account['lastDifference'] === 0)
                        <x-badge tone="success">Agreed</x-badge>
                    @else
                        <span class="font-medium text-warning">{{ $F::money($account['lastDifference'], bare: true) }}</span>
                    @endif
                </x-td>
                <x-td numeric>
                    @if ($account['unresolvedDifference'] === 0)
                        <span class="text-content-subtle">—</span>
                    @else
                        <span class="font-medium text-danger">{{ $F::money($account['unresolvedDifference'], bare: true) }}</span>
                    @endif
                </x-td>
            </tr>
        @endforeach
    </x-table>

    <div class="grid gap-8 lg:grid-cols-5">
        <div class="lg:col-span-3">
            <h2 class="mb-3 text-sm font-semibold text-content">Past counts</h2>
            @if ($history === [])
                <x-empty-state title="Nothing counted yet" description="Counting the till at the end of the day is the quickest way to catch a mistake while you can still remember what happened." />
            @else
                <x-table>
                    <x-slot:head>
                        <x-th>Reference</x-th>
                        <x-th>Date</x-th>
                        <x-th>Account</x-th>
                        <x-th numeric>Books</x-th>
                        <x-th numeric>Counted</x-th>
                        <x-th numeric>Difference</x-th>
                        <x-th>Outcome</x-th>
                        <x-th />
                    </x-slot:head>
                    @foreach ($history as $row)
                        <tr class="border-b border-line last:border-0" wire:key="count-{{ $row['id'] }}">
                            <x-td>
                                <span class="font-medium text-content">{{ $row['reconciliationNo'] }}</span>
                                @if ($row['explanation'])<span class="mt-0.5 block text-xs text-content-subtle">{{ $row['explanation'] }}</span>@endif
                            </x-td>
                            <x-td><span class="whitespace-nowrap text-content-muted">@date($row['businessDate'])</span></x-td>
                            <x-td><span class="text-content-muted">{{ $row['accountName'] }}</span></x-td>
                            <x-td numeric>{{ $F::money($row['expectedMinor'], bare: true) }}</x-td>
                            <x-td numeric>{{ $F::money($row['actualMinor'], bare: true) }}</x-td>
                            <x-td numeric>
                                @if ($row['differenceMinor'] === 0)
                                    <span class="text-content-subtle">—</span>
                                @else
                                    <span @class(['font-medium', 'text-danger' => $row['differenceMinor'] < 0, 'text-success' => $row['differenceMinor'] > 0])>{{ $F::money($row['differenceMinor'], bare: true) }}</span>
                                @endif
                            </x-td>
                            <x-td>
                                @if ($row['status'] === 'VOIDED')
                                    <x-badge tone="danger">Voided</x-badge>
                                @elseif ($row['differenceMinor'] === 0)
                                    <x-badge tone="success">Agreed</x-badge>
                                @elseif ($row['adjusted'])
                                    <x-badge tone="accent">Adjusted</x-badge>
                                @else
                                    <x-badge tone="warning">Open</x-badge>
                                @endif
                            </x-td>
                            <x-td>
                                @if ($row['status'] !== 'VOIDED' && $canVoid)
                                    @include('livewire.cashbook.row-void', ['id' => $row['id'], 'what' => $row['reconciliationNo'], 'placeholder' => 'e.g. Counted the wrong till'])
                                @endif
                            </x-td>
                        </tr>
                    @endforeach
                </x-table>
                <x-step-pager :list="$historyList" :total="$historyTotal" noun="count" newer="newerCounts" older="olderCounts" />
            @endif
        </div>

        <div class="lg:col-span-2">
            <h2 class="mb-3 text-sm font-semibold text-content">Count an account</h2>
            @if ($canCount)
                <form wire:submit="reconcile" class="space-y-4" novalidate>
                    @if ($saved)
                        <x-alert :tone="$saved['difference'] === 0 ? 'success' : 'warning'" title="Count saved — {{ $saved['reconciliationNo'] }}">
                            @if ($saved['difference'] === 0)
                                <p>The books and the count agreed exactly.</p>
                            @else
                                <p>Difference of {{ $F::money($saved['difference'], currencyCode: $currency) }}
                                    {{ $saved['adjusted'] ? 'was posted to Cash Over / Short, so the books now match what you counted.' : 'was recorded and left open. The books are unchanged until you resolve it.' }}</p>
                            @endif
                        </x-alert>
                    @endif
                    @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror

                    <div class="rounded-xl border border-line bg-surface-raised p-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-field label="Account to count" for="paymentAccountId" required>
                                <select id="paymentAccountId" wire:model.live="paymentAccountId" class="h-11 w-full rounded-lg border border-line-strong bg-surface-raised px-3 text-content">
                                    @foreach ($overview as $account)
                                        <option value="{{ $account['paymentAccountId'] }}">{{ $account['accountName'] }}</option>
                                    @endforeach
                                </select>
                            </x-field>
                            <x-field label="Date being counted" for="businessDate" required hint="The balance is compared as at this day.">
                                <x-date-input id="businessDate" wire:model="businessDate" :value="$businessDate" required :invalid="$errors->has('businessDate')" />
                            </x-field>
                        </div>

                        <dl class="mt-4 space-y-2 border-t border-line pt-4 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-content-muted">The books say</dt>
                                <dd class="tabular font-medium text-content">{{ $F::money($expected, currencyCode: $currency) }}</dd>
                            </div>
                        </dl>

                        <div class="mt-4">
                            <x-field label="What did you actually count? ({{ $currency }})" for="actual" required>
                                <x-input amount id="actual" wire:model.live.debounce.300ms="actual" placeholder="0.00" required class="h-12 text-lg" :invalid="$errors->has('actual') || $badAmount" />
                            </x-field>
                        </div>

                        @if ($difference !== null)
                            <div @class(['mt-4 rounded-lg border px-4 py-3', 'border-success/40 bg-success-soft' => $difference === 0, 'border-warning/40 bg-warning-soft' => $difference !== 0])>
                                <div class="flex items-baseline justify-between">
                                    <span class="text-sm font-medium text-content">{{ $difference === 0 ? 'Agrees exactly' : ($difference > 0 ? 'More than the books say' : 'Less than the books say') }}</span>
                                    <span class="tabular text-lg font-semibold text-content">{{ $difference === 0 ? '—' : $F::money($difference, currencyCode: $currency) }}</span>
                                </div>
                                @if ($difference !== 0)
                                    <p class="mt-1 text-xs text-content-muted">Worth recounting before you save. If the difference is real, explain it below.</p>
                                @endif
                            </div>
                        @endif
                    </div>

                    @if ($difference !== null && $difference !== 0)
                        <div class="rounded-xl border border-line bg-surface-raised p-4">
                            <x-field label="What do you think happened?" for="explanation" required hint="Required whenever there is a difference. This is kept permanently.">
                                <x-input id="explanation" wire:model="explanation" placeholder="e.g. Gave wrong change during the rush" required :invalid="$errors->has('explanation')" />
                            </x-field>
                            <div class="mt-4 flex items-start gap-3">
                                <input id="adjust" type="checkbox" wire:model.live="adjust" class="mt-0.5 h-4 w-4 rounded border-line-strong">
                                <label for="adjust" class="text-sm text-content">
                                    Correct the books to match what I counted
                                    <span class="mt-0.5 block text-xs text-content-subtle">
                                        {{ $adjust
                                            ? 'The difference will be posted to Cash Over / Short, so it shows up in your profit figure. Nothing already recorded is changed.'
                                            : 'The books will be left alone and the difference recorded as unresolved, so you can look for the money first.' }}
                                    </span>
                                </label>
                            </div>
                        </div>
                    @endif

                    <x-button type="submit" size="lg" wire:loading.attr="disabled">{{ $difference !== null && $difference !== 0 ? 'Save count and difference' : 'Save count' }}</x-button>

                    <p class="text-xs text-content-subtle">
                        No past transaction is ever edited to make the numbers agree. A difference you accept is
                        posted as its own entry, so the shortage or surplus stays visible in the accounts.
                    </p>
                </form>
            @else
                <p class="rounded-xl border border-line bg-surface-raised p-4 text-sm text-content-muted">You do not have permission to record a count.</p>
            @endif
        </div>
    </div>

    <p class="mt-6 text-xs text-content-subtle">
        Once an account has been counted and agreed, closing the books up to that date under
        <a href="/accounting" class="text-accent hover:underline">Accounting</a>
        stops anything being added behind it.
    </p>
</div>
