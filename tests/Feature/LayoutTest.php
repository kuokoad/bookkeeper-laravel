<?php

declare(strict_types=1);

/*
| The signed-in shell: the menu follows the person's rights and the shop's
| features (the old (app)/layout.tsx), the header names the shop and the
| person, and Blade formats money through the domain.
*/

use App\View\Format;
use App\View\Navigation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;

it('keeps the old menu: same groups, labels and order', function () {
    $menu = array_map(fn (array $s): string => $s['heading'].': '.implode(', ', array_column($s['items'], 'label')), Navigation::SECTIONS);
    expect($menu)->toBe([
        'Daily: Dashboard, Sales, Quotations, Purchases, Expenses',
        'Stock: Stock items, Inventory',
        'People: Customers, Suppliers',
        'Money: Accounts, Reconciliation, Accounting, Reports',
        'Admin: Users, Settings',
    ]);
});

it('shows a staff member only the areas they hold, and the dashboard always', function () {
    $html = signedIn(staff(['sales' => viewOnly(), 'customers' => viewOnly()]))->get('/dashboard')->assertOk()->getContent();
    $nav = substr($html, strpos($html, 'aria-label="Main"'), 6000);
    expect($nav)->toContain('href="/dashboard"')->toContain('href="/sales"')->toContain('href="/customers"')
        ->not->toContain('href="/settings"')->not->toContain('href="/reports"')->not->toContain('href="/users"')->not->toContain('href="/suppliers"');
});

it('offers Quotations only to a shop that switched it on, and never to someone without the right', function () {
    $ownerId = owner();
    DB::table('business_settings')->update(['feature_quotations' => false]);
    signedIn($ownerId)->get('/dashboard')->assertDontSee('href="/quotations"', false);
    DB::table('business_settings')->update(['feature_quotations' => true]);
    signedIn($ownerId)->get('/dashboard')->assertSee('href="/quotations"', false);
    signedIn(staff(['sales' => viewOnly()]))->get('/dashboard')->assertDontSee('href="/quotations"', false);
});

it('names the shop and the person, and offers switch user and sign out', function () {
    DB::table('business_settings')->update(['business_name' => 'Auntie Akos Provisions']);
    signedIn(staff([], 'ama'))->get('/dashboard')
        ->assertSee('Auntie Akos Provisions')->assertSee('Ama')->assertSee('Switch user')->assertSee('Sign out');
});

it('formats money, quantities and dates through the domain', function () {
    expect(Blade::render('@money($v)', ['v' => -125_050]))->toBe('GHS -1,250.50')
        ->and(Blade::render('@qty($q, $u)', ['q' => 1_500, 'u' => 'kg']))->toBe('1.5 kg')
        ->and(Blade::render('@date($d)', ['d' => '2026-09-05']))->toBe('05 Sept 2026')
        ->and(Format::moneyAccounting(-125_000))->toBe('GHS (1,250.00)')
        ->and(Format::money(125_000, bare: true))->toBe('1,250.00');
});

it('shows money in the shop currency when the shop is not in GHS', function () {
    DB::table('business_settings')->update(['currency_code' => 'NGN']);
    expect(Blade::render('@money($v)', ['v' => 125_000]))->toBe('NGN 1,250.00')
        ->and(Format::moneyAccounting(-125_000))->toBe('NGN (1,250.00)')
        ->and(Format::money(125_000, currencyCode: 'USD'))->toBe('USD 1,250.00');
    signedIn(owner())->get('/dashboard')->assertOk()->assertSee('NGN 0.00')->assertDontSee('GHS 0.00');
});

it('flashes a one-shot message after a redirect', function () {
    signedIn(owner())->withSession(['status' => 'Saved.'])->get('/dashboard')->assertSee('Saved.');
});

it('searches as you type from the top bar, over a form that works without JavaScript', function () {
    $html = signedIn(owner())->get('/search?q=rice')->assertOk()->getContent();
    expect($html)->toContain('action="/search"')
        ->toContain("fetch('/search/suggest?q=' + encodeURIComponent(q)")
        ->toContain('See all results for')
        ->toContain('x-on:keydown.enter="enter($event)"')
        ->toContain('value="rice"');
});
