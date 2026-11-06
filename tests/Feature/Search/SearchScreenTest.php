<?php

declare(strict_types=1);

/* Search: the old search page and api/search route. Signed in is enough; each record type is searched only for those who may view it. */

use App\Livewire\Search\Index;
use App\Models\User;
use App\Services\Actor;
use Livewire\Livewire;
use Tests\Support\Shop;

beforeEach(function () {
    $this->ownerId = owner();
    $actor = new Actor($this->ownerId, 'kwame');
    Shop::product($actor, 'Milo tin 400g');
    Shop::customer($actor, 'Milo Mensah');
});

it('opens for anyone signed in', function () {
    $this->get('/search')->assertRedirect('/login');
    signedIn(staff())->get('/search')->assertOk()->assertSee('Stock items, customers, suppliers, receipts, purchases and journal entries.');
});

it('only shows the record types the person may view', function () {
    signedIn(staff(['products' => viewOnly()]))->get('/search?q=milo')->assertOk()
        ->assertSee('Milo tin 400g')->assertDontSee('Milo Mensah')->assertSee('1 match for');

    signedIn(staff(['customers' => viewOnly()], 'kofi'))->get('/search?q=milo')->assertOk()
        ->assertSee('Milo Mensah')->assertDontSee('Milo tin 400g');

    signedIn($this->ownerId)->get('/search?q=milo')->assertSee('Milo tin 400g')->assertSee('Milo Mensah')->assertSee('2 matches for');
});

it('follows the box as you type, and asks for two characters', function () {
    Livewire::actingAs(User::query()->findOrFail($this->ownerId))->test(Index::class)
        ->set('q', 'm')->assertSee('Type at least two characters')
        ->set('q', 'milo')->assertSee('Milo tin 400g')
        ->set('q', 'zzzz')->assertSee('Nothing matches “zzzz”');
});

it('answers the suggest route with the same results, for the same rights', function () {
    $this->getJson('/search/suggest?q=milo')->assertUnauthorized();
    signedIn(staff(['products' => viewOnly()]))->getJson('/search/suggest?q=milo')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('total', 1)
        ->assertJsonPath('groups.0.label', 'Stock items')
        ->assertJsonPath('groups.0.hits.0.title', 'Milo tin 400g');
});
