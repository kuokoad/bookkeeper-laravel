@php
    use App\View\Format;
    $currency = $settings->currency_code ?? 'GHS';
    $cash = fn (int $v) => Format::money($v, currencyCode: $currency);
    $lineQty = fn (array $item) => $item['entryUnit'] && $item['entryQtyMilli'] !== null
        ? Format::qty((int) $item['entryQtyMilli'], $item['entryUnit'])
        : Format::qty((int) $item['qtyMilli'], $item['unit']);
@endphp
<x-layouts::app title="Quote">
    <div class="mx-auto max-w-3xl">
        <div class="no-print mb-4 flex items-center justify-between gap-2">
            <x-button variant="secondary" size="sm" href="/quotations/{{ $quote['id'] }}">Back</x-button>
            <x-button size="sm" x-data x-on:click="window.print()">Print</x-button>
        </div>

        <article class="rounded-xl border border-line bg-surface-raised p-6 text-sm">
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-line pb-4">
                <div class="min-w-0">
                    @if ($settings->has_logo)
                        <img src="/logo" alt="" class="mb-2 max-h-14 max-w-36 object-contain">
                    @endif
                    <p class="text-lg font-semibold text-content">{{ $settings->business_name ?? 'NunaBooks' }}</p>
                    @if ($settings->tagline)<p class="text-content-muted">{{ $settings->tagline }}</p>@endif
                    @if ($settings->address)<p class="text-content-muted">{{ $settings->address }}</p>@endif
                    @if ($settings->phone)<p class="text-content-muted">{{ $settings->phone }}</p>@endif
                </div>
                <div class="text-right">
                    <p class="text-xs font-semibold uppercase tracking-wider text-content-muted">Quotation</p>
                    <p class="text-xl font-semibold text-content">{{ $quote['quoteNo'] }}</p>
                    <p class="mt-1 text-content-muted">Issued @date($quote['businessDate'])</p>
                    <p class="font-medium text-content">Valid until @date($quote['validUntil'])</p>
                </div>
            </header>

            <section class="border-b border-line py-4">
                <p class="text-xs font-semibold uppercase tracking-wider text-content-muted">Prepared for</p>
                <p class="font-semibold text-content">{{ $quote['customerName'] }}</p>
                @if ($quote['customerPhone'])<p class="text-content-muted">{{ $quote['customerPhone'] }}</p>@endif
                @if ($quote['reference'])<p class="text-content-muted">{{ $quote['reference'] }}</p>@endif
            </section>

            <table class="w-full py-4">
                <thead>
                    <tr class="border-b border-line text-left text-xs uppercase tracking-wider text-content-muted">
                        <th class="py-3 font-semibold">Item</th>
                        <th class="py-3 text-right font-semibold">Qty</th>
                        <th class="py-3 text-right font-semibold">Price</th>
                        <th class="py-3 text-right font-semibold">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($quote['items'] as $item)
                        <tr class="border-b border-line">
                            <td class="py-2.5 text-content">{{ $item['productName'] }}</td>
                            <td class="tabular py-2.5 text-right text-content">{{ $lineQty($item) }}</td>
                            <td class="tabular py-2.5 text-right text-content">{{ $cash((int) $item['unitPriceMinor']) }}</td>
                            <td class="tabular py-2.5 text-right text-content">{{ $cash((int) $item['lineTotalMinor']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <dl class="ml-auto max-w-xs space-y-1.5 pt-4">
                <div class="flex items-baseline justify-between gap-3"><dt class="text-content-muted">Subtotal</dt><dd class="tabular text-content">{{ $cash($totals['subtotal']) }}</dd></div>
                @if ($totals['discount'] > 0)
                    <div class="flex items-baseline justify-between gap-3"><dt class="text-content-muted">Discount</dt><dd class="tabular text-content">&minus;{{ $cash($totals['discount']) }}</dd></div>
                @endif
                @if ($totals['tax'] > 0)
                    <div class="flex items-baseline justify-between gap-3"><dt class="text-content-muted">Tax{{ $totals['taxWithinTotal'] ? ' (included)' : '' }}</dt><dd class="tabular text-content">{{ $cash($totals['tax']) }}</dd></div>
                @endif
                <div class="flex items-baseline justify-between gap-3 border-t border-line pt-2"><dt class="font-semibold text-content">Total</dt><dd class="tabular text-lg font-semibold text-content">{{ $cash($totals['total']) }}</dd></div>
            </dl>

            <footer class="mt-6 border-t border-line pt-4 text-content-muted">
                @if ($quote['notes'])<p class="mb-2 text-content">{{ $quote['notes'] }}</p>@endif
                {{-- The sentence that makes this a quote rather than a bill. --}}
                <p>This is a quotation, not a bill. Nothing has been sold and no payment is due.{{ $quote['status'] === 'CONVERTED' ? '' : ' Prices hold until '.Format::date($quote['validUntil']).'.' }}</p>
                @if ($expired)<p class="mt-1 font-medium text-content">This quote has passed its date. Please ask us to confirm the price before ordering.</p>@endif
                @if ($quote['status'] === 'CANCELLED')<p class="mt-1 font-medium text-content">This quote was withdrawn.</p>@endif
                @if ($quote['status'] === 'CONVERTED')<p class="mt-1 font-medium text-content">This quote has been accepted and invoiced.</p>@endif
                @if ($settings->phone)<p class="mt-2">Questions? Call {{ $settings->phone }}.</p>@endif
            </footer>
        </article>
    </div>
</x-layouts::app>
