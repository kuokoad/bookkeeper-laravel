@php
    use App\View\Format;
    $currency = $settings->currency_code ?? 'GHS';
@endphp
<x-layouts::app title="Statement">
    <div class="mx-auto max-w-2xl">
        <div class="no-print mb-4 flex flex-wrap items-center justify-between gap-2">
            <x-button variant="secondary" size="sm" href="/customers/{{ $customer['id'] }}">Back to {{ $customer['name'] }}</x-button>
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label for="asAt" class="text-xs text-content-muted">As at</label>
                <x-date-input id="asAt" name="asAt" :value="$asAt" :max="$today" />
                <x-button variant="secondary" size="sm" type="submit">Show</x-button>
                <x-button size="sm" x-data x-on:click="window.print()">Print</x-button>
            </form>
        </div>

        @unless ($agrees)
            <x-alert tone="danger" title="This statement does not tie to the ledger" class="mb-4">
                The unpaid invoices come to {{ Format::money($rowsTotal, currencyCode: $currency) }}, but the
                account balance is {{ Format::money($balance, currencyCode: $currency) }}. Do not send this until it
                is resolved.
            </x-alert>
        @endunless

        <article class="rounded-xl border border-line bg-surface-raised p-6 text-sm">
            <header class="mb-6 flex items-start justify-between gap-4">
                <div class="min-w-0">
                    @if ($settings->has_logo)
                        <img src="/logo?v={{ $settings->logo_updated_at ? strtotime((string) $settings->logo_updated_at) : 0 }}" alt="" class="mb-2 max-h-14 max-w-36 object-contain">
                    @endif
                    <h1 class="text-lg font-semibold text-content">{{ $settings->business_name ?? 'NunaBooks' }}</h1>
                    @if ($settings->tagline)<p class="text-xs text-content-muted">{{ $settings->tagline }}</p>@endif
                    @if ($settings->phone)<p class="text-xs text-content-muted">{{ $settings->phone }}</p>@endif
                </div>
                <div class="shrink-0 text-right">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-content-subtle">Statement</p>
                    <p class="text-xs text-content-muted">As at @date($asAt)</p>
                </div>
            </header>

            <section class="mb-6 border-y border-line py-3">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-content-subtle">Account</p>
                <p class="font-medium text-content">{{ $customer['name'] }}</p>
                @if ($customer['phone'])<p class="text-xs text-content-muted">{{ $customer['phone'] }}</p>@endif
            </section>

            @if ($rows === [])
                <x-empty-state title="Nothing outstanding" :description="$customer['name'].' owes nothing as at '.Format::date($asAt).'.'" />
            @else
                <table class="mb-4 w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-xs uppercase tracking-wide text-content-subtle">
                            <th class="py-1.5 text-left font-medium">Document</th>
                            <th class="py-1.5 text-left font-medium">Dated</th>
                            <th class="py-1.5 text-left font-medium">Due</th>
                            <th class="py-1.5 text-right font-medium">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b border-line last:border-b-0">
                                <td class="py-1.5 pr-2 text-content">{{ $row['document'] }}</td>
                                <td class="py-1.5 pr-2 text-content-muted">@date($row['businessDate'])</td>
                                <td class="py-1.5 pr-2">
                                    @if ($row['dueDate'])
                                        <span class="{{ $row['overdueBy'] > 0 ? 'font-medium text-danger' : 'text-content-muted' }}">@date($row['dueDate']){{ $row['overdueBy'] > 0 ? ' · '.$row['overdueBy'].'d late' : '' }}</span>
                                    @else
                                        <span class="text-content-subtle">—</span>
                                    @endif
                                </td>
                                <td class="tabular py-1.5 text-right text-content">{{ Format::money($row['outstanding'], true, $currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <dl class="ml-auto max-w-xs">
                    <div class="flex justify-between gap-4 border-t-2 border-line-strong pt-2">
                        <dt class="font-semibold text-content">Total owed</dt>
                        <dd class="tabular text-lg font-semibold text-content">{{ Format::money($balance, currencyCode: $currency) }}</dd>
                    </div>
                </dl>
            @endif

            <footer class="mt-6 border-t border-line pt-4 text-xs text-content-muted">
                <p>This statement covers everything unpaid as at @date($asAt). Payments made after that date are not included.</p>
            </footer>
        </article>
    </div>
</x-layouts::app>
