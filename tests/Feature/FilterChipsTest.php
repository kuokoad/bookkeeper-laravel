<?php

declare(strict_types=1);

/*
| The old FilterBar's two kinds of chip on <x-filter-bar>: quick filters
| above the controls, and "Showing:" chips for what is on, each removing
| only its own filter.
*/

use App\Support\Filters\ListFilters;
use Illuminate\Support\Facades\Blade;

function chipFields(array $list): array
{
    return [
        ['kind' => 'search', 'key' => 'q', 'label' => 'Search', 'value' => $list['filters']['search'] ?? ''],
        ['kind' => 'select', 'key' => 'status', 'label' => 'Status', 'allLabel' => 'All', 'options' => ['POSTED' => 'Posted', 'VOIDED' => 'Voided'], 'value' => $list['filters']['status'] ?? null],
        ['kind' => 'select', 'key' => 'method', 'label' => 'Method', 'allLabel' => 'All', 'options' => ['CASH' => 'Cash'], 'value' => $list['filters']['paymentKind'] ?? null],
    ];
}

it('shows a removable chip for each filter the URL set, and quick filters that toggle', function () {
    $list = ListFilters::parseSalesFilters(['period' => 'last-month', 'status' => 'VOIDED', 'q' => 'rice', 'sort' => 'amount'], '2026-08-31');
    $html = Blade::render('<x-filter-bar :list="$list" :dates="true" :quick="$quick" :fields="$fields" />', [
        'list' => $list,
        'quick' => [['label' => 'Voided', 'params' => ['status' => 'VOIDED']], ['label' => 'Cash sales', 'params' => ['method' => 'CASH']]],
        'fields' => chipFields($list),
    ]);

    expect($html)->toContain('Showing:')
        ->toContain('Period:</span>')->toContain('2026-07-01 to 2026-07-31')
        ->toContain('Status:</span>')->toContain('Search:</span>')
        ->not->toContain('Method:</span>')
        // The period chip clears from and to with it; the status chip leaves the rest.
        ->toContain('href="?q=rice&amp;status=VOIDED&amp;sort=amount"')
        ->toContain('href="?period=last-month&amp;q=rice&amp;sort=amount"')
        // "Voided" is on, so it reads pressed and turns itself off; "Cash sales" adds to what is there.
        ->toContain('aria-pressed="true"')
        ->toContain('href="?period=last-month&amp;q=rice&amp;status=VOIDED&amp;sort=amount&amp;method=CASH"');
});

it('shows no chips for an unfiltered list, nor for junk the parser threw away', function () {
    foreach ([[], ['status' => 'drop table'], ['period' => 'month']] as $params) {
        $list = ListFilters::parseSalesFilters($params, '2026-08-31');
        $html = Blade::render('<x-filter-bar :list="$list" :dates="true" :fields="$fields" />', ['list' => $list, 'fields' => chipFields($list)]);
        expect($html)->not->toContain('Showing:');
    }
});
