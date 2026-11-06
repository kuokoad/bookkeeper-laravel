@php
    use App\View\Format;
    $lineQty = fn (array $item) => $item['entryUnit'] && $item['entryQtyMilli'] !== null
        ? Format::qty((int) $item['entryQtyMilli'], $item['entryUnit'])
        : Format::qty((int) $item['qtyMilli'], $item['unit']);
    $currency = $settings->currency_code ?? 'GHS';
@endphp
<x-layouts::app title="Receipt">
    {{--
        The paper a receipt comes out on is whichever the print dialog chose:
        a sheet gets the usual 14mm, a till roll (narrower than 120mm) 4mm.
        Scoped by being on this route.
    --}}
    <style>
        @page { size: auto; margin: 0; }
        @media print { body { padding: 4mm; } }
        @media print and (min-width: 120mm) { body { padding: 14mm; } }
    </style>
    <div class="mx-auto max-w-md">
        <div class="no-print mb-4 flex items-center justify-between gap-2">
            <x-button variant="secondary" size="sm" href="/sales/{{ $sale['id'] }}">Back</x-button>
            <x-button size="sm" x-data x-on:click="window.print()">Print</x-button>
        </div>

        <article class="receipt-paper rounded-xl border border-line bg-surface-raised p-6 text-sm print:mx-0">
            <header class="mb-4 text-center">
                @if ($settings->logo_data !== null)
                    <img src="/logo?v={{ $settings->logo_updated_at ? strtotime((string) $settings->logo_updated_at) : 0 }}" alt="" class="mx-auto mb-2 max-h-16 max-w-40 object-contain">
                @endif
                <h1 class="text-lg font-semibold text-content">{{ $settings->business_name ?? 'NunaBooks' }}</h1>
                @if ($settings->tagline)<p class="text-xs text-content-muted">{{ $settings->tagline }}</p>@endif
                @if ($settings->address)<p class="text-xs text-content-muted">{{ $settings->address }}</p>@endif
                @if ($settings->phone)<p class="text-xs text-content-muted">{{ $settings->phone }}</p>@endif
            </header>

            <div class="mb-4 border-y border-dashed border-line py-2 text-xs">
                <div class="flex justify-between"><span class="text-content-muted">Receipt</span><span class="font-medium text-content">{{ $sale['receiptNo'] }}</span></div>
                {{-- The trading date; only the time of day comes from occurredAt. --}}
                <div class="flex justify-between"><span class="text-content-muted">Date</span><span class="text-content">@date($sale['businessDate']) {{ \Illuminate\Support\Str::after(Format::dateTime($sale['occurredAt']), ', ') }}</span></div>
                @if ($sale['customerName'])
                    <div class="flex justify-between"><span class="text-content-muted">Customer</span><span class="text-content">{{ $sale['customerName'] }}</span></div>
                @endif
            </div>

            @if ($sale['status'] === 'VOIDED')
                <p class="mb-4 border border-danger/40 bg-danger-soft px-3 py-2 text-center text-xs font-semibold text-content">VOIDED — {{ $sale['voidReason'] }}</p>
            @endif

            <table class="mb-3 w-full">
                <thead>
                    <tr class="border-b border-dashed border-line text-xs text-content-muted">
                        <th scope="col" class="pb-1 text-left font-normal">Item</th>
                        <th scope="col" class="pb-1 text-right font-normal">Qty</th>
                        <th scope="col" class="pb-1 text-right font-normal">Price</th>
                        <th scope="col" class="pb-1 text-right font-normal">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sale['items'] as $item)
                        <tr class="align-top">
                            <td class="py-1 pr-2 text-content">{{ $item['productName'] }}</td>
                            <td class="tabular py-1 text-right text-content-muted">{{ $lineQty($item) }}</td>
                            <td class="tabular py-1 text-right text-content-muted">{{ Format::money((int) $item['unitPriceMinor'], bare: true) }}</td>
                            <td class="tabular py-1 text-right font-medium text-content">{{ Format::money((int) $item['lineTotalMinor'], bare: true) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <dl class="space-y-1 border-t border-dashed border-line pt-2">
                <div class="flex justify-between"><dt class="text-content-muted">Subtotal</dt><dd class="tabular text-content">{{ Format::money($totals['subtotal'], bare: true) }}</dd></div>
                @if ($totals['discount'] > 0)
                    <div class="flex justify-between"><dt class="text-content-muted">Discount</dt><dd class="tabular text-content">−{{ Format::money($totals['discount'], bare: true) }}</dd></div>
                @endif
                @unless ($totals['taxWithinTotal'])
                    @foreach ($sale['taxes'] as $line)
                        @if ((int) $line['amountMinor'] !== 0)
                            <div class="flex justify-between"><dt class="text-content-muted">{{ $line['name'] }}</dt><dd class="tabular text-content">{{ Format::money((int) $line['amountMinor'], bare: true) }}</dd></div>
                        @endif
                    @endforeach
                @endunless
                <div class="flex justify-between border-t border-line pt-1 text-base font-semibold"><dt class="text-content">Total</dt><dd class="tabular text-content">{{ Format::money($totals['total'], currencyCode: $currency) }}</dd></div>
                @if ($totals['taxWithinTotal'])
                    @foreach ($sale['taxes'] as $line)
                        @if ((int) $line['amountMinor'] !== 0)
                            <div class="flex justify-between text-xs"><dt class="text-content-muted">includes {{ $line['name'] }}</dt><dd class="tabular text-content-muted">{{ Format::money((int) $line['amountMinor'], bare: true) }}</dd></div>
                        @endif
                    @endforeach
                @endif
            </dl>

            <dl class="mt-3 space-y-1 border-t border-dashed border-line pt-2 text-xs">
                @foreach ($sale['tenders'] as $tender)
                    <div class="flex justify-between"><dt class="text-content-muted">Paid by {{ $tender['accountName'] }}</dt><dd class="tabular text-content">{{ Format::money((int) $tender['amountMinor'], bare: true) }}</dd></div>
                @endforeach
                @if ($change > 0)
                    <div class="flex justify-between font-semibold"><dt class="text-content">Change</dt><dd class="tabular text-content">{{ Format::money($change, bare: true) }}</dd></div>
                @endif
                @if ($sale['outstandingMinor'] > 0)
                    <div class="flex justify-between font-semibold"><dt class="text-content">Balance owing</dt><dd class="tabular text-content">{{ Format::money((int) $sale['outstandingMinor'], bare: true) }}</dd></div>
                @endif
            </dl>

            <footer class="mt-5 border-t border-dashed border-line pt-3 text-center text-xs text-content-muted">
                <p>Thank you.</p>
                @if ($sale['note'])<p class="mt-1 italic">{{ $sale['note'] }}</p>@endif
            </footer>
        </article>
    </div>
    @if ($autoPrint)
        {{-- Once per visit: the flag leaves the address first, so a refresh or Back does not print again. --}}
        <script>
            window.addEventListener('load', () => { window.history.replaceState(null, '', window.location.pathname); window.print(); });
        </script>
    @endif
</x-layouts::app>
