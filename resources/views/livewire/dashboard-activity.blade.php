{{--
    Transaction History, shared by both dashboard layouts: the journal's ten
    newest entries (LedgerService::listJournalEntries, the /accounting/journal
    list), optionally one kind. Shown only with the Accounts right, the journal
    page's own. Formatting only: every amount is the service's entry total.
--}}
@use('App\Domain\Dashboard', 'Windows')
@use('App\Livewire\Accounting\Journal')
@use('App\View\Format')
<section aria-labelledby="activity-heading" class="min-w-0 border border-line bg-surface-raised p-4" style="border-radius: var(--card-radius); box-shadow: var(--card-shadow)">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h2 id="activity-heading" class="text-base font-semibold text-content">Transaction History</h2>
        <a href="/accounting/journal{{ $this->activity === null ? '' : '?source='.$this->activity }}" class="rounded text-sm font-medium text-accent hover:underline focus-visible:outline-2 focus-visible:outline-accent">View all in the journal →</a>
    </div>
    <nav aria-label="Transaction kind" class="mb-3 flex flex-wrap gap-1.5 text-xs">
        @foreach ([null => 'All'] + Windows::ACTIVITY as $key => $text)
            @php($kind = $key === '' ? null : $key)
            <a href="{{ Windows::dashboardRangeHref($this->sales, $this->spend, $kind) }}#activity-heading"
               @if ($this->activity === $kind) aria-current="true" @endif
               @class(['rounded-full border px-3 py-1.5 font-medium focus-visible:outline-2 focus-visible:outline-accent', 'border-accent bg-accent-soft text-accent' => $this->activity === $kind, 'border-line text-content-muted hover:bg-surface-sunken' => $this->activity !== $kind])>{{ $text }}</a>
        @endforeach
    </nav>
    @if ($recent === [])
        <p class="py-3 text-sm text-content-muted">{{ $this->activity === null ? 'No transactions recorded yet.' : 'No '.strtolower(Windows::ACTIVITY[$this->activity]).' recorded yet.' }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-[36rem] text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-xs text-content-muted">
                        <th scope="col" class="py-2 pr-3 font-medium">Date</th>
                        <th scope="col" class="py-2 pr-3 font-medium">Kind</th>
                        <th scope="col" class="py-2 pr-3 font-medium">What it was</th>
                        <th scope="col" class="py-2 pr-3 font-medium">Entry</th>
                        <th scope="col" class="py-2 text-right font-medium">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recent as $entry)
                        <tr class="border-b border-line last:border-0">
                            <td class="whitespace-nowrap py-2 pr-3 text-content-muted">@date($entry['entryDate'])</td>
                            <td class="py-2 pr-3">
                                <x-badge :tone="$entry['reversesEntryId'] !== null ? 'neutral' : 'accent'">{{ Journal::SOURCE_LABELS[$entry['sourceType']] ?? $entry['sourceType'] }}</x-badge>
                                @if ($entry['reversedByEntryId'] !== null)<x-badge tone="danger">Reversed</x-badge>@endif
                            </td>
                            <td class="max-w-[22rem] truncate py-2 pr-3 text-content">{{ $entry['memo'] ?? '—' }}</td>
                            <td class="whitespace-nowrap py-2 pr-3"><a href="/accounting/journal/{{ $entry['id'] }}" class="rounded font-medium text-accent hover:underline focus-visible:outline-2 focus-visible:outline-accent">{{ $entry['entryNo'] }}</a></td>
                            <td class="tabular whitespace-nowrap py-2 text-right font-semibold">@money($entry['total'])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-content-muted">The {{ count($recent) }} newest entries{{ $this->activity === null ? '' : ' of this kind' }}. Amount is each entry's total; corrections show as reversals, never edits.</p>
    @endif
</section>
