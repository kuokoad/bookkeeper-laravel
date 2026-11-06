<?php

declare(strict_types=1);

/*
| The shared list components module screens are built from: sort headings
| keep to the module's whitelist, pagination and filters keep every other
| filter, and the controls show the value the server resolved (filter rule 5).
*/

use App\Services\SaleService;
use App\Support\Filters\ListFilters;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\ViewException;

function salesList(array $params): array
{
    return ListFilters::parseSalesFilters($params, '2026-08-31');
}

it('links a sortable heading to the other direction, keeping the filters and dropping the page', function () {
    $html = Blade::render('<x-th sort="amount" :sorts="$sorts" :list="$list" numeric>Total</x-th>', [
        'sorts' => SaleService::SALE_SORTS,
        'list' => salesList(['q' => 'rice', 'sort' => 'amount', 'direction' => 'desc', 'page' => '3']),
    ]);
    expect($html)->toContain('aria-sort="descending"')
        ->toContain('href="?q=rice&amp;sort=amount&amp;direction=asc"')
        ->not->toContain('page=');
});

it('shows the order the server actually used, not what the URL asked for', function () {
    // An unknown sort key falls back to date; the heading must not claim "amount".
    $html = Blade::render('<x-th sort="amount" :sorts="$sorts" :list="$list">Total</x-th>', ['sorts' => SaleService::SALE_SORTS, 'list' => salesList(['sort' => 'drop table'])]);
    expect($html)->toContain('aria-sort="none"');
});

it('refuses a sort heading the module does not whitelist', function () {
    Blade::render('<x-th sort="password" :sorts="$sorts" :list="$list">X</x-th>', ['sorts' => SaleService::SALE_SORTS, 'list' => salesList([])]);
})->throws(ViewException::class, 'not in this list\'s whitelist');

it('pages through the same filters', function () {
    $list = ListFilters::parseSalesFilters(['q' => 'rice', 'page' => '2'], '2026-08-31', 10);
    $html = Blade::render('<x-pagination :list="$list" :total="25" noun="sale" />', ['list' => $list]);
    expect($html)->toContain('Showing 11–20 of 25 sales')->toContain('Page 2 of 3')
        ->toContain('href="?q=rice"')->toContain('href="?q=rice&amp;page=3"');
});

it('fills the period from the resolved range, and carries what it does not own', function () {
    // Ends typed the wrong way round are swapped by the parser; the boxes show the swap.
    $list = salesList(['period' => 'custom', 'from' => '2026-08-20', 'to' => '2026-08-01', 'sort' => 'amount', 'direction' => 'asc']);
    $html = Blade::render('<x-filter-bar :list="$list" :dates="true" :fields="$fields" />', ['list' => $list, 'fields' => [
        ['kind' => 'search', 'key' => 'q', 'label' => 'Search', 'value' => $list['filters']['search'] ?? ''],
    ]]);
    expect($html)->toMatch('/value="2026-08-01"[^>]*name="from"/')->toMatch('/value="2026-08-20"[^>]*name="to"/')
        ->toContain('<option value="custom" selected')
        ->toContain('name="sort" value="amount"')->toContain('name="direction" value="asc"');
});

it('links the CSV button to the shared export route with the page dropped', function () {
    $html = Blade::render('<x-csv-button list="sales" :query="[\'q\' => \'rice\', \'page\' => \'4\']" />');
    expect($html)->toContain('href="/export/list/sales?q=rice"');
});

it('opens a confirm dialog again only for its own errors, not another form\'s on the page', function () {
    $render = function (array $messages, string $extra = ''): string {
        view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag($messages)));

        return Blade::render('<x-confirm-dialog title="Void this sale" description="d" trigger="Void…" '.$extra.'>x</x-confirm-dialog>');
    };

    // On the sale page a return-form error used to open the void dialog.
    expect($render(['returnForm' => 'Enter a quantity for at least one item.']))->toContain('confirming: false')
        ->and($render([]))->toContain('confirming: false')
        ->and($render(['reason' => 'Give a reason for voiding this sale.']))->toContain('confirming: true')
        ->and($render(['form' => 'That sale is already void.']))->toContain('confirming: true')
        ->and($render(['note' => 'x'], ':keys="[\'note\']"'))->toContain('confirming: true');
});
