{{--
    The dashboard under the Fable 5 look: the money the shop holds leads, on the
    one dark panel of the page; then what needs the owner, the period figures,
    the daily charts and the summaries. Same figures, same permission gates and
    the same $attention list as dashboard.blade.php, which includes this; only
    the layout differs. Bar widths are pixel positions, never a figure.
--}}
@use('App\Domain\Dashboard', 'Windows')
@use('App\Livewire\Dashboard')
@use('App\View\Format')
@php
    $passed = count(array_filter($checks, fn (array $c): bool => $c['passed']));
    $topBalance = max([1, ...array_column($paymentAccounts, 'balance')]);
    $link = 'rounded hover:text-accent hover:underline focus-visible:outline-2 focus-visible:outline-accent';
@endphp
<div class="fable-dashboard w-full space-y-5">
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-content">Dashboard</h1>
            <p class="mt-1 text-sm text-content-muted">{{ $user->display_name }}, this is the shop on @date($today).</p>
        </div>
        <x-create-menu />
    </header>

    @if ($nothingToShow)
        <x-alert tone="info" title="No dashboard summaries available">Use the menu to access your work. Ask the owner if you need access to financial summaries.</x-alert>
    @endif

    @if ($shows['money'])
        <section aria-labelledby="held-heading" class="fable-hero grid gap-x-10 gap-y-6 p-6 sm:p-8 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:items-center">
            <div class="min-w-0">
                <h2 id="held-heading" class="fable-label"><a href="/accounts" class="{{ $link }}">Cash &amp; Bank Balance</a></h2>
                <p class="fable-hero-figure mt-2"><x-dashboard.amount :value="$cashHeld" /></p>
                <p class="mt-3 max-w-[38ch] text-sm text-content-muted">What the books say you hold right now, across every active payment account.</p>
            </div>
            @if ($paymentAccounts === [])
                <p class="text-sm text-content-muted">No active payment accounts. Open Accounts to review setup.</p>
            @else
                <ul class="min-w-0 space-y-3.5">
                    @foreach ($paymentAccounts as $account)
                        <li>
                            <div class="flex items-baseline justify-between gap-3 text-sm">
                                <a href="{{ Dashboard::accountHref($account['id']) }}" class="truncate font-medium text-content {{ $link }}">{{ $account['name'] }}</a>
                                <span class="tabular whitespace-nowrap font-semibold {{ $account['balance'] < 0 ? 'text-danger' : 'text-content' }}">@money($account['balance'])</span>
                            </div>
                            <div class="fable-track mt-1.5" aria-hidden="true"><div style="width: {{ round(max(0, $account['balance']) / $topBalance * 100, 1) }}%"></div></div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    @unless ($nothingToShow)
        <section aria-labelledby="attention-heading" class="needs-attention fable-card">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="attention-heading" class="flex items-center gap-2 text-base font-semibold text-content">
                    Needs attention
                    @if ($attention !== [])
                        <span class="tabular rounded-full bg-danger-soft px-2 py-0.5 text-xs font-semibold text-danger">{{ count($attention) }}</span>
                    @endif
                </h2>
                @if ($booksCheck !== null)
                    <a href="/accounting" class="rounded-full focus-visible:outline-2 focus-visible:outline-accent"><x-badge :tone="$passed === count($checks) ? 'success' : 'warning'">Books Check: {{ $passed }} of {{ count($checks) }} passed</x-badge></a>
                @endif
            </div>
            @if ($attention === [])
                <p class="mt-2 text-sm text-content-muted">No issues found in the dashboard checks available to your account.</p>
            @else
                <ul class="mt-4 grid gap-x-8 gap-y-4 md:grid-cols-2">
                    @foreach ($attention as $item)
                        <li @class(['min-w-0 border-l-4 pl-3', 'border-warning' => $item['tone'] === 'warning', 'border-danger' => $item['tone'] !== 'warning', 'negative-account-alert' => $item['negativeBalance'] ?? false])>
                            <a href="{{ $item['href'] }}" class="text-sm font-semibold text-content {{ $link }}">{{ $item['title'] }}</a>
                            <p class="attention-note mt-0.5 text-xs text-content-muted">{{ $item['note'] }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endunless

    @if ($rangeSales !== null || $spendTotal !== null || $pl !== null)
        <section aria-label="Primary financial figures" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <h2 class="sr-only">Primary financial figures</h2>
            @if ($rangeSales !== null)
                <section class="fable-card">
                    <div class="flex flex-wrap items-start justify-between gap-x-3">
                        <h3 class="fable-label pt-2"><a href="{{ $salesHref }}" class="{{ $link }}">Sales</a></h3>
                        @include('livewire.dashboard-period', ['label' => 'Sales', 'param' => 'sales'])
                    </div>
                    <p class="text-[1.75rem] leading-tight"><x-dashboard.amount :value="$rangeSales['total']" /></p>
                    <p class="mt-1 text-xs text-content-muted">{{ $rangeSales['count'] }} sale{{ $rangeSales['count'] === 1 ? '' : 's' }} rung up, transaction total</p>
                    <p class="mt-3 border-t border-line pt-3 text-sm text-content-muted">Today: <span class="tabular font-semibold text-content">@money($todaySales['total'])</span></p>
                </section>
            @endif
            @if ($spendTotal !== null)
                <section class="fable-card">
                    <div class="flex flex-wrap items-start justify-between gap-x-3">
                        <h3 class="fable-label pt-2"><a href="{{ $reportHref('/expenses', $spendPeriod) }}" class="{{ $link }}">Expenses</a></h3>
                        @include('livewire.dashboard-period', ['label' => 'Expenses', 'param' => 'spend'])
                    </div>
                    <p class="text-[1.75rem] leading-tight"><x-dashboard.amount :value="$spendTotal" /></p>
                    <p class="mt-1 text-xs text-content-muted">Recorded expense transactions; excludes other ledger adjustments</p>
                </section>
            @endif
            @if ($pl !== null)
                <section class="fable-card">
                    <div class="flex flex-wrap items-start justify-between gap-x-3">
                        <h3 class="fable-label pt-2"><a href="{{ $reportHref('/reports/profit-and-loss', $profitPeriod) }}" class="{{ $link }}">Net Profit</a></h3>
                        <p class="mb-3 px-3 py-2 text-xs text-content-muted">Month to date</p>
                    </div>
                    <p @class(['text-[1.75rem] leading-tight', 'text-success' => $pl['netProfit'] > 0])><x-dashboard.amount :value="$pl['netProfit']" /></p>
                    <p class="mt-1 text-xs text-content-muted">Net sales − cost of goods + other income − all ledger expenses</p>
                </section>
            @endif
        </section>
    @endif

    @if ($shows['sales'] || $shows['spending'])
        <section aria-labelledby="trends-heading" class="grid gap-4 lg:grid-cols-2">
            <h2 id="trends-heading" class="sr-only">Financial trends</h2>
            @if ($shows['sales'])
                <section class="fable-card">
                    <h3 class="text-base font-semibold text-content"><a href="{{ $salesHref }}" class="{{ $link }}">Daily sales</a></h3>
                    <p class="mb-3 mt-0.5 text-xs text-content-muted">{{ $dates($salesPeriod) }}</p>
                    @if ($salesByDay !== [])
                        <x-chart.trend :data="Windows::dashboardDailySeries($salesByDay, $salesPeriod)" :summary="'Daily sales: '.$dates($salesPeriod).'. Total '.Format::money($rangeSales['total']).'.'" />
                    @else
                        <p class="text-sm text-content-muted">No sales recorded in this period.</p>
                    @endif
                    <div class="mt-4 border-t border-line pt-3">
                        <h4 class="fable-label mb-1">Top selling stock item</h4>
                        @include('livewire.dashboard-top-product')
                    </div>
                </section>
            @endif
            @if ($shows['spending'])
                <section class="fable-card">
                    <h3 class="text-base font-semibold text-content"><a href="{{ $reportHref('/expenses', $spendPeriod) }}" class="{{ $link }}">Daily expenses</a></h3>
                    <p class="mb-3 mt-0.5 text-xs text-content-muted">{{ $dates($spendPeriod) }}</p>
                    @if ($expenseDays !== [])
                        <x-chart.trend :data="Windows::dashboardDailySeries($expenseDays, $spendPeriod)" :summary="'Recorded daily expenses: '.$dates($spendPeriod).'. Total '.Format::money($spendTotal).'.'" />
                    @else
                        <p class="text-sm text-content-muted">No expense transactions recorded in this period.</p>
                    @endif
                    @if ($categories !== [])
                        @php($biggest = max(1, ...array_map(fn (array $c): int => abs($c['total']), $categories)))
                        <div class="mt-4 border-t border-line pt-3">
                            <h4 class="fable-label mb-2">Where it went</h4>
                            <ul class="space-y-2.5">
                                @foreach ($categories as $category)
                                    <li>
                                        <div class="flex justify-between gap-3 text-sm"><span class="truncate text-content">{{ $category['categoryName'] }}</span><span class="tabular whitespace-nowrap font-semibold">@money($category['total'])</span></div>
                                        <div class="fable-track mt-1" aria-hidden="true"><div style="width: {{ round(max(0, $category['total']) / $biggest * 100, 1) }}%"></div></div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </section>
            @endif
        </section>
    @endif

    @if ($shows['money'] || $shows['owed'] || $shows['suppliers'] || $stock !== null)
        <section aria-labelledby="summaries-heading" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <h2 id="summaries-heading" class="sr-only">Inventory and account summaries</h2>
            @if ($shows['owed'] || $shows['suppliers'])
                <section class="fable-card">
                    @if ($receivables !== null && $aged !== null)
                        <h3 class="fable-label"><a href="/customers?balance=owing" class="{{ $link }}">Customer receivables</a></h3>
                        <p class="mt-1 text-2xl"><x-dashboard.amount :value="$receivables" /></p>
                        <p class="mt-1 text-xs text-content-muted">Net customer ledger balance, including customer credits. Ageing at @date($today).</p>
                        @if ($receivables > 0 && $aged['overdue'] >= 0 && $aged['notYetDue'] >= 0)
                            <div class="mt-3 flex h-2 overflow-hidden rounded-full bg-surface-sunken" aria-hidden="true"><div class="bg-danger" style="width: {{ min(100, round($aged['overdue'] / $receivables * 100, 1)) }}%"></div><div class="bg-accent" style="width: {{ max(0, min(100, round($aged['notYetDue'] / $receivables * 100, 1))) }}%"></div></div>
                        @endif
                        <dl class="fable-rows mt-2 text-sm">
                            <div><dt>Overdue (all overdue buckets)</dt><dd class="tabular whitespace-nowrap {{ $aged['overdue'] > 0 ? 'font-semibold text-danger' : '' }}">@money($aged['overdue'])</dd></div>
                            <div><dt>Not yet due / due today</dt><dd class="tabular whitespace-nowrap">@money($aged['notYetDue'])</dd></div>
                            @unless ($aged['reconciles'])
                                <div class="text-warning"><dt>Outside invoice ageing</dt><dd class="tabular whitespace-nowrap">@money($aged['unaged'])</dd></div>
                            @endunless
                        </dl>
                        <p class="mt-2 text-xs text-content-muted">{{ $aged['reconciles'] ? 'Overdue + not yet due agrees with the customer ledger.' : 'Invoice buckets + amounts outside invoice ageing agree with the customer ledger.' }} Negative bucket amounts represent customer credits.</p>
                    @endif
                    @if ($payables !== null)
                        <h3 @class(['fable-label', 'mt-4 border-t border-line pt-4' => $receivables !== null])><a href="/suppliers" class="{{ $link }}">Supplier payables</a></h3>
                        <p class="mt-1 text-2xl"><x-dashboard.amount :value="$payables" /></p>
                        <p class="mt-1 text-xs text-content-muted">Net amount owed to suppliers, including supplier credits</p>
                    @endif
                </section>
            @endif
            @if ($stock !== null)
                <section class="fable-card">
                    <h3 class="fable-label"><a href="/inventory" class="{{ $link }}">Inventory valuation</a></h3>
                    <p class="mt-1 text-2xl"><x-dashboard.amount :value="$stock['totalStockValue']" /></p>
                    <p class="mt-1 text-xs text-content-muted">Current stock at weighted average cost</p>
                    <dl class="fable-rows mt-2 text-sm">
                        <div><dt>Stock items</dt><dd class="tabular">{{ $stock['trackedCount'] }}</dd></div>
                        <div><dt>Low stock</dt><dd class="tabular {{ $stock['lowStockCount'] > 0 ? 'font-semibold text-warning' : '' }}">{{ $stock['lowStockCount'] }}</dd></div>
                        <div><dt>Out of stock</dt><dd class="tabular {{ $stock['outOfStockCount'] > 0 ? 'font-semibold text-danger' : '' }}">{{ $stock['outOfStockCount'] }}</dd></div>
                    </dl>
                    @if ($stock['trackedCount'] === 0)
                        <p class="mt-2 text-sm text-content-muted">No stock-tracked stock items yet.</p>
                    @endif
                    @if ($expiry !== null && ($expiry['expiredCount'] > 0 || $expiry['expiringSoonCount'] > 0))
                        <p class="mt-2 text-sm text-warning">{{ $expiry['expiredCount'] }} stock items with expired stock; {{ $expiry['expiringSoonCount'] }} expiring soon. Review batch dates in Inventory.</p>
                    @endif
                </section>
            @endif
            @if ($shows['money'])
                <section class="fable-card">
                    <h3 class="fable-label"><a href="{{ $shows['profit'] ? '/reports/cash-flow' : '/accounts' }}" class="{{ $link }}">Cash movements</a></h3>
                    <p class="mb-3 mt-1 text-xs text-content-muted">{{ ($moneyMonths[0]['month'] ?? '').' through '.($moneyMonths[count($moneyMonths) - 1]['month'] ?? '') }}, whole calendar months</p>
                    @if (collect($moneyMonths)->contains(fn ($m) => $m['inMinor'] !== 0 || $m['outMinor'] !== 0))
                        <x-chart.bars :data="array_map(fn ($m) => ['label' => $m['label'], 'in' => $m['inMinor'], 'out' => $m['outMinor']], $moneyMonths)" summary="Gross debits and credits to payment accounts over six whole calendar months." />
                    @else
                        <p class="text-sm text-content-muted">No money movements recorded in these months.</p>
                    @endif
                    <p class="mt-2 text-xs text-content-muted">Gross money in and out, including transfers between accounts. This is separate from your cash balance.</p>
                </section>
            @endif
        </section>
    @endif

    @if ($booksCheck !== null || $shows['reconciliation'])
        <section aria-labelledby="books-heading" class="grid gap-4 md:grid-cols-2">
            <h2 id="books-heading" class="sr-only">Books Check and reconciliation</h2>
            @if ($booksCheck !== null)
                <section class="fable-card">
                    <h3 class="text-base font-semibold text-content"><a href="/accounting" class="{{ $link }}">Books Check</a></h3>
                    <dl class="fable-rows mt-2 text-sm">
                        @foreach ($checks as $check)
                            <div><dt class="text-content-muted">{{ $check['label'] }}</dt><dd><x-badge :tone="$check['passed'] ? 'success' : 'danger'">{{ $check['passed'] ? 'Passed' : 'Needs review' }}</x-badge></dd></div>
                        @endforeach
                        <div><dt>Total debits</dt><dd class="tabular whitespace-nowrap">@money($booksCheck['totalDebit'])</dd></div>
                        <div><dt>Total credits</dt><dd class="tabular whitespace-nowrap">@money($booksCheck['totalCredit'])</dd></div>
                        <div><dt>Difference</dt><dd class="tabular whitespace-nowrap font-semibold">@money($booksCheck['difference'])</dd></div>
                    </dl>
                    <p class="mt-2 text-xs text-content-muted">{{ $entryCount }} journal entries checked. Matching debits and credits does not confirm that every transaction is correct.</p>
                </section>
            @endif
            @if ($shows['reconciliation'])
                <section class="fable-card">
                    <h3 class="text-base font-semibold text-content"><a href="/reconciliation" class="{{ $link }}">Account reconciliation</a></h3>
                    @if ($reconciliation === [])
                        <p class="mt-2 text-sm text-content-muted">No active accounts available to reconcile.</p>
                    @else
                        <ul class="mt-2 divide-y divide-line text-sm">
                            @foreach ($reconciliation as $account)
                                @php($open = $account['unresolvedDifference'] !== 0)
                                <li class="py-2">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium text-content">{{ $account['accountName'] }}</span>
                                        <x-badge :tone="$open ? 'warning' : ($account['lastCountedOn'] === null ? 'neutral' : 'success')">{{ $open ? 'Needs review' : ($account['lastCountedOn'] === null ? 'Not verified' : 'Count recorded') }}</x-badge>
                                    </div>
                                    <p class="mt-0.5 text-xs {{ $open ? 'text-warning' : 'text-content-muted' }}">
                                        {{ $account['lastCountedOn'] === null ? 'No count recorded; reconciliation not verified.' : 'Last counted '.Format::date($account['lastCountedOn']).'.' }}
                                        {{ $open ? Format::money($account['unresolvedDifference']).' unresolved difference.' : '' }}
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif
        </section>
    @endif

    @if ($shows['money'])
        @include('livewire.dashboard-activity')
    @endif
</div>
