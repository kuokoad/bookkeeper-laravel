<?php

declare(strict_types=1);

/* The Health & backup screen: the old settings/health page, api/backup and api/update. */

use App\Auth\Permissions;
use App\Livewire\Settings\Health;
use App\Models\User;
use Livewire\Livewire;

it('shows the readiness checks and the backup status to somebody with the settings right', function () {
    $this->get('/settings/health')->assertRedirect('/login');
    signedIn(staff(['sales' => viewOnly()]))->get('/settings/health')->assertRedirect('/no-access?area=settings');

    signedIn(staff(['settings' => viewOnly()], 'kofi'))->get('/settings/health')->assertOk()
        ->assertSee('Back up your records')
        ->assertSee('No backup has ever been taken.')
        ->assertSee('Checks passed')
        ->assertSee('Only the shop owner can take a backup — it contains every customer and every figure.')
        ->assertDontSee('Download a backup');

    signedIn(owner())->get('/settings/health')->assertOk()->assertSee('Download a backup')->assertSee('Update the app');
});

it('refuses a backup download without the edit right', function () {
    signedIn(staff(['settings' => viewOnly()]))->post('/settings/backup')->assertForbidden();
});

it('lets only the owner download a backup, whatever settings rights a staff member holds (D31)', function () {
    signedIn(staff(['settings' => Permissions::FULL_ACCESS]))->post('/settings/backup')->assertForbidden();
    signedIn(staff(['settings' => Permissions::FULL_ACCESS], 'kofi'))->get('/settings/health')->assertOk()
        ->assertSee('Only the shop owner can take a backup')
        ->assertDontSee('Download a backup');
});

it('lets only the owner check for updates, whatever settings rights a staff member holds', function () {
    $staff = staff(['settings' => Permissions::FULL_ACCESS]);
    Livewire::actingAs(User::query()->findOrFail($staff))->test(Health::class)
        ->call('checkForUpdates')->assertSet('checkError', 'Only the shop owner can update the app.')
        ->call('startUpdate')->assertSet('startError', 'Only the shop owner can update the app.');

    Livewire::actingAs(User::query()->findOrFail(staff(['settings' => viewOnly()], 'kofi')))->test(Health::class)
        ->call('checkForUpdates')->assertForbidden();
});

it('tells the owner updates run only on the shop computer outside production', function () {
    Livewire::actingAs(User::query()->findOrFail(owner()))->test(Health::class)
        ->assertSee('Updates run only on the shop computer, not in development.')
        ->call('startUpdate')->assertSet('startError', 'Updates run only on the shop computer, not in development.')
        ->assertSet('started', false);
});

it('serves the update status only to somebody who may update', function () {
    signedIn(staff(['settings' => viewOnly()]))->getJson('/settings/update')->assertForbidden();
    signedIn(owner())->getJson('/settings/update')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});
