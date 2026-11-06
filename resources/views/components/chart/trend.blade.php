{{--
    A trend line with a soft fill (the old ui/chart.tsx TrendLine), plain SVG
    drawn on the server. It turns final values into pixel positions and
    nothing else: no total is derived here. `summary` is the written figure.
    <x-chart.trend :data="Dashboard::dashboardDailySeries($rows, $period)" summary="..." />
--}}
@props(['data', 'summary'])
@if (count($data) < 2)
    <x-chart.empty />
@else
    @php
        $scale = max(1, ...array_map(fn (array $p): int => abs($p['value']), $data));
        $step = 100 / (count($data) - 1);
        $points = array_map(fn (array $p, int $i): string => round($i * $step, 4).','.round(34 - (max(0, $p['value']) / $scale) * 30, 4), $data, array_keys($data));
        $line = 'M '.implode(' L ', $points);
    @endphp
    <figure class="m-0">
        <svg viewBox="0 0 100 40" preserveAspectRatio="none" class="h-28 w-full" role="img" aria-label="{{ $summary }}">
            <path d="{{ $line }} L 100,36 L 0,36 Z" fill="var(--accent-soft)" />
            <path d="{{ $line }}" fill="none" stroke="var(--accent)" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
            <line x1="0" y1="36" x2="100" y2="36" stroke="var(--border)" stroke-width="0.3" />
        </svg>
        <div class="mt-1 flex justify-between text-[10px] text-content-subtle">
            <span>{{ $data[0]['label'] }}</span>
            <span>{{ $data[count($data) - 1]['label'] }}</span>
        </div>
    </figure>
@endif
