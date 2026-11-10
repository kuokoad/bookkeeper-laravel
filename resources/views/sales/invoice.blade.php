@php
    use App\View\Format;
    $lineQty = fn (array $item) => $item['entryUnit'] && $item['entryQtyMilli'] !== null
        ? Format::qty((int) $item['entryQtyMilli'], $item['entryUnit'])
        : Format::qty((int) $item['qtyMilli'], $item['unit']);
    $currency = $settings->currency_code ?? 'GHS';
    $cash = fn (int $v, bool $bare = true) => Format::money($v, $bare, $currency);
@endphp
<x-layouts::app title="Invoice">
    @if (! $sale['invoiceNo'])
        {{-- A cash sale has no invoice: say so plainly and point at the receipt. --}}
        <div class="mx-auto max-w-xl">
            <x-alert title="This sale has no invoice">
                {{ $sale['receiptNo'] }} was paid in full at the time, so there is nothing to request payment
                for. Its receipt is the document for it.
            </x-alert>
            <x-button size="sm" class="mt-4" href="/sales/{{ $sale['id'] }}/receipt">Open the receipt</x-button>
        </div>
    @else
        <div class="mx-auto max-w-2xl">
            <div class="no-print mb-4 flex flex-wrap items-center justify-between gap-2">
                <x-button variant="secondary" size="sm" href="/sales/{{ $sale['id'] }}">Back to the sale</x-button>
                <x-button size="sm" x-data x-on:click="window.print()">Print</x-button>
            </div>

            @if ($settled)
                <x-alert tone="success" class="no-print mb-4">This invoice has been paid in full. Kept for the record.</x-alert>
            @elseif ($overdueBy > 0)
                <x-alert tone="warning" class="no-print mb-4">Payment was due @date($sale['dueDate']) — {{ $overdueBy }} {{ Format::plural($overdueBy, 'day') }} ago.</x-alert>
            @endif

            <article class="rounded-xl border border-line bg-surface-raised p-6 text-sm">
                <header class="mb-6 flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        @if ($settings->has_logo)
                            <img src="/logo?v={{ $settings->logo_updated_at ? strtotime((string) $settings->logo_updated_at) : 0 }}" alt="" class="mb-2 max-h-14 max-w-36 object-contain">
                        @endif
                        <h1 class="text-lg font-semibold text-content">{{ $settings->business_name ?? 'NunaBooks' }}</h1>
                        @if ($settings->tagline)<p class="text-xs text-content-muted">{{ $settings->tagline }}</p>@endif
                        @if ($settings->address)<p class="text-xs text-content-muted">{{ $settings->address }}</p>@endif
                        @if ($settings->phone)<p class="text-xs text-content-muted">{{ $settings->phone }}</p>@endif
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-content-subtle">Invoice</p>
                        <p class="tabular text-lg font-semibold text-content">{{ $sale['invoiceNo'] }}</p>
                        <p class="mt-1 text-xs text-content-muted">Issued @date($sale['businessDate'])</p>
                        @if ($sale['dueDate'])<p class="text-xs font-medium text-content">Due @date($sale['dueDate'])</p>@endif
                    </div>
                </header>

                <section class="mb-6 border-y border-line py-3">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-content-subtle">Billed to</p>
                    <p class="font-medium text-content">{{ $sale['customerName'] ?? 'Customer' }}</p>
                    @if ($sale['termsDays'] !== null)
                        <p class="text-xs text-content-muted">{{ (int) $sale['termsDays'] === 0 ? 'Payment due on receipt' : "Payment terms: {$sale['termsDays']} days" }}</p>
                    @endif
                </section>

                <table class="mb-4 w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-xs uppercase tracking-wide text-content-subtle">
                            <th class="py-1.5 text-left font-medium">Item</th>
                            <th class="py-1.5 text-right font-medium">Qty</th>
                            <th class="py-1.5 text-right font-medium">Price</th>
                            <th class="py-1.5 text-right font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sale['items'] as $item)
                            <tr class="border-b border-line last:border-b-0">
                                <td class="py-1.5 pr-2 text-content">{{ $item['productName'] }}</td>
                                <td class="tabular py-1.5 text-right text-content-muted">{{ $lineQty($item) }}</td>
                                <td class="tabular py-1.5 text-right text-content-muted">{{ $cash((int) $item['unitPriceMinor']) }}</td>
                                <td class="tabular py-1.5 text-right text-content">{{ $cash((int) $item['lineTotalMinor']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <dl class="ml-auto max-w-xs space-y-1.5">
                    <div class="flex justify-between gap-4"><dt class="text-content-muted">Subtotal</dt><dd class="tabular text-content">{{ $cash($totals['subtotal']) }}</dd></div>
                    @if ($totals['discount'] > 0)
                        <div class="flex justify-between gap-4"><dt class="text-content-muted">Discount</dt><dd class="tabular text-content">−{{ $cash($totals['discount']) }}</dd></div>
                    @endif
                    @unless ($totals['taxWithinTotal'])
                        @foreach ($sale['taxes'] as $line)
                            @if ((int) $line['amountMinor'] !== 0)
                                <div class="flex justify-between gap-4"><dt class="text-content-muted">{{ $line['name'] }}</dt><dd class="tabular text-content">{{ $cash((int) $line['amountMinor']) }}</dd></div>
                            @endif
                        @endforeach
                    @endunless
                    <div class="flex justify-between gap-4 border-t border-line pt-1.5"><dt class="font-semibold text-content">Total</dt><dd class="tabular font-semibold text-content">{{ $cash($totals['total'], false) }}</dd></div>
                    @if ($totals['taxWithinTotal'])
                        @foreach ($sale['taxes'] as $line)
                            @if ((int) $line['amountMinor'] !== 0)
                                <div class="flex justify-between gap-4 text-xs"><dt class="text-content-muted">includes {{ $line['name'] }}</dt><dd class="tabular text-content-muted">{{ $cash((int) $line['amountMinor']) }}</dd></div>
                            @endif
                        @endforeach
                    @endif
                    @if ($sale['creditedMinor'] > 0)
                        <div class="flex justify-between gap-4"><dt class="text-content-muted">Returned</dt><dd class="tabular text-content">−{{ $cash((int) $sale['creditedMinor']) }}</dd></div>
                    @endif
                    @if ($alreadyPaid > 0)
                        <div class="flex justify-between gap-4"><dt class="text-content-muted">Already paid</dt><dd class="tabular text-content">−{{ $cash($alreadyPaid) }}</dd></div>
                    @endif
                    <div class="flex justify-between gap-4 border-t-2 border-line-strong pt-1.5"><dt class="font-semibold text-content">Amount due</dt><dd class="tabular text-lg font-semibold text-content">{{ $cash($amountDue, false) }}</dd></div>
                    @if ($inCredit > 0)
                        <div class="flex justify-between gap-4"><dt class="text-content-muted">In credit on your account</dt><dd class="tabular text-content">{{ $cash($inCredit) }}</dd></div>
                    @endif
                </dl>

                <footer class="mt-6 border-t border-line pt-4 text-xs text-content-muted">
                    @if ($settled)
                        <p>Paid in full. Thank you.</p>
                    @else
                        <p>Please pay {{ $cash((int) $sale['outstandingMinor'], false) }}@if ($sale['dueDate']) by @date($sale['dueDate'])@endif. Quote <span class="font-medium text-content">{{ $sale['invoiceNo'] }}</span> when you pay.</p>
                    @endif
                    @if ($settings->phone)<p class="mt-1">Questions? Call {{ $settings->phone }}.</p>@endif
                </footer>
            </article>
        </div>
    @endif
</x-layouts::app>
