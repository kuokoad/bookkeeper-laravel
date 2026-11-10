{{--
    The dashboard under the Modern look (design-shotgun 2026-11-09, "Owner homepage"):
    what needs the owner first, then the four headline figures, the daily charts
    and the summaries. Same figures, same permission gates and the same
    $attention list as dashboard.blade.php, which includes this; only the layout differs.
--}}
@use('App\Domain\Dashboard', 'Windows')
@use('App\Livewire\Dashboard')
@use('App\View\Format')
@php
    $passed = count(array_filter($checks, fn (array $c): bool => $c['passed']));
    $tabs = array_filter([
        'sales' => $shows['sales'] ? 'Daily sales' : null,
        'spend' => $shows['spending'] ? 'Daily expenses' : null,
        'money' => $shows['money'] ? 'Cash movements' : null,
    ]);
@endphp
{{-- Not .stitch-dashboard: that class shrinks the old layout's type to 13/11px; this one keeps 14px body text. --}}
<div class="modern-dashboard w-full space-y-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-content">Dashboard</h1>
            <p class="mt-1 text-sm text-content-muted">{{ $user->display_name }} · @date($today)@unless ($nothingToShow) · {{ $attention === [] ? 'nothing needs you' : count($attention).' '.(count($attention) === 1 ? 'thing needs' : 'things need').' you' }}@endunless</p>
        </div>
        <x-create-menu />
    </header>

    @if ($nothingToShow)
        <x-alert tone="info" title="No dashboard summaries available">Use the menu to access your work. Ask the owner if you need access to financial summaries.</x-alert>
    @else
        <section aria-labelledby="attention-heading" class="needs-attention flex flex-col gap-3 border border-line bg-surface-raised p-3 lg:flex-row lg:items-stretch" style="border-radius: var(--card-radius)">
            <h2 id="attention-heading" class="modern-label flex shrink-0 items-center gap-2 px-2 lg:w-24">
                <x-icon name="warning" class="h-4 w-4" /> Needs attention
            </h2>
            @if ($attention === [])
                <p class="flex-1 self-center px-2 text-sm text-content-muted">No issues found in the dashboard checks available to your account.</p>
            @else
                <ul class="grid flex-1 gap-3 md:grid-cols-2">
                    @foreach ($attention as $item)
                        @php($warn = $item['tone'] === 'warning')
                        <li @class(['flex min-w-0 items-center gap-3 rounded-lg px-3 py-2.5', 'bg-warning-soft' => $warn, 'bg-danger-soft' => ! $warn, 'negative-account-alert' => $item['negativeBalance'] ?? false])>
                            <span @class(['flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-bold text-white', 'bg-warning' => $warn, 'bg-danger' => ! $warn]) aria-hidden="true">{{ $warn ? '▲' : '!' }}</span>
                            <div class="min-w-0">
                                <a href="{{ $item['href'] }}" class="rounded text-sm font-semibold text-content hover:text-accent hover:underline focus-visible:outline-2 focus-visible:outline-accent">{{ $item['title'] }}</a>
                                <p class="attention-note text-xs text-content-muted">{{ $item['note'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($booksCheck !== null)
                <a href="/accounting" @class(['flex shrink-0 items-center gap-3 rounded-lg px-3 py-2.5 focus-visible:outline-2 focus-visible:outline-accent', 'bg-success-soft' => $passed === count($checks), 'bg-warning-soft' => $passed !== count($checks)])>
                    <span @class(['flex h-7 w-7 items-center justify-center rounded-full text-sm font-bold text-white', 'bg-success' => $passed === count($checks), 'bg-warning' => $passed !== count($checks)]) aria-hidden="true">{{ $passed === count($checks) ? '✓' : '!' }}</span>
                    <span><span class="block text-sm font-semibold text-content">Books Check</span><span class="block text-xs text-content-muted">{{ $passed }} / {{ count($checks) }} checks passed</span></span>
                </a>
            @endif
        </section>
    @endif

    <section aria-label="Primary financial figures" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <h2 class="sr-only">Primary financial figures</h2>
        @if ($shows['money'])
            <section class="min-w-0 border border-line bg-surface-raised p-4" style="border-radius: var(--card-radius)">
                <h3 class="modern-label"><a href="/accounts" class="rounded hover:text-accent focus-visible:outline-2 focus-visible:outline-accent">Cash &amp; Bank Balance</a></h3>
                <p class="mt-2 text-[1.7rem]"><x-dashboard.amount :value="$cashHeld" /></p>
                <p class="mt-1 text-xs text-content-muted">Current ledger balance · all active payment accounts</p>
                @if ($paymentAccounts !== [])
                    <ul class="mt-3 space-y-1 border-t border-line pt-2 text-sm">
                        @foreach ($paymentAccounts as $account)
                            <li class="flex justify-between gap-2"><a href="{{ Dashboard::accountHref($account['id']) }}" class="truncate rounded text-content-muted hover:text-accent hover:underline">{{ $account['name'] }}</a><span class="tabular whitespace-nowrap {{ $account['balance'] < 0 ? 'text-danger' : 'text-content' }}">@money($account['balance'])</span></li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-3 text-sm text-content-muted">No active payment accounts. Open Accounts to review setup.</p>
                @endif
            </section>
        @endif
        @if ($rangeSales !== null)
            <section class="min-w-0 border border-line bg-surface-raised p-4" style="border-radius: var(--card-radius)">
                <h3 class="modern-label"><a href="{{ $salesHref }}" class="rounded hover:text-accent focus-visible:outline-2 focus-visible:outline-accent">Sales</a></h3>
                @include('livewire.dashboard-period', ['label' => 'Sales', 'param' => 'sales'])
                <p class="text-[1.7rem]"><x-dashboard.amount :value="$rangeSales['total']" /></p>
                <p class="mt-1 text-xs text-content-muted">{{ $rangeSales['count'] }} posted sale{{ $rangeSales['count'] === 1 ? '' : 's' }} · transaction total</p>
                <p class="mt-2 border-t border-line pt-2 text-sm text-content-muted">Today (@date($today)): <span class="tabular font-semibold text-content">@money($todaySales['total'])</span></p>
            </section>
        @endif
        @if ($spendTotal !== null)
            <section class="min-w-0 border border-line bg-surface-raised p-4" style="border-radius: var(--card-radius)">
                <h3 class="modern-label"><a href="{{ $reportHref('/expenses', $spendPeriod) }}" class="rounded hover:text-accent focus-visible:outline-2 focus-visible:outline-accent">Expenses</a></h3>
                @include('livewire.dashboard-period', ['label' => 'Expenses', 'param' => 'spend'])
                <p class="text-[1.7rem]"><x-dashboard.amount :value="$spendTotal" /></p>
                <p class="mt-1 text-xs text-content-muted">Recorded expense transactions; excludes other ledger adjustments</p>
            </section>
        @endif
        @if ($pl !== null)
            <section class="min-w-0 border border-line bg-surface-raised p-4" style="border-radius: var(--card-radius)">
                <h3 class="modern-label"><a href="{{ $reportHref('/reports/profit-and-loss', $profitPeriod) }}" class="rounded hover:text-accent focus-visible:outline-2 focus-visible:outline-accent">Net Profit</a> · month to date</h3>
                <p @class(['mt-2 text-[1.7rem]', 'text-success' => $pl['netProfit'] > 0])><x-dashboard.amount :value="$pl['netProfit']" /></p>
                <p class="mt-1 text-xs text-content-muted">Net sales − cost of goods + other income − all ledger expenses</p>
            </section>
        @endif
    </section>

    @if ($tabs !== [] || $categories !== [])
        <section aria-labelledby="trends-heading" class="grid gap-4 lg:grid-cols-3">
            <h2 id="trends-heading" class="sr-only">Financial trends</h2>
            @if ($tabs !== [])
                <div class="min-w-0 border border-line bg-surface-raised p-4 {{ $categories !== [] ? 'lg:col-span-2' : 'lg:col-span-3' }}" style="border-radius: var(--card-radius)" x-data="{ tab: @js(array_key_first($tabs)) }">
                    <div role="tablist" aria-label="Charts" class="mb-3 flex gap-5 border-b border-line">
                        @foreach ($tabs as $key => $label)
                            <button type="button" role="tab" id="tab-{{ $key }}" aria-controls="panel-{{ $key }}" x-on:click="tab = '{{ $key }}'" x-bind:aria-selected="tab === '{{ $key }}'" @if ($loop->first) aria-selected="true" @endif
                                class="-mb-px border-b-2 py-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-accent"
                                x-bind:class="tab === '{{ $key }}' ? 'border-accent text-accent' : 'border-transparent text-content-muted hover:text-content'">{{ $label }}</button>
                        @endforeach
                    </div>
                    @isset($tabs['sales'])
                        <div role="tabpanel" id="panel-sales" aria-labelledby="tab-sales" x-show="tab === 'sales'">
                            <p class="mb-2 text-xs text-content-muted">{{ $dates($salesPeriod) }}</p>
                            @if ($salesByDay !== [])
                                <x-chart.trend :data="Windows::dashboardDailySeries($salesByDay, $salesPeriod)" :summary="'Daily sales: '.$dates($salesPeriod).'. Total '.Format::money($rangeSales['total']).'.'" />
                            @else
                                <p class="text-sm text-content-muted">No sales recorded in this period.</p>
                            @endif
                        </div>
                    @endisset
                    @isset($tabs['spend'])
                        <div role="tabpanel" id="panel-spend" aria-labelledby="tab-spend" x-show="tab === 'spend'" @unless (array_key_first($tabs) === 'spend') x-cloak @endunless>
                            <p class="mb-2 text-xs text-content-muted">{{ $dates($spendPeriod) }}</p>
                            @if ($expenseDays !== [])
                                <x-chart.trend :data="Windows::dashboardDailySeries($expenseDays, $spendPeriod)" :summary="'Recorded daily expenses: '.$dates($spendPeriod).'. Total '.Format::money($spendTotal).'.'" />
                            @else
                                <p class="text-sm text-content-muted">No expense transactions recorded in this period.</p>
                            @endif
                        </div>
                    @endisset
                    @isset($tabs['money'])
                        <div role="tabpanel" id="panel-money" aria-labelledby="tab-money" x-show="tab === 'money'" @unless (array_key_first($tabs) === 'money') x-cloak @endunless>
                            <p class="mb-2 text-xs text-content-muted">{{ ($moneyMonths[0]['month'] ?? '').' through '.($moneyMonths[count($moneyMonths) - 1]['month'] ?? '') }} · whole calendar months</p>
                            @if (collect($moneyMonths)->contains(fn ($m) => $m['inMinor'] !== 0 || $m['outMinor'] !== 0))
                                <x-chart.bars :data="array_map(fn ($m) => ['label' => $m['label'], 'in' => $m['inMinor'], 'out' => $m['outMinor']], $moneyMonths)" summary="Gross debits and credits to payment accounts over six whole calendar months." />
                            @else
                                <p class="text-sm text-content-muted">No money movements recorded in these months.</p>
                            @endif
                            <p class="mt-2 text-xs text-content-muted">Gross money in and out, including transfers between accounts. This is separate from your cash balance.</p>
                        </div>
                    @endisset
                </div>
            @endif
            @if ($categories !== [])
                @php($biggest = max(1, ...array_map(fn (array $c): int => abs($c['total']), $categories)))
                <section class="min-w-0 border border-line bg-surface-raised p-4" style="border-radius: var(--card-radius)">
                    <h3 class="modern-label">Expenses by category</h3>
                    <p class="mb-2 mt-1 text-xs text-content-muted">{{ $dates($spendPeriod) }}</p>
                    <ul class="space-y-2.5">
                        @foreach ($categories as $category)
                            <li>
                                <div class="flex justify-between gap-3 text-sm"><span class="truncate text-content">{{ $category['categoryName'] }}</span><span class="tabular whitespace-nowrap font-semibold">@money($category['total'])</span></div>
                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-surface-sunken" aria-hidden="true"><div class="h-full rounded-full bg-[var(--chart-5)]" style="width: {{ round(max(0, $category['total']) / $biggest * 100, 1) }}%"></div></div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </section>
    @endif

    @if ($shows['owed'] || $shows['suppliers'] || $shows['stock'] || $shows['sales'] || $booksCheck !== null || $shows['reconciliation'])
        <section aria-labelledby="summaries-heading" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <h2 id="summaries-heading" class="sr-only">Inventory and account summaries</h2>
            @if ($shows['owed'] || $shows['suppliers'])
                <section class="min-w-0 border border-line bg-surface-raised p-4" style="border-radius: var(--card-radius)">
                    @if ($receivables !== null && $aged !== null)
                        <h3 class="modern-label"><a href="/customers?balance=owing" class="rounded hover:text-accent focus-visible:outline-2 focus-visible:outline-accent">Customer receivables</a></h3>
                        <p class="mt-2 text-2xl"><x-dashboard.amount :value="$receivables" /></p>
                        <p class="mt-1 text-xs text-content-muted">Net customer ledger balance, including customer credits · ageing at @date($today)</p>
                        @if ($receivables > 0 && $aged['overdue'] >= 0 && $aged['notYetDue'] >= 0)
                            @php($overdueShare = round($aged['overdue'] / $receivables * 100, 1))
                            <div class="mt-3 flex h-2 overflow-hidden rounded-full bg-surface-sunken" aria-hidden="true"><div class="bg-danger" style="width: {{ min(100, $overdueShare) }}%"></div><div class="bg-accent" style="width: {{ max(0, min(100, round($aged['notYetDue'] / $receivables * 100, 1))) }}%"></div></div>
                        @endif
                        <dl class="mt-2 text-sm">
                            <div class="flex justify-between gap-2 border-b border-line py-1.5"><dt>Overdue (all overdue buckets)</dt><dd class="tabular whitespace-nowrap {{ $aged['overdue'] > 0 ? 'text-danger font-semibold' : '' }}">@money($aged['overdue'])</dd></div>
                            <div class="flex justify-between gap-2 border-b border-line py-1.5"><dt>Not yet due / due today</dt><dd class="tabular whitespace-nowrap">@money($aged['notYetDue'])</dd></div>
                            @unless ($aged['reconciles'])
                                <div class="flex justify-between gap-2 border-b border-line py-1.5 text-warning"><dt>Outside invoice ageing</dt><dd class="tabular whitespace-nowrap">@money($aged['unaged'])</dd></div>
                            @endunless
                        </dl>
                        <p class="mt-2 text-xs text-content-muted">{{ $aged['reconciles'] ? 'Overdue + not yet due agrees with the customer ledger.' : 'Invoice buckets + amounts outside invoice ageing agree with the customer ledger.' }} Negative bucket amounts represent customer credits.</p>
                    @endif
                    @if ($payables !== null)
                        <h3 @class(['modern-label', 'mt-4 border-t border-line pt-3' => $receivables !== null])><a href="/suppliers" class="rounded hover:text-accent focus-visible:outline-2 focus-visible:outline-accent">Supplier payables</a></h3>
                        <p class="mt-2 text-2xl"><x-dashboard.amount :value="$payables" /></p>
                        <p class="mt-1 text-xs text-content-muted">Net amount owed to suppliers, including supplier credits</p>
                    @endif
                </section>
            @endif
            @if ($stock !== null || $shows['sales'])
                <section class="min-w-0 border border-line bg-surface-raised p-4" style="border-radius: var(--card-radius)">
                    @if ($stock !== null)
                        <h3 class="modern-label"><a href="/inventory" class="rounded hover:text-accent focus-visible:outline-2 focus-visible:outline-accent">Inventory valuation</a></h3>
                        <p class="mt-2 text-2xl"><x-dashboard.amount :value="$stock['totalStockValue']" /></p>
                        <p class="mt-1 text-xs text-content-muted">At weighted average cost · current stock</p>
                        <dl class="mt-2 text-sm">
                            <div class="flex justify-between gap-2 border-b border-line py-1.5"><dt>Stock items</dt><dd class="tabular">{{ $stock['trackedCount'] }}</dd></div>
                            <div class="flex justify-between gap-2 border-b border-line py-1.5"><dt>Low stock</dt><dd class="tabular {{ $stock['lowStockCount'] > 0 ? 'font-semibold text-warning' : '' }}">{{ $stock['lowStockCount'] }}</dd></div>
                            <div class="flex justify-between gap-2 border-b border-line py-1.5"><dt>Out of stock</dt><dd class="tabular {{ $stock['outOfStockCount'] > 0 ? 'font-semibold text-danger' : '' }}">{{ $stock['outOfStockCount'] }}</dd></div>
                        </dl>
                        @if ($stock['trackedCount'] === 0)
                            <p class="mt-2 text-sm text-content-muted">No stock-tracked stock items yet.</p>
                        @endif
                        @if ($expiry !== null && ($expiry['expiredCount'] > 0 || $expiry['expiringSoonCount'] > 0))
                            <p class="mt-2 text-sm text-warning">{{ $expiry['expiredCount'] }} stock items with expired stock; {{ $expiry['expiringSoonCount'] }} expiring soon. Review batch dates in Inventory.</p>
                        @endif
                    @endif
                    @if ($shows['sales'])
                        <h4 @class(['modern-label', 'mt-4' => $stock !== null])>Top selling stock item</h4>
                        <p class="mb-1 mt-1 text-xs text-content-muted">{{ $dates($salesPeriod) }}</p>
                        @include('livewire.dashboard-top-product')
                    @endif
                </section>
            @endif
            @if ($booksCheck !== null || $shows['reconciliation'])
                <section class="min-w-0 border border-line bg-surface-raised p-4" style="border-radius: var(--card-radius)">
                    @if ($booksCheck !== null)
                        <h3 class="modern-label"><a href="/accounting" class="rounded hover:text-accent focus-visible:outline-2 focus-visible:outline-accent">Books Check</a></h3>
                        <dl class="mt-2 text-sm">
                            @foreach ($checks as $check)
                                <div class="flex items-center justify-between gap-3 border-b border-line py-1.5"><dt class="text-content-muted">{{ $check['label'] }}</dt><dd><x-badge :tone="$check['passed'] ? 'success' : 'danger'">{{ $check['passed'] ? 'Passed' : 'Needs review' }}</x-badge></dd></div>
                            @endforeach
                            <div class="flex justify-between gap-2 border-b border-line py-1.5"><dt>Total debits</dt><dd class="tabular whitespace-nowrap">@money($booksCheck['totalDebit'])</dd></div>
                            <div class="flex justify-between gap-2 border-b border-line py-1.5"><dt>Total credits</dt><dd class="tabular whitespace-nowrap">@money($booksCheck['totalCredit'])</dd></div>
                            <div class="flex justify-between gap-2 py-1.5"><dt>Difference</dt><dd class="tabular whitespace-nowrap font-semibold">@money($booksCheck['difference'])</dd></div>
                        </dl>
                        <p class="mt-1 text-xs text-content-muted">{{ $entryCount }} journal entries checked. Matching debits and credits does not confirm that every transaction is correct.</p>
                    @endif
                    @if ($shows['reconciliation'])
                        <h3 @class(['modern-label', 'mt-4 border-t border-line pt-3' => $booksCheck !== null])><a href="/reconciliation" class="rounded hover:text-accent focus-visible:outline-2 focus-visible:outline-accent">Account reconciliation</a></h3>
                        @if ($reconciliation === [])
                            <p class="mt-2 text-sm text-content-muted">No active accounts available to reconcile.</p>
                        @else
                            <ul class="mt-1 text-sm">
                                @foreach ($reconciliation as $account)
                                    @php($open = $account['unresolvedDifference'] !== 0)
                                    <li class="border-b border-line py-1.5 last:border-0">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-content">{{ $account['accountName'] }}</span>
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
                    @endif
                </section>
            @endif
        </section>
    @endif

    @if ($shows['money'])
        @include('livewire.dashboard-activity')
    @endif
</div>
