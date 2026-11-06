<div class="w-full">
    <x-page-header title="Reports" description="Every figure here is calculated from your actual records, not stored anywhere." />

    @unless ($sheet['balances'])
        <x-alert tone="danger" title="The balance sheet does not balance" class="mb-6">
            Assets @money($sheet['totalAssets']) do not equal liabilities plus equity @money($sheet['totalLiabilitiesAndEquity']). Please report this before relying on these reports.
        </x-alert>
    @endunless

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat icon="sales" label="Sales this month" :value="\App\View\Format::money($pl['netSales'])" />
        <x-stat icon="profit" label="Profit this month" :value="\App\View\Format::money($pl['netProfit'])" :tone="$pl['netProfit'] < 0 ? 'danger' : 'success'" hint="After all costs" />
        <x-stat icon="inventory" label="Stock value" :value="\App\View\Format::money($sheet['inventory'])" />
        <x-stat icon="books" label="Business worth" :value="\App\View\Format::money($sheet['totalEquity'])" hint="Assets less what you owe" />
    </div>

    <div class="space-y-6">
        @foreach ($groups as $section)
            <section>
                <h2 class="mb-3 text-sm font-semibold text-content">{{ $section['group'] }}</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach ($section['items'] as $report)
                        <a href="{{ $report['href'] }}" class="block">
                            <x-card class="h-full transition-colors hover:bg-surface-sunken">
                                <p class="font-medium text-accent">{{ $report['title'] }}</p>
                                <p class="mt-1 text-sm text-content-muted">{{ $report['description'] }}</p>
                            </x-card>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    <p class="mt-6 text-xs text-content-subtle">Every report can be filtered by date, downloaded as a spreadsheet, and printed.</p>
</div>
