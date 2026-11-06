{{-- The old (app)/dashboard/page.tsx, panel for panel. Figures are minor units from the services; this only formats them. --}}
@use('App\Domain\Dashboard', 'Windows')
@use('App\Livewire\Dashboard')
@use('App\View\Format')
@php
    $dates = fn (array $p): string => Format::date($p['from']).' to '.Format::date($p['to']).' (inclusive)';
    $reportHref = fn (string $path, array $p): string => "{$path}?period=custom&from={$p['from']}&to={$p['to']}";
    $salesHref = "/sales?period=custom&from={$salesPeriod['from']}&to={$salesPeriod['to']}";

    $attention = [];
    if ($aged !== null && $aged['overdue'] > 0) {
        $attention[] = ['title' => Format::money($aged['overdue']).' overdue receivables', 'note' => 'Includes amounts 1–30 days overdue and all older buckets. Customer credits can reduce bucket totals.', 'href' => '/customers?balance=owing'];
    }
    foreach ($paymentAccounts as $account) {
        if ($account['balance'] < 0) {
            $attention[] = ['negativeBalance' => true, 'title' => $account['name'].': '.Format::money($account['balance']), 'note' => 'Negative ledger balance. This may be an overdraft; it is not a confirmed reconciliation error.', 'href' => Dashboard::accountHref($account['id'])];
        }
    }
    if ($stock !== null && ($stock['lowStockCount'] > 0 || $stock['outOfStockCount'] > 0)) {
        $attention[] = ['title' => "{$stock['lowStockCount']} low-stock and {$stock['outOfStockCount']} out-of-stock stock items", 'note' => 'Review stock levels before recording the next sale.', 'href' => '/inventory'];
    }
    foreach ($differences as $account) {
        $attention[] = ['title' => $account['accountName'].': '.Format::money($account['unresolvedDifference']).' unresolved count difference', 'note' => 'Recorded counts differ from the books and have not been adjusted.', 'href' => '/reconciliation'];
    }
    foreach ($failedChecks as $check) {
        $attention[] = ['title' => $check['label'], 'note' => 'This integrity check needs investigation.', 'href' => '/accounting'];
    }
    if ($aged !== null && ! $aged['reconciles']) {
        $attention[] = ['title' => Format::money($aged['unaged']).' outside invoice ageing', 'note' => 'Ledger balances include amounts without an invoice due-date bucket. Review allocations and opening balances.', 'href' => '/customers'];
    }
@endphp
<div class="stitch-dashboard w-full space-y-4">
    <header class="space-y-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-content">Dashboard</h1>
                <p class="mt-1 text-sm text-content-muted">{{ $user->display_name }}, your financial overview · @date($today)</p>
            </div>
            <div class="flex flex-wrap gap-2" aria-label="Quick actions">
                @foreach ($actions as $action)
                    <a href="{{ $action['href'] }}" @class(['inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-accent',
                        'border-accent bg-accent text-accent-text hover:opacity-90' => $action['href'] === '/sales/new',
                        'border-line bg-surface-raised text-accent hover:bg-accent-soft' => $action['href'] !== '/sales/new'])>
                        <x-icon :name="$action['icon']" class="h-4 w-4" />
                        {{ $action['label'] }}
                    </a>
                @endforeach
            </div>
            {{-- Jinu's Create Transaction menu (shown by that look only; the quick actions above serve the others).
                 A native <details>: opens with Enter or Space, no JavaScript needed; Alpine only closes it on Escape or a click elsewhere. --}}
            @if ($createMenu !== [])
                <details class="create-menu relative" x-data x-on:click.outside="$el.open = false" x-on:keydown.escape="$el.open = false; $refs.summary.focus()">
                    <summary x-ref="summary" class="inline-flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-lg border border-accent bg-accent px-4 py-2 text-sm font-semibold text-accent-text hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent [&::-webkit-details-marker]:hidden">
                        <x-icon name="add" class="h-4 w-4" />
                        Create Transaction
                        <svg aria-hidden="true" viewBox="0 0 16 16" class="create-menu-chevron h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="m4 6 4 4 4-4"/></svg>
                    </summary>
                    <ul class="absolute z-30 mt-2 w-[17.5rem] max-w-[calc(100vw-2rem)] max-sm:left-0 sm:right-0 rounded-lg border border-line bg-surface-raised p-1.5 shadow-lg" aria-label="Create transaction">
                        @foreach ($createMenu as $item)
                            <li><a href="{{ $item['href'] }}" class="block rounded px-3 py-2 text-sm text-content hover:bg-accent-soft hover:text-accent focus-visible:bg-accent-soft focus-visible:text-accent focus-visible:outline-none">{{ $item['label'] }}</a></li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>
    </header>

    @if ($nothingToShow)
        <x-alert tone="info" title="No dashboard summaries available">Use the menu to access your work. Ask the owner if you need access to financial summaries.</x-alert>
    @endif

    <section aria-label="Primary financial figures" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <h2 class="sr-only">Primary financial figures</h2>
        @if ($shows['money'])
            <x-dashboard.card title="Cash & Bank Balance" icon="accounts" href="/accounts" period="Current ledger balance · all recorded dates">
                <x-dashboard.figure :value="$cashHeld" hint="Cash, bank, mobile money and other active payment accounts" />
            </x-dashboard.card>
        @endif
        @if ($rangeSales !== null)
            <x-dashboard.card title="Sales" icon="sales" :href="$salesHref">
                @include('livewire.dashboard-period', ['label' => 'Sales', 'param' => 'sales'])
                <x-dashboard.figure :value="$rangeSales['total']" :hint="$rangeSales['count'].' posted sale'.($rangeSales['count'] === 1 ? '' : 's').' · transaction total'" />
            </x-dashboard.card>
        @endif
        @if ($spendTotal !== null)
            <x-dashboard.card title="Expenses" icon="expenses" :href="$reportHref('/expenses', $spendPeriod)">
                @include('livewire.dashboard-period', ['label' => 'Expenses', 'param' => 'spend'])
                <x-dashboard.figure :value="$spendTotal" hint="Recorded expense transactions; excludes other ledger adjustments" />
            </x-dashboard.card>
        @endif
        @if ($pl !== null)
            <x-dashboard.card title="Net Profit" icon="profit" :href="$reportHref('/reports/profit-and-loss', $profitPeriod)" period="Month to date">
                <x-dashboard.figure :value="$pl['netProfit']" hint="Net sales − cost of goods + other income − all ledger expenses" />
            </x-dashboard.card>
        @endif
    </section>

    @unless ($nothingToShow)
        <section aria-labelledby="attention-heading" class="needs-attention rounded-[var(--panel-radius,var(--radius-xl))] border border-line bg-surface-raised p-5">
            <h2 id="attention-heading" class="mb-3 flex items-center gap-2 text-xl font-semibold leading-7 text-content">
                <x-icon name="warning" class="attention-heading-icon h-[18px] w-[18px]" />
                Needs Attention
            </h2>
            <div class="min-w-0">
                @if ($attention === [])
                    <p class="py-3 text-sm text-content-muted">No issues found in the dashboard checks available to your account.</p>
                @else
                    <ul class="grid gap-2.5 md:grid-cols-2">
                        @foreach ($attention as $item)
                            <li @class(['min-w-0 rounded-[var(--panel-radius,var(--radius-lg))] border border-line bg-surface-sunken p-4', 'negative-account-alert' => $item['negativeBalance'] ?? false])>
                                <a href="{{ $item['href'] }}" class="inline-block rounded text-sm font-medium text-accent hover:underline focus-visible:outline-2 focus-visible:outline-accent">{{ $item['title'] }}</a>
                                <p class="attention-note mt-1 text-content-muted">{{ $item['note'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    @endunless

    @if ($shows['sales'] || $shows['spending'] || $shows['money'])
        <section aria-labelledby="trends-heading">
            <h2 id="trends-heading" class="sr-only">Financial trends</h2>
            <div class="grid gap-3 md:grid-cols-2">
                @if ($shows['sales'] || $shows['spending'])
                    <x-dashboard.card title="Sales & expense trends" icon="sales">
                        <div class="grid gap-3 sm:grid-cols-2">
                            @if ($shows['sales'])
                                <div class="min-w-0">
                                    <a href="{{ $salesHref }}" class="inline-block rounded text-sm font-medium text-accent hover:underline">Daily sales</a>
                                    <p class="mb-2 mt-1 text-xs text-content-muted">{{ $dates($salesPeriod) }}</p>
                                    @if ($salesByDay !== [])
                                        <x-chart.trend :data="Windows::dashboardDailySeries($salesByDay, $salesPeriod)" :summary="'Daily sales: '.$dates($salesPeriod).'. Total '.Format::money($rangeSales['total']).'.'" />
                                    @else
                                        <p class="text-sm text-content-muted">No sales recorded in this period.</p>
                                    @endif
                                    <p class="mt-2 text-xs text-content-muted">Today (@date($today)): @money($todaySales['total'])</p>
                                </div>
                            @endif
                            @if ($shows['spending'])
                                <div class="min-w-0 border-t border-line pt-3 sm:border-t-0 sm:border-l sm:pt-0 sm:pl-3">
                                    <a href="{{ $reportHref('/expenses', $spendPeriod) }}" class="inline-block rounded text-sm font-medium text-accent hover:underline">Daily expenses</a>
                                    <p class="mb-2 mt-1 text-xs text-content-muted">{{ $dates($spendPeriod) }}</p>
                                    @if ($expenseDays !== [])
                                        <x-chart.trend :data="Windows::dashboardDailySeries($expenseDays, $spendPeriod)" :summary="'Recorded daily expenses: '.$dates($spendPeriod).'. Total '.Format::money($spendTotal).'.'" />
                                    @else
                                        <p class="text-sm text-content-muted">No expense transactions recorded in this period.</p>
                                    @endif
                                    @if ($categories !== [])
                                        <details class="mt-3 text-sm">
                                            <summary class="cursor-pointer text-accent">Expense categories</summary>
                                            <dl class="mt-2 space-y-1">
                                                @foreach ($categories as $category)
                                                    <div class="flex justify-between gap-3">
                                                        <dt class="text-content-muted">{{ $category['categoryName'] }}</dt>
                                                        <dd class="tabular">@money($category['total'])</dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        </details>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </x-dashboard.card>
                @endif
                @if ($shows['money'])
                    <x-dashboard.card title="Cash movements" icon="cashflow" :href="$shows['profit'] ? '/reports/cash-flow' : '/accounts'"
                        :period="($moneyMonths[0]['month'] ?? '').' through '.($moneyMonths[count($moneyMonths) - 1]['month'] ?? '').' · whole calendar months'">
                        @if (collect($moneyMonths)->contains(fn ($m) => $m['inMinor'] !== 0 || $m['outMinor'] !== 0))
                            <x-chart.bars :data="array_map(fn ($m) => ['label' => $m['label'], 'in' => $m['inMinor'], 'out' => $m['outMinor']], $moneyMonths)" summary="Gross debits and credits to payment accounts over six whole calendar months." />
                        @else
                            <p class="text-sm text-content-muted">No money movements recorded in these months.</p>
                        @endif
                        <p class="mt-2 text-xs text-content-muted">Gross money in and out, including transfers between accounts. This is separate from your cash balance.</p>
                    </x-dashboard.card>
                @endif
            </div>
        </section>
    @endif

    @if ($shows['stock'] || $shows['money'] || $shows['owed'] || $shows['suppliers'] || $shows['sales'])
        <section aria-labelledby="summaries-heading">
            <h2 id="summaries-heading" class="mb-2 text-base font-semibold text-content">Inventory and account summaries</h2>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @if ($stock !== null)
                    <x-dashboard.card title="Inventory valuation" icon="stock" href="/inventory" period="Current stock · all recorded dates">
                        <x-dashboard.figure :value="$stock['totalStockValue']" hint="At weighted average cost" />
                        <dl class="mt-3 grid grid-cols-3 gap-2 text-sm">
                            <div><dt class="text-content-muted">Stock items</dt><dd class="tabular">{{ $stock['trackedCount'] }}</dd></div>
                            <div><dt class="text-content-muted">Low stock</dt><dd class="tabular">{{ $stock['lowStockCount'] }}</dd></div>
                            <div><dt class="text-content-muted">Out of stock</dt><dd class="tabular">{{ $stock['outOfStockCount'] }}</dd></div>
                        </dl>
                        @if ($stock['trackedCount'] === 0)
                            <p class="mt-2 text-sm text-content-muted">No stock-tracked stock items yet.</p>
                        @endif
                        @if ($shows['sales'])
                            <div class="mt-3 rounded-lg border border-line bg-surface-sunken p-3">
                                <h4 class="text-sm font-semibold">Top selling stock item</h4>
                                <p class="mb-2 mt-1 text-xs text-content-muted">{{ $dates($salesPeriod) }}</p>
                                @include('livewire.dashboard-top-product')
                            </div>
                        @endif
                        @if ($expiry !== null && ($expiry['expiredCount'] > 0 || $expiry['expiringSoonCount'] > 0))
                            <p class="mt-3 border-t border-line pt-2 text-sm text-warning">{{ $expiry['expiredCount'] }} stock items with expired stock; {{ $expiry['expiringSoonCount'] }} expiring soon. Review batch dates in Inventory.</p>
                        @endif
                    </x-dashboard.card>
                @endif
                @if ($shows['owed'] || $shows['suppliers'])
                    <x-dashboard.card title="Receivables & payables" icon="owed">
                        <div class="space-y-3">
                            @if ($receivables !== null && $aged !== null)
                                <div class="rounded-lg border border-line bg-surface-sunken p-3">
                                    <a href="/customers?balance=owing" class="inline-block rounded text-sm font-medium text-accent hover:underline">Customer receivables</a>
                                    <p class="mb-2 mt-1 text-xs text-content-muted">Current ledger balance · invoice ageing at @date($today)</p>
                                    <x-dashboard.figure :value="$receivables" hint="Net customer ledger balance, including customer credits" />
                                    <dl class="mt-3 space-y-2 text-sm">
                                        <div class="flex flex-wrap justify-between gap-2"><dt>Overdue (all overdue buckets)</dt><dd class="tabular">@money($aged['overdue'])</dd></div>
                                        <div class="flex flex-wrap justify-between gap-2"><dt>Not yet due / due today</dt><dd class="tabular">@money($aged['notYetDue'])</dd></div>
                                        @unless ($aged['reconciles'])
                                            <div class="flex flex-wrap justify-between gap-2 text-warning"><dt>Outside invoice ageing</dt><dd class="tabular">@money($aged['unaged'])</dd></div>
                                        @endunless
                                    </dl>
                                    <p class="mt-3 text-xs text-content-muted">
                                        {{ $aged['reconciles'] ? 'Overdue + not yet due agrees with the customer ledger.' : 'Invoice buckets + amounts outside invoice ageing agree with the customer ledger. Review unallocated credits, opening balances and future-dated transactions.' }}
                                        Negative bucket amounts represent customer credits.
                                    </p>
                                </div>
                            @endif
                            @if ($payables !== null)
                                <div class="rounded-lg border border-line bg-surface-sunken p-3">
                                    <a href="/suppliers" class="inline-block rounded text-sm font-medium text-accent hover:underline">Supplier payables</a>
                                    <p class="mb-2 mt-1 text-xs text-content-muted">Current ledger balance · all recorded dates</p>
                                    <x-dashboard.figure :value="$payables" hint="Net amount owed to suppliers, including supplier credits" />
                                </div>
                            @endif
                        </div>
                    </x-dashboard.card>
                @endif
                @if ($shows['money'])
                    <x-dashboard.card title="Money accounts" icon="cashflow" href="/accounts" period="Current ledger balances · all recorded dates">
                        @if ($paymentAccounts === [])
                            <p class="text-sm text-content-muted">No active payment accounts. Open Accounts to review setup.</p>
                        @else
                            <ul class="divide-y divide-line">
                                @foreach ($paymentAccounts as $account)
                                    <li class="flex flex-wrap items-baseline justify-between gap-2 py-2">
                                        <a href="{{ Dashboard::accountHref($account['id']) }}" class="inline-block rounded text-sm font-medium text-accent hover:underline">{{ $account['name'] }}</a>
                                        <span class="tabular {{ $account['balance'] < 0 ? 'text-danger' : 'text-content' }}">@money($account['balance'])</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-dashboard.card>
                @endif
                @if ($shows['sales'] && ! $shows['stock'])
                    <x-dashboard.card title="Top selling stock item" icon="products" :period="$dates($salesPeriod)">
                        @include('livewire.dashboard-top-product')
                    </x-dashboard.card>
                @endif
            </div>
        </section>
    @endif

    @if ($booksCheck !== null || $shows['reconciliation'])
        <section aria-labelledby="books-heading" class="rounded-xl border border-line bg-surface-raised p-4">
            <h2 id="books-heading" class="mb-2 text-base font-semibold text-content">Books Check and reconciliation</h2>
            <div class="grid gap-3 md:grid-cols-2">
                @if ($booksCheck !== null)
                    <x-dashboard.card title="Books Check" embedded icon="books" href="/accounting" period="All recorded journal entries">
                        <dl class="space-y-2 text-sm">
                            @foreach ($checks as $check)
                                <div class="flex justify-between gap-3">
                                    <dt class="text-content-muted">{{ $check['label'] }}</dt>
                                    <dd class="shrink-0 font-medium {{ $check['passed'] ? 'text-success' : 'text-danger' }}"><x-badge :tone="$check['passed'] ? 'success' : 'danger'">{{ $check['passed'] ? 'Passed' : 'Needs review' }}</x-badge></dd>
                                </div>
                            @endforeach
                            <div class="flex flex-wrap justify-between gap-2 border-t border-line pt-2"><dt>Total debits</dt><dd class="tabular">@money($booksCheck['totalDebit'])</dd></div>
                            <div class="flex flex-wrap justify-between gap-2"><dt>Total credits</dt><dd class="tabular">@money($booksCheck['totalCredit'])</dd></div>
                            <div class="flex flex-wrap justify-between gap-2"><dt>Difference</dt><dd class="tabular">@money($booksCheck['difference'])</dd></div>
                        </dl>
                        <p class="mt-3 text-xs text-content-muted">{{ $entryCount }} journal entries checked. Matching debits and credits does not confirm that every transaction is correct. Review classifications, supporting documents and reconciliation.</p>
                    </x-dashboard.card>
                @endif
                @if ($shows['reconciliation'])
                    <x-dashboard.card title="Account reconciliation" embedded icon="check" href="/reconciliation" period="Recorded counts · each account checked separately">
                        @if ($reconciliation === [])
                            <p class="text-sm text-content-muted">No active accounts available to reconcile.</p>
                        @else
                            <ul class="divide-y divide-line">
                                @foreach ($reconciliation as $account)
                                    @php($open = $account['unresolvedDifference'] !== 0)
                                    <li class="py-2">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-sm font-medium text-content">{{ $account['accountName'] }}</p>
                                            <x-badge :tone="$open ? 'warning' : 'neutral'">{{ $open ? 'Needs review' : ($account['lastCountedOn'] === null ? 'Not verified' : 'Count recorded') }}</x-badge>
                                        </div>
                                        <p class="mt-1 text-xs text-content-muted">{{ $account['lastCountedOn'] === null ? 'No count recorded; reconciliation not verified.' : 'Last counted '.Format::date($account['lastCountedOn']).'.' }}</p>
                                        <p class="mt-1 text-sm {{ $open ? 'text-warning' : 'text-content-muted' }}">
                                            {{ $open ? Format::money($account['unresolvedDifference']).' unresolved difference' : ($account['lastCountedOn'] === null ? 'Count this account to establish a comparison.' : 'No unresolved recorded difference.') }}
                                        </p>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-dashboard.card>
                @endif
            </div>
        </section>
    @endif
</div>
