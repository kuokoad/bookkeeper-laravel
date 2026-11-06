{{-- One statement of the year-end pack (the old page's Statement): a heading, a note, a header row, then the slot rows. --}}
<section class="mb-8 break-inside-avoid">
    <h2 class="mb-1 text-base font-semibold text-content">{{ $title }}</h2>
    @if ($note ?? null)<p class="mb-2 text-xs text-content-muted">{{ $note }}</p>@endif
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-line-strong text-xs uppercase tracking-wide text-content-subtle">
                <th class="py-1.5 text-left font-medium">{{ $headers[0] }}</th>
                @foreach (array_slice($headers, 1) as $i => $header)
                    <th @class(['py-1.5 text-right font-medium', 'pl-6' => $i === 1])>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows ?? [] as $row)
                <tr @class(['border-t border-line-strong' => $row['rule']])>
                    <td @class(['py-1.5 pr-4', 'pl-4' => $row['indent'], 'font-semibold text-content' => $row['bold'], 'text-content-muted' => ! $row['bold']])>{{ $row['label'] }}</td>
                    <td @class(['tabular py-1.5 text-right', 'font-semibold text-content' => $row['bold'], 'text-content' => ! $row['bold']])>{{ \App\View\Format::moneyAccounting($row['value'], currencyCode: $currency) }}</td>
                    @if ($row['previous'] !== null)
                        <td class="tabular py-1.5 pl-6 text-right text-content-muted">{{ \App\View\Format::moneyAccounting($row['previous'], currencyCode: $currency) }}</td>
                    @endif
                </tr>
            @endforeach
            @foreach ($cells ?? [] as $cell)
                {{-- Free-form rows: [label, in/col1, col2, classes], money already formatted. --}}
                <tr @class([$cell[3] ?? ''])>
                    <td @class(['py-1.5 pr-4', $cell[4] ?? 'text-content-muted'])>{{ $cell[0] }}</td>
                    <td class="tabular py-1.5 text-right text-content">{{ $cell[1] }}</td>
                    <td class="tabular py-1.5 pl-6 text-right text-content">{{ $cell[2] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</section>
