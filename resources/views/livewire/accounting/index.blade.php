<div class="w-full">
    <x-page-header title="Accounting" description="The books underneath everything else — and the proof that they add up." />

    @if ($problems !== [])
        <x-alert tone="danger" title="Something is wrong with the books" class="mb-6">
            <ul class="mt-1 list-inside list-disc">
                @foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach
            </ul>
            <p class="mt-2">Every one of these should be impossible. Please report it before recording anything else.</p>
        </x-alert>
    @else
        <x-alert tone="success" title="The books are healthy" class="mb-6">
            Debits equal credits, every entry balances on its own, customer and supplier balances agree with their control accounts, and every entry traces back to a real transaction.
        </x-alert>
    @endif

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <x-stat icon="books" label="Total debits" :value="\App\View\Format::money($integrity['totalDebit'])" :hint="'= credits '.\App\View\Format::money($integrity['totalCredit'], bare: true)" />
        <x-stat icon="owed" label="Customers owe you" :value="\App\View\Format::money($receivables)" :tone="$receivables > 0 ? 'warning' : 'default'" />
        <x-stat icon="owes" label="You owe suppliers" :value="\App\View\Format::money($payables)" :tone="$payables > 0 ? 'warning' : 'default'" />
    </div>

    @if ($canControl)
        <div class="mb-6 space-y-4">
            {{-- Year-end close (the old year-end-form.tsx) --}}
            <x-card>
                <h2 class="text-sm font-semibold text-content">Year-end close</h2>
                <p class="mt-2 text-sm text-content-muted">Closing a year sweeps its sales and costs back to zero and carries the profit into Retained Earnings, so the next year starts fresh. Your drawings for the year are cleared the same way.</p>
                <p class="mt-2 text-sm text-content-muted">Nothing already recorded is altered — the close is one dated journal entry you can inspect, and reversing it reopens the year.</p>

                @error('year')<x-alert tone="danger" class="mt-4">{{ $message }}</x-alert>@enderror
                @if ($yearMessage)<x-alert tone="success" class="mt-4">{{ $yearMessage }}</x-alert>@endif

                @if ($closable === [])
                    <x-alert tone="info" class="mt-4">There is no finished year waiting to be closed. A year can only be closed once it has ended.</x-alert>
                @else
                    <form wire:submit="closeYear" class="mt-4 flex flex-wrap items-end gap-2">
                        <div>
                            <label for="startYear" class="mb-1 block text-xs text-content-muted">Year to close</label>
                            <select id="startYear" wire:model="startYear" class="h-10 rounded-lg border border-line-strong bg-surface-raised px-3 text-sm text-content">
                                @foreach ($closable as $year)
                                    <option value="{{ $year['startYear'] }}">{{ $year['label'] }} (ends {{ $year['end'] }})</option>
                                @endforeach
                            </select>
                        </div>
                        <x-button type="submit" size="sm" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="closeYear">Close this year</span>
                            <span wire:loading wire:target="closeYear">Working…</span>
                        </x-button>
                    </form>
                @endif

                @if ($closed !== [])
                    <div class="mt-6 border-t border-line pt-4">
                        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-content-subtle">Closed years</p>
                        <ul class="space-y-2">
                            @foreach ($closed as $year)
                                <li class="flex flex-wrap items-center gap-3" wire:key="closed-{{ $year['startYear'] }}">
                                    <x-badge tone="success">{{ $year['label'] }}</x-badge>
                                    <span class="text-sm text-content-muted">closed to {{ $year['end'] }}</span>
                                    <form wire:submit="reopenYear({{ $year['startYear'] }})" class="ml-auto">
                                        <x-button type="submit" size="sm" variant="secondary" wire:loading.attr="disabled">Reopen</x-button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-3 text-xs text-content-subtle">Reopening reverses the closing entry rather than deleting it, and is recorded in the audit log. Years reopen newest first.</p>
                    </div>
                @endif
            </x-card>

            {{-- Books lock (the old books-lock-form.tsx): moving it BACK reopens a closed period, so the form says so. --}}
            <div x-data="{ open: {{ $errors->has('lockedBefore') || $errors->has('lock') ? 'true' : 'false' }}, saved: @js($lock['lockedBefore']), value: $wire.entangle('lockedBefore') }"
                 x-on:books-lock-saved.window="open = false; saved = value === '' ? null : value"
                 x-init="$watch('open', o => { if (! o) value = saved ?? '' })">
                <div x-show="! open" class="rounded-xl border border-line bg-surface-raised p-4">
                    <h2 class="text-sm font-semibold text-content">Books lock</h2>
                    @if ($lock['lockedBefore'] === null)
                        <p class="mt-1 mb-3 text-sm text-content-muted">Nothing is locked. Anyone with permission can record a transaction dated any day, including in a month you have already reviewed.</p>
                    @else
                        <p class="mt-1 mb-3 text-sm text-content-muted">The books are closed up to <strong class="text-content">{{ $lock['lockedBefore'] }}</strong>. {{ $lock['entriesLocked'] }} entry(ies) are protected. Nothing dated on or before that day can be recorded or changed.</p>
                    @endif
                    <x-button variant="secondary" x-on:click="open = true">{{ $lock['lockedBefore'] === null ? 'Close the books up to a date…' : 'Change the lock date…' }}</x-button>
                </div>

                <form x-show="open" x-cloak wire:submit="setLock" novalidate
                      x-bind:class="saved !== null && (value === '' || value < saved) ? 'border-danger/40 bg-danger-soft' : 'border-line bg-surface-raised'"
                      class="rounded-xl border p-4">
                    <h2 class="mb-1 text-sm font-semibold text-content" x-text="saved !== null && (value === '' || value < saved) ? 'Reopen a closed period?' : 'Close the books'">Close the books</h2>
                    <p class="mb-3 text-sm text-content-muted">Transactions dated on or before this day will be refused. Leave it blank to remove the lock entirely.</p>
                    @error('lock')<x-alert tone="danger" class="mb-3">{{ $message }}</x-alert>@enderror
                    <div class="max-w-xs">
                        <x-field label="Closed up to and including" for="lockedBefore" :hint="'Today is '.$today.'.'">
                            <x-date-input id="lockedBefore" x-model="value" :value="$lockedBefore" :max="$today" :invalid="$errors->has('lockedBefore')" />
                        </x-field>
                    </div>
                    <template x-if="saved !== null && (value === '' || value < saved)">
                        <x-alert tone="danger" class="mt-3">This reopens a period that was declared final. It will be recorded in the audit log as a reopening, with your name against it.</x-alert>
                    </template>
                    <p class="mt-3 text-xs text-content-subtle">Mistakes in a closed period are still correctable — void the transaction, which posts a dated reversal today rather than quietly rewriting history.</p>
                    <div class="mt-4 flex items-center gap-3">
                        <x-button type="submit" variant="danger" wire:loading.attr="disabled" x-show="saved !== null && (value === '' || value < saved)">Yes, reopen the books</x-button>
                        <x-button type="submit" wire:loading.attr="disabled" x-show="! (saved !== null && (value === '' || value < saved))">Close the books</x-button>
                        <x-button variant="ghost" x-on:click="open = false">Cancel</x-button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <div class="grid gap-3 sm:grid-cols-2">
        @foreach ($sections as $section)
            <a href="{{ $section['href'] }}" class="block">
                <x-card class="h-full transition-colors hover:bg-surface-sunken">
                    <p class="font-medium text-accent">{{ $section['title'] }}</p>
                    <p class="mt-1 text-sm text-content-muted">{{ $section['description'] }}</p>
                </x-card>
            </a>
        @endforeach
    </div>

    <p class="mt-6 text-xs text-content-subtle">Nothing on these pages is stored. Every figure is calculated from the same accounting entries that each sale, purchase, payment and expense writes, so what you see here is what actually happened.</p>
</div>
