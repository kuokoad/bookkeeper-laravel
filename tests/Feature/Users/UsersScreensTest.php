<?php

declare(strict_types=1);

/* The users list, add-a-user, one person's account and the audit log: the old users pages and user.actions. */

use App\Auth\Permissions;
use App\Auth\Principal;
use App\Livewire\Users\Audit;
use App\Livewire\Users\Create;
use App\Livewire\Users\Show;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function usersAs(int $userId): mixed
{
    return Livewire::actingAs(User::query()->findOrFail($userId));
}

it('lists people for somebody with the users right and refuses everyone else', function () {
    $ownerId = owner();
    $this->get('/users')->assertRedirect('/login');
    signedIn(staff(['sales' => viewOnly()]))->get('/users')->assertRedirect('/no-access?area=users');
    signedIn(staff(['users' => viewOnly()], 'kofi'))->get('/users')->assertOk()
        ->assertSee('Who can sign in, and what each person is allowed to do.')
        ->assertSee('There is only one owner account.')
        ->assertSee('Kwame')->assertDontSee('Add a user');
    signedIn($ownerId)->get('/users')->assertOk()->assertSee('Add a user');
});

it('guards each user screen with its own right', function () {
    $viewer = staff(['users' => viewOnly()]);
    signedIn($viewer)->get('/users/new')->assertRedirect('/no-access?area=users');
    signedIn($viewer)->get('/users/audit')->assertOk()->assertSee('Everything that has happened, who did it, and when.');
    signedIn($viewer)->get("/users/{$viewer}")->assertOk()->assertSee('You can see this account but not change it.');
    signedIn($viewer)->get('/users/999999')->assertNotFound();
});

it('creates a staff account with exactly the ticked rights, who must set their own password', function () {
    usersAs(owner())->test(Create::class)
        ->set('displayName', 'Esi Mensah')->set('username', 'esi')->set('password', 'start-here-2026')
        ->call('applyPreset', 'till')
        ->set('perms.reports.canEdit', true)
        ->call('save')->assertHasNoErrors();

    $esi = DB::table('users')->where('username', 'esi')->first();
    expect($esi->role)->toBe('STAFF')->and((bool) $esi->must_change_password)->toBeTrue();
    expect(Principal::permissionMap((int) $esi->id))->toMatchArray([
        'sales' => ['canView' => true, 'canCreate' => true, 'canEdit' => false, 'canVoid' => false],
        // Ticking Change ticked See with it.
        'reports' => ['canView' => true, 'canCreate' => false, 'canEdit' => true, 'canVoid' => false],
    ]);
});

it('shows the old messages for an account it cannot create', function () {
    owner();
    usersAs(owner('abena'))->test(Create::class)
        ->set('displayName', 'E')->set('username', 'e!')->set('password', 'short')
        ->call('save')
        ->assertHasErrors(['displayName' => 'Enter the person’s name.', 'username' => 'Username must be at least 3 characters.', 'password' => 'Password must be at least 8 characters.']);

    usersAs(owner('yaw'))->test(Create::class)
        ->set('displayName', 'Kwame again')->set('username', 'KWAME')->set('password', 'long-enough-1')
        ->call('save')->assertHasErrors(['form' => 'That username is already taken.']);
});

it('refuses add-a-user without the create right', function () {
    usersAs(staff(['users' => viewOnly()]))->test(Create::class)
        ->set('displayName', 'Esi')->set('username', 'esi')->set('password', 'start-here-2026')
        ->call('save')->assertForbidden();
    expect(DB::table('users')->where('username', 'esi')->exists())->toBeFalse();
});

it('lets a non-owner pass on only what they hold, and never make an owner', function () {
    owner();
    $manager = staff(['users' => Permissions::FULL_ACCESS, 'sales' => viewOnly()], 'adjoa');

    $c = usersAs($manager)->test(Create::class)->set('displayName', 'Esi')->set('username', 'esi')->set('password', 'start-here-2026');
    // The till preset is trimmed to what Adjoa holds: sales view only.
    $c->call('applyPreset', 'till')->assertSet('perms.sales', ['canView' => true, 'canCreate' => false, 'canEdit' => false, 'canVoid' => false])
        ->assertSet('perms.products.canView', false);
    $c->set('perms.products.canView', true)->call('save')->assertHasErrors(['form' => 'You do not have permission to do that.']);

    usersAs($manager)->test(Create::class)->set('displayName', 'Esi')->set('username', 'esi')->set('password', 'start-here-2026')
        ->set('role', 'OWNER')->call('save')->assertHasErrors(['form' => 'You do not have permission to do that.']);
    expect(DB::table('users')->where('username', 'esi')->exists())->toBeFalse();
});

it('refuses a staff member taking over the owner by resetting their password or PIN (D14)', function () {
    $ownerId = owner();
    $manager = staff(['users' => Permissions::FULL_ACCESS]);
    $hash = DB::table('users')->where('id', $ownerId)->value('password_hash');

    usersAs($manager)->test(Show::class, ['user' => $ownerId])
        ->set('newPassword', 'i-am-the-owner-now')->call('resetPassword')
        ->assertHasErrors(['password' => 'You do not have permission to do that.'])
        ->set('pin', '8351')->call('savePin')
        ->assertHasErrors(['pinForm' => 'You do not have permission to do that.']);

    expect(DB::table('users')->where('id', $ownerId)->value('password_hash'))->toBe($hash)
        ->and(DB::table('users')->where('id', $ownerId)->value('pin_hash'))->toBeNull();
});

it('offers the password reset and PIN only where they would be allowed, not on the owner or a better-placed colleague (D14)', function () {
    $ownerId = owner();
    $manager = staff(['users' => Permissions::FULL_ACCESS, 'sales' => viewOnly()]);
    $cashier = staff(['sales' => viewOnly()], 'kofi');
    $accountant = staff(['accounts' => Permissions::FULL_ACCESS], 'esi');

    // Owner's page, and a colleague holding a right the manager lacks: both would be refused, so neither is offered.
    foreach ([$ownerId, $accountant] as $target) {
        usersAs($manager)->test(Show::class, ['user' => $target])
            ->assertDontSee('Reset their password')->assertDontSee('Save PIN');
    }
    usersAs($manager)->test(Show::class, ['user' => $cashier])->assertSee('Reset their password')->assertSee('Save PIN');
    usersAs($ownerId)->test(Show::class, ['user' => $accountant])->assertSee('Reset their password')->assertSee('Save PIN');
});

it('resets a staff password and sets a PIN through UserService', function () {
    $ownerId = owner();
    $ama = staff(['sales' => viewOnly()]);

    usersAs($ownerId)->test(Show::class, ['user' => $ama])
        ->set('newPassword', 'short')->call('resetPassword')->assertHasErrors(['newPassword' => 'Password must be at least 8 characters.'])
        ->set('newPassword', 'fresh-start-2026')->call('resetPassword')->assertHasNoErrors()
        ->assertSet('status', 'Password reset. They were signed out and must choose a new password next time.')
        ->set('pin', '12')->call('savePin')->assertHasErrors('pin')
        ->set('pin', '8351')->call('savePin')->assertHasNoErrors()->assertSet('status', 'Till PIN updated.');

    $row = DB::table('users')->where('id', $ama)->first();
    expect((bool) $row->must_change_password)->toBeTrue()->and($row->pin_hash)->not->toBeNull();
});

it('offers no till PIN for an owner account, on adding one or on its page (D17)', function () {
    $ownerId = owner();
    $abena = owner('abena');

    usersAs($ownerId)->test(Show::class, ['user' => $abena])->assertDontSee('Save PIN')->assertSee('Not used: owners sign in with their password');
    usersAs($ownerId)->test(Create::class)->assertSee('Till PIN (optional)')
        ->set('role', 'OWNER')->assertDontSee('Till PIN (optional)')
        // A PIN typed before switching to Owner is dropped, not refused.
        ->set(['displayName' => 'Kofi', 'username' => 'kofi', 'password' => 'start-password-1', 'pin' => '8351'])->call('save')->assertHasNoErrors();
    expect(DB::table('users')->where('username', 'kofi')->value('pin_hash'))->toBeNull();
});

it('shows the guards that stop the shop locking itself out', function () {
    $ownerId = owner();
    usersAs($ownerId)->test(Show::class, ['user' => $ownerId])
        ->assertSee('You cannot switch off your own account.')
        ->call('setActive', false)->assertHasErrors(['access' => 'You cannot switch off your own account.'])
        ->set('selectedRole', 'STAFF')->call('saveDetails')
        ->assertHasErrors(['details' => 'You cannot change your own role. Another owner has to do that for you.']);
    expect(DB::table('users')->where('id', $ownerId)->value('role'))->toBe('OWNER');
});

it('switches a staff account off and saves their rights, and refuses both without the edit right', function () {
    $ownerId = owner();
    $ama = staff(['sales' => viewOnly()]);
    usersAs(staff(['users' => viewOnly()], 'kofi'))->test(Show::class, ['user' => $ama])->call('setActive', false)->assertForbidden();

    usersAs($ownerId)->test(Show::class, ['user' => $ama])
        ->set('perms.inventory.canView', true)->call('savePermissions')->assertHasNoErrors()
        ->call('setActive', false)->assertHasNoErrors();

    expect((bool) DB::table('users')->where('id', $ama)->value('is_active'))->toBeFalse()
        ->and(Principal::permissionMap($ama))->toHaveKey('inventory');
});

it('narrows the audit log by action and person, and shows the resolved filters', function () {
    $ownerId = owner();
    staff(['sales' => viewOnly()]);
    $viewer = usersAs($ownerId);

    $viewer->withQueryParams(['action' => 'CREATE'])->test(Audit::class)
        ->assertSee('Created staff account')->assertViewHas('total', DB::table('audit_logs')->where('action', 'CREATE')->count());
    usersAs($ownerId)->withQueryParams(['user' => (string) $ownerId])->test(Audit::class)
        ->assertViewHas('total', DB::table('audit_logs')->where('user_id', $ownerId)->count());
    usersAs($ownerId)->withQueryParams(['action' => 'NOT_AN_ACTION', 'user' => 'junk', 'entity' => 'nonsense'])->test(Audit::class)
        ->assertViewHas('resolved', [])->assertViewHas('total', DB::table('audit_logs')->count());
    usersAs($ownerId)->withQueryParams(['q' => '%'])->test(Audit::class)->assertViewHas('total', 0);
});

it('shows "Account created" once, and clears it on the next action on the page', function () {
    $ownerId = owner();
    $ama = staff(['sales' => viewOnly()]);
    $created = 'Account created. Give them the starting password';

    // Shown by the account page itself, not twice by the shell as well.
    $page = signedIn($ownerId)->withSession(['status' => "{$created} — they will be asked to choose their own the first time they sign in."])
        ->get("/users/{$ama}")->assertOk();
    // (The page's own copy rides in its Livewire snapshot too, which is not on screen.)
    expect(substr_count(preg_replace('/wire:snapshot="[^"]*"/', '', $page->getContent()), $created))->toBe(1);

    session()->flash('status', "{$created}.");
    usersAs($ownerId)->test(Show::class, ['user' => $ama])->assertSee($created)
        ->call('setActive', false)->assertHasNoErrors()->assertDontSee($created);
});
