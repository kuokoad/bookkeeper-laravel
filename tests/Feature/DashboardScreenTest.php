<?php

declare(strict_types=1);

/*
| The rendered dashboard: the "dashboard server permissions" block and the
| page halves of the old tests/services/dashboard.test.tsx, deferred from
| tests/Services/DashboardTest (which keeps the figures).
*/

use App\Livewire\Dashboard;
use App\Models\User;
use App\Services\Actor;
use App\Services\PaymentAccountService;
use App\Services\ReconciliationService;
use App\Support\Clock;
use App\Support\Filters\ListFilters;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Shop;

beforeEach(function () {
    $this->ownerId = owner();
    $this->actor = new Actor($this->ownerId, 'kwame');
    $this->cash = Shop::paymentAccount('CASH');
    $this->momo = Shop::paymentAccount('MOBILE_MONEY');
    $this->today = Clock::today();
    $product = Shop::product($this->actor, 'Dashboard rice', 500, 1000);
    Shop::openingStock($this->actor, $product, 100_000, 50_000, substr($this->today, 0, 7).'-01');
    Shop::sell($this->actor, [[$product, 10_000]], [['paymentAccountId' => $this->cash, 'amount' => 10_000]], ['businessDate' => $this->today]);
});

function dashboardAs(int $userId, array $query = []): mixed
{
    return Livewire::withQueryParams($query)->actingAs(User::query()->findOrFail($userId))->test(Dashboard::class);
}

it('groups the approved layout without losing independently dated charts', function () {
    $html = dashboardAs($this->ownerId, ['sales' => 'week', 'spend' => 'quarter'])->html();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($dom);

    expect($xpath->query('//section[@aria-label="Primary financial figures"]/section')->length)->toBe(4)
        ->and($xpath->query('//section[@aria-labelledby="trends-heading"]/div/section')->length)->toBe(2)
        ->and($xpath->query('//section[@aria-labelledby="summaries-heading"]/div/section')->length)->toBe(3)
        ->and($xpath->query('//*[@aria-label="Create transaction"]//a')->item(0)->getAttribute('href'))->toBe('/sales/new');
    expect($html)->toContain('Daily sales')->toContain('Daily expenses')->toContain('Top selling stock item')
        ->toContain('Customer receivables')->toContain('Supplier payables')->toContain('Books Check')
        ->toContain('Account reconciliation')->toContain('reconciliation not verified')
        // Each card keeps its own window.
        ->toContain('/dashboard?sales=month&amp;spend=quarter')->toContain('/dashboard?sales=week&amp;spend=month');
});

it('shows the sales figure the services hold, formatted by the domain', function () {
    dashboardAs($this->ownerId)->assertSeeHtml('GHS 100.00')->assertSee('1 posted sale · transaction total');
});

it('keeps product sales summaries when inventory access is denied', function () {
    dashboardAs(staff(['sales' => viewOnly()]))
        ->assertSee('Top selling stock item')->assertSee('Dashboard rice')
        ->assertDontSee('Inventory valuation')->assertDontSee('Supplier payables')->assertDontSee('New Sale');
});

it('gives accounts-only access the Books Check without report-only links', function () {
    $html = dashboardAs(staff(['accounts' => viewOnly()]))->html();
    expect($html)->toContain('href="/accounting"')->not->toContain('/reports/')
        ->not->toContain('Supplier payables')->not->toContain('Account reconciliation');
});

it('shows opposing reconciliation differences, neither hiding the other', function () {
    foreach ([[$this->cash, 10_100], [$this->momo, -100]] as [$account, $actual]) {
        ReconciliationService::createReconciliation(['businessDate' => $this->today, 'paymentAccountId' => $account, 'actual' => $actual, 'adjust' => false, 'explanation' => 'Unresolved test count'], $this->actor);
    }
    $html = dashboardAs($this->ownerId)->html();
    expect(substr_count($html, 'unresolved count difference'))->toBe(2)
        ->and($html)->toContain('GHS 1.00 unresolved count difference')->toContain('GHS -1.00 unresolved count difference');
});

it('does not turn a negative ledger balance into a confirmed reconciliation error', function () {
    Shop::expense($this->actor, $this->today, PaymentAccountService::listExpenseCategories()[0]['id'], 100, 'Negative balance test', ['paymentAccountId' => $this->momo]);
    dashboardAs($this->ownerId)->assertSee('Negative ledger balance.')->assertSee('not a confirmed reconciliation error')->assertDontSee('unresolved count difference');
});

it('reads only: rendering changes no journal line and no stock row', function () {
    $lines = DB::table('journal_lines')->orderBy('id')->get()->toArray();
    $stock = DB::table('stock_ledger')->orderBy('id')->get()->toArray();
    dashboardAs($this->ownerId);
    expect(DB::table('journal_lines')->orderBy('id')->get()->toArray())->toEqual($lines)
        ->and(DB::table('stock_ledger')->orderBy('id')->get()->toArray())->toEqual($stock);
});

it('shows a person with no rights no figures, no actions and no denied links', function () {
    dashboardAs(staff([]))->assertSee('No dashboard summaries available')->assertDontSee('Net Profit')->assertDontSee('New Sale');
});

it('gives customer-view access receivables but not supplier balances or create actions', function () {
    $html = dashboardAs(staff(['customers' => viewOnly()]))->html();
    expect($html)->toContain('Customer receivables')->not->toContain('Supplier payables')
        ->not->toContain('Receive Payment')->not->toContain('/accounting');
});

it('opens for any signed-in person inside the shell, and not for anyone else', function () {
    $this->get('/dashboard')->assertRedirect('/login');
    signedIn(staff([]))->get('/dashboard')->assertOk()->assertSee('Dashboard')->assertSee('Sign out');
});

it('offers in Create Transaction only the entries a person may start', function () {
    $menu = function (int $id): array {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.dashboardAs($id)->html());

        return array_map(fn (DOMNode $a): string => trim($a->textContent), iterator_to_array((new DOMXPath($dom))->query('//*[@aria-label="Create transaction"]//a')));
    };
    $create = ['canView' => true, 'canCreate' => true, 'canEdit' => false, 'canVoid' => false];

    expect($menu($this->ownerId))->toContain('Sale', 'Purchase', 'Expense', 'Other income', 'Customer payment received', 'Supplier payment', 'Transfer between accounts', 'Stock adjustment')
        // Quotations stay off the menu until the shop turns them on.
        ->not->toContain('Quotation')
        ->and($menu(staff(['sales' => $create, 'expenses' => viewOnly()])))->toBe(['Sale'])
        ->and(dashboardAs(staff([], 'kofi'))->html())->not->toContain('Create Transaction');

    DB::table('business_settings')->update(['feature_quotations' => true]);
    expect($menu($this->ownerId))->toContain('Quotation');
});

it('sends Receive Payment and the receivables links to customers who owe, not to every customer', function () {
    $html = dashboardAs($this->ownerId)->html();
    preg_match_all('#href="(/customers\?[^"]*)"#', $html, $links);

    expect($links[1])->not->toBeEmpty();
    foreach ($links[1] as $href) {
        parse_str((string) parse_url(html_entity_decode($href), PHP_URL_QUERY), $query);
        // The customer list reads only what its filter parser knows; ?owing=1 was silently ignored.
        expect(ListFilters::parseCustomerFilters($query)['filters']['balanceState'] ?? null)->toBe('owing', $href);
    }
});
