<div class="w-full">
    @php($a = fn (int $v): string => \App\View\Format::moneyAccounting($v, currencyCode: $currency))
    @php($blankZero = fn (int $v): string => $v === 0 ? '' : $a($v))
    @php($columns = [$pack['year']->label, $pack['previous']->label])
    @php($yearEnd = \App\View\Format::date($pack['year']->end))
    @php($previousEnd = \App\View\Format::date($pack['previous']->end))

    <div class="no-print">
        <x-page-header title="Year-end pack" description="The statements your accountant needs, for one financial year.">
            <x-slot:actions>
                <x-csv-button report="year-end" :query="['year' => $pack['year']->startYear]" />
                <x-button variant="secondary" size="sm" href="/reports">All reports</x-button>
                <x-button size="sm" x-on:click="window.print()">Print</x-button>
            </x-slot:actions>
        </x-page-header>

        @if (count($years) > 1)
            <x-filter-bar :list="['carried' => []]" :fields="[[
                'kind' => 'select', 'key' => 'year', 'label' => 'Financial year', 'allLabel' => 'Current financial year',
                'options' => collect($years)->mapWithKeys(fn ($y) => [$y->startYear => $y->label])->all(),
                'value' => $pack['year']->startYear,
            ]]" />
        @endif

        @if ($pack['isProvisional'])
            <x-alert tone="warning" title="This year has not finished" class="mb-4">
                These are draft figures up to today, not final accounts. The year ends {{ $yearEnd }}.
            </x-alert>
        @endif
        @unless ($pack['integrity']['trialBalanced'])
            <x-alert tone="danger" title="The books do not balance" class="mb-4">
                Do not give this to an accountant until it is resolved. Debits and credits differ by {{ $a($pack['integrity']['difference']) }}.
            </x-alert>
        @endunless
        @if (! $pack['isLocked'] && ! $pack['isProvisional'])
            <x-alert tone="info" class="mb-4">
                This year is not closed, so entries dated inside it can still be added or changed. To
                fix the figures, set the books lock to {{ $yearEnd }} under Accounting.
            </x-alert>
        @endif
    </div>

    {{-- The pack itself, from here down. --}}
    <header class="mb-8 border-b-2 border-line-strong pb-4">
        <h1 class="text-xl font-semibold text-content">{{ $pack['shop']['name'] }}</h1>
        @if ($pack['shop']['address'])<p class="text-sm text-content-muted">{{ $pack['shop']['address'] }}</p>@endif
        <p class="text-sm text-content-muted">{{ implode(' · ', array_filter([$pack['shop']['phone'], $pack['shop']['email']])) }}</p>
        <p class="mt-3 text-sm font-medium text-content">Financial statements for the year {{ $pack['year']->label }}</p>
        <p class="text-sm text-content-muted">@date($pack['year']->start) to {{ $yearEnd }} · all figures in {{ $currency }}</p>
        <p class="mt-1 text-xs text-content-subtle">
            Prepared @date($today) from {{ $pack['entryCount'] }} journal entries dated in the year{{ $pack['isProvisional'] ? ' · PROVISIONAL, the year has not finished' : '' }}
        </p>
    </header>

    @include('livewire.reports.year-end-statement', ['title' => 'Profit and Loss', 'note' => "For the year ended {$yearEnd}", 'headers' => ['', ...$columns], 'rows' => $profitAndLoss])
    @include('livewire.reports.year-end-statement', ['title' => 'Balance Sheet', 'note' => "As at {$yearEnd}", 'headers' => ['', ...$columns], 'rows' => $balanceSheet])
    @include('livewire.reports.year-end-statement', ['title' => "Movement in the Owner's Stake", 'note' => "What the year did to the owner's capital", 'headers' => ['', $pack['year']->label], 'rows' => $equityRows])

    @include('livewire.reports.year-end-statement', ['title' => 'Cash Flow', 'note' => "Money in and out over the year ended {$yearEnd}", 'headers' => ['', 'In', 'Out'], 'cells' => [
        ["Balance at {$previousEnd}", $a($pack['cashFlow']['openingBalance']), ''],
        ...array_map(fn (array $flow): array => [$flow['label'], $blankZero($flow['inMinor']), $blankZero($flow['outMinor']), '', 'pl-4 text-content-muted'], $pack['cashFlow']['lines']),
        ['Total', $a($pack['cashFlow']['totalIn']), $a($pack['cashFlow']['totalOut']), 'border-t border-line-strong font-semibold', 'text-content'],
        ["Balance at {$yearEnd}", $a($pack['cashFlow']['closingBalance']), '', 'font-semibold', 'font-semibold text-content'],
    ]])

    @if ($pack['receivables'] !== [])
        @include('livewire.reports.year-end-statement', ['title' => 'Owed by Customers', 'note' => 'Outstanding at '.\App\View\Format::date($pack['ageingAsAt']).', by how long it has been owed', 'headers' => ['Customer', 'Over 90 days', 'Total'], 'cells' => [
            ...array_map(fn (array $r): array => [$r['partyName'], $blankZero($r['over90']), $a($r['total'])], $pack['receivables']),
            ['Total owed to the shop', '', $a($pack['balanceSheet']['receivables']), 'border-t border-line-strong font-semibold', 'text-content'],
        ]])
    @endif
    @if ($pack['payables'] !== [])
        @include('livewire.reports.year-end-statement', ['title' => 'Owed to Suppliers', 'note' => 'Outstanding at '.\App\View\Format::date($pack['ageingAsAt']).', by how long it has been owed', 'headers' => ['Supplier', 'Over 90 days', 'Total'], 'cells' => [
            ...array_map(fn (array $r): array => [$r['partyName'], $blankZero($r['over90']), $a($r['total'])], $pack['payables']),
            ['Total owed by the shop', '', $a($pack['balanceSheet']['payables']), 'border-t border-line-strong font-semibold', 'text-content'],
        ]])
    @endif

    @include('livewire.reports.year-end-statement', ['title' => 'Trial Balance', 'note' => "As at {$yearEnd}", 'headers' => ['Account', 'Debit', 'Credit'], 'cells' => [
        ...array_map(fn (array $r): array => ["{$r['code']} {$r['name']}", $blankZero($r['debit']), $blankZero($r['credit'])], $pack['trialBalance']['lines']),
        ['Total', $a($pack['trialBalance']['totalDebit']), $a($pack['trialBalance']['totalCredit']), 'border-t border-line-strong font-semibold', 'text-content'],
    ]])

    <section class="mb-8 break-inside-avoid">
        <h2 class="mb-2 text-base font-semibold text-content">Notes to the statements</h2>
        <ol class="list-decimal space-y-2 pl-5 text-sm text-content-muted">
            <li><span class="font-medium text-content">Basis of preparation.</span> These statements
                are prepared from a double-entry ledger. Every transaction is recorded as balanced
                debits and credits, and no balance is stored — each figure above is computed from the
                underlying journal lines, which can be inspected for any amount shown.</li>
            <li><span class="font-medium text-content">Stock.</span> Valued at weighted average
                cost. Cost of goods sold is the cost recorded at the time of each sale, not a
                recalculation at the year end.</li>
            <li><span class="font-medium text-content">Credit sales and purchases.</span> Recorded
                when the sale or purchase happens, not when the money moves. Amounts still outstanding
                appear as owed by customers and owed to suppliers.</li>
            <li><span class="font-medium text-content">No closing entries.</span> Revenue and
                expense accounts are not zeroed at the year end. All-time profit is carried within the
                owner&apos;s stake, which is why the Trial Balance above shows cumulative figures while
                the Profit and Loss covers this year only. The Movement in the Owner&apos;s Stake
                reconciles the two.</li>
            <li><span class="font-medium text-content">Corrections.</span> Nothing already recorded
                is edited or deleted. A correction is posted as a dated reversing entry, so the original
                and its reversal both remain visible.</li>
            @if ($pack['booksLockedBefore'])
                <li><span class="font-medium text-content">Period closed.</span> Entries dated on or
                    before @date($pack['booksLockedBefore']) are refused by the system.</li>
            @endif
        </ol>
    </section>

    <section class="break-inside-avoid border-t border-line pt-4">
        <h2 class="mb-2 text-sm font-semibold text-content">Checks performed</h2>
        <ul class="space-y-1 text-sm text-content-muted">
            <li>Trial balance {{ $pack['trialBalance']['balanced'] ? 'balances' : 'DOES NOT BALANCE' }} — debits {{ $a($pack['trialBalance']['totalDebit']) }}, credits {{ $a($pack['trialBalance']['totalCredit']) }}</li>
            <li>Every entry ever posted {{ $pack['integrity']['trialBalanced'] ? 'balances' : 'DOES NOT BALANCE' }} — gross debits {{ $a($pack['integrity']['totalDebit']) }}, gross credits {{ $a($pack['integrity']['totalCredit']) }}</li>
            <li>Balance sheet {{ $pack['balanceSheet']['balances'] ? 'balances' : 'DOES NOT BALANCE' }}</li>
            <li>Owed by customers {{ $pack['integrity']['receivablesMatch'] ? 'agrees' : 'DOES NOT AGREE' }} with the customer records</li>
            <li>Owed to suppliers {{ $pack['integrity']['payablesMatch'] ? 'agrees' : 'DOES NOT AGREE' }} with the supplier records</li>
            <li>Owner&apos;s stake {{ $pack['equity']['reconciles'] ? 'reconciles' : 'DOES NOT RECONCILE' }} from opening to closing</li>
            <li>Cash flow {{ $pack['cashFlow']['reconciles'] ? 'reconciles' : 'DOES NOT RECONCILE' }} — opening plus movement equals closing</li>
        </ul>
    </section>

    <p class="mt-6 text-xs text-content-subtle">Financial year starting in {{ $startMonthName }}. Change it under Settings.</p>
</div>
