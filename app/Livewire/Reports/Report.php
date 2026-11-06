<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Domain\JsCompat;
use App\Support\Filters\Filters;
use App\View\Format;

/**
 * Presentation helpers the report pages share: the old
 * shared/period-filter describePeriod, the reports' percent(), and the query
 * the CSV button carries. Text only; no figure is worked out here.
 */
final class Report
{
    /** The old describePeriod(): "Everything up to …", "05 Aug 2026", "Up to …", "… to …". */
    public static function describePeriod(array $range, string $preset): string
    {
        return match (true) {
            $preset === 'all' => 'Everything up to '.Format::date($range['to']),
            $range['from'] === $range['to'] => Format::date($range['from']),
            $range['from'] === Filters::EARLIEST_DATE => 'Up to '.Format::date($range['to']),
            default => Format::date($range['from']).' to '.Format::date($range['to']),
        };
    }

    /**
     * The old percent(bp): `${(bp / 100).toFixed(1)}%`, or "—" with no figure.
     * shortcut: belongs in App\View\Format beside money(); move it there when that file is free to edit.
     */
    public static function percent(?int $basisPoints): string
    {
        if ($basisPoints === null) {
            return '—';
        }
        $tenths = JsCompat::toFixedScaled($basisPoints / 100, 1)->toInt();

        return ($tenths < 0 ? '-' : '').intdiv(abs($tenths), 10).'.'.(abs($tenths) % 10).'%';
    }

    /** A select's options, id => name, from service rows (arrays or objects). @return array<int, string> */
    public static function options(iterable $items, string $label = 'name'): array
    {
        $options = [];
        foreach ($items as $item) {
            $options[(int) data_get($item, 'id')] = (string) data_get($item, $label);
        }

        return $options;
    }

    /**
     * What the CSV button sends: the page's carried filters with the RESOLVED
     * dates, because the export reads from/to and would turn a bare
     * ?period=year into this month.
     */
    public static function csvQuery(array $list): array
    {
        return [...$list['carried'], 'from' => $list['range']['from'], 'to' => $list['range']['to']];
    }
}
