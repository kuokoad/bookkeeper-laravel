{{--
    Money in against money out per period, on ONE shared scale (the old
    ui/chart.tsx PairedBars). Pixel positions only; figures arrive final.
    <x-chart.bars :data="[['label' => 'Jan', 'in' => 100, 'out' => 50], ...]" summary="..." />
--}}
@props(['data', 'summary'])
@if (count($data) === 0)
    <x-chart.empty />
@else
    @php
        $scale = max(1, ...array_map(fn (array $p): int => max(abs($p['in']), abs($p['out'])), $data));
        $band = 100 / count($data);
        $bar = min($band * 0.3, 6);
    @endphp
    <figure class="m-0">
        <svg viewBox="0 0 100 40" preserveAspectRatio="none" class="h-28 w-full" role="img" aria-label="{{ $summary }}">
            @foreach ($data as $i => $point)
                @php
                    $centre = $band * $i + $band / 2;
                    $inH = abs($point['in']) / $scale * 34;
                    $outH = abs($point['out']) / $scale * 34;
                @endphp
                <g>
                    <rect x="{{ round($centre - $bar - 0.6, 4) }}" y="{{ round(36 - $inH, 4) }}" width="{{ round($bar, 4) }}" height="{{ round($inH, 4) }}" rx="0.6" fill="var(--accent)" />
                    <rect x="{{ round($centre + 0.6, 4) }}" y="{{ round(36 - $outH, 4) }}" width="{{ round($bar, 4) }}" height="{{ round($outH, 4) }}" rx="0.6" fill="var(--border-strong)" />
                </g>
            @endforeach
            <line x1="0" y1="36" x2="100" y2="36" stroke="var(--border)" stroke-width="0.3" />
        </svg>
        <div class="mt-1 flex justify-between text-[10px] text-content-subtle">
            @foreach ($data as $point)
                <span class="flex-1 truncate text-center">{{ $point['label'] }}</span>
            @endforeach
        </div>
        <figcaption class="mt-2 flex gap-4 text-xs text-content-muted">
            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-accent" aria-hidden="true"></span> Money in</span>
            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-line-strong" aria-hidden="true"></span> Money out</span>
        </figcaption>
    </figure>
@endif
