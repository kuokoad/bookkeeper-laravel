<?php

declare(strict_types=1);

/*
 * A form kept through an ended sign-in (resources/js/keep-form.js): signing
 * in again comes back to the page, and lines put back on the till or a
 * quotation are never overwritten by the next line added.
 */

use App\Livewire\Quotations\Form as QuotationForm;
use App\Livewire\Sales\Pos;
use App\Models\User;
use App\Services\Actor;
use Livewire\Livewire;
use Tests\Support\Shop;

beforeEach(function () {
    $this->ownerId = owner();
    $this->owner = User::query()->findOrFail($this->ownerId);
    $this->actor = new Actor($this->ownerId, 'kwame');
    Shop::paymentAccount('CASH');
    $this->rice = Shop::product($this->actor, 'Rice 5kg', 500, 1000);
    $this->milo = Shop::product($this->actor, 'Milo 400g', 500, 800);
    Shop::openingStock($this->actor, $this->rice, 10_000, 5_000, '2026-01-01');
    Shop::openingStock($this->actor, $this->milo, 10_000, 5_000, '2026-01-01');
});

it('comes back to the page that sent you to sign in', function () {
    $this->get('/expenses/new')->assertRedirect('/login');
    $this->post('/login', ['username' => 'kwame', 'password' => 'owner-password-2026'])->assertRedirect('/expenses/new');
});

it('still opens the dashboard after an ordinary sign-in', function () {
    $this->post('/login', ['username' => 'kwame', 'password' => 'owner-password-2026'])->assertRedirect('/');
});

it('adds a till line after the lines put back, not over the first', function () {
    $till = Livewire::actingAs($this->owner)->test(Pos::class)->call('add', $this->rice);
    $kept = $till->get('lines');

    // A fresh till (its line counter back at 1) with the kept cart put back.
    $fresh = Livewire::actingAs($this->owner)->test(Pos::class)->set('lines', $kept)->call('add', $this->milo);

    expect(array_column($fresh->get('lines'), 'productId'))->toBe([$this->rice, $this->milo]);
});

it('adds a quotation line after the lines put back, not over the first', function () {
    // Two more lines than a fresh form has, so a fresh counter would land on one of them.
    $form = Livewire::actingAs($this->owner)->test(QuotationForm::class)->call('addLine')->call('addLine');
    $kept = $form->get('lines');
    $count = count($kept);

    $fresh = Livewire::actingAs($this->owner)->test(QuotationForm::class)->set('lines', $kept)->call('addLine');

    expect(count($fresh->get('lines')))->toBe($count + 1);
});
