<?php

declare(strict_types=1);

/*
| The old session rules (tests/services/auth.test.ts "sessions" and "how long
| a session lives") on Laravel's session machinery, and the permission gate
| every route will use (the old requirePermission / requirePageAccess).
| Routes are defined here for the test only; the real ones are Phase 9.
*/

use App\Auth\Permissions;
use App\Auth\Sessions;
use App\Enums\PermissionModule;
use App\Enums\UserRole;
use App\Livewire\Inventory\Batch;
use App\Models\User;
use App\Services\Actor;
use App\Services\AuthService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'session.limits'])->get('/_test/home', fn () => 'home');
    Route::middleware(['web', 'session.limits', 'permission:sales,create'])->match(['get', 'post'], '/_test/till', fn () => 'till');
    Route::middleware('web')->post('/_test/sign-in', function (Request $request) {
        $result = AuthService::login((string) $request->input('username'), (string) $request->input('password'));
        abort_unless($result['ok'], 422);
        Sessions::begin($request, $result['user']);

        return ['at' => $request->session()->get(Sessions::SIGNED_IN_AT), 'fingerprint' => $request->session()->get(Sessions::FINGERPRINT)];
    });
    Route::get('/login', fn () => 'sign in')->name('login');

    $this->ownerId = AuthService::createUser(['username' => 'kwame', 'displayName' => 'Kwame', 'password' => 'owner-password-2026', 'role' => UserRole::Owner]);
    $this->staffId = AuthService::createUser(['username' => 'ama', 'displayName' => 'Ama', 'password' => 'staff-password-2026', 'role' => UserRole::Staff, 'permissions' => Permissions::defaultStaffPermissions()]);
    $this->owner = new Actor($this->ownerId, 'kwame');
});

afterEach(fn () => Carbon::setTestNow());

/** A request from somebody who signed in at $at. */
function signedInAs(int $userId, Carbon $at): mixed
{
    return test()->actingAs(User::query()->findOrFail($userId))->withSession([
        Sessions::SIGNED_IN_AT => $at->getTimestampMs(),
        Sessions::FINGERPRINT => Sessions::fingerprint($userId),
    ]);
}

it('starts a session that records when it began and what it was granted on', function () {
    $response = $this->post('/_test/sign-in', ['username' => 'ama', 'password' => 'staff-password-2026']);
    $response->assertOk();
    expect($response->json('at'))->toBeInt()
        ->and($response->json('fingerprint'))->toBe(Sessions::fingerprint($this->staffId));
});

it('keeps working while the person keeps working, and ends at the 30-day ceiling', function () {
    $start = Carbon::parse('2026-11-01 08:00:00', 'UTC');
    Carbon::setTestNow($start->copy()->addDays(29));
    signedInAs($this->staffId, $start)->get('/_test/home')->assertOk()->assertSee('home');

    Carbon::setTestNow($start->copy()->addDays(Sessions::ABSOLUTE_MAX_DAYS)->addSecond());
    signedInAs($this->staffId, $start)->get('/_test/home')->assertRedirect('/login');
});

it('stops working the moment the account is switched off', function () {
    $now = Carbon::now();
    UserService::setUserActive($this->staffId, false, $this->owner);
    signedInAs($this->staffId, $now)->get('/_test/home')->assertRedirect('/login');
});

it('ends a session opened before a password reset, a permission change or a role change', function (Closure $change) {
    $session = [Sessions::SIGNED_IN_AT => Carbon::now()->getTimestampMs(), Sessions::FINGERPRINT => Sessions::fingerprint($this->staffId)];
    $change($this);
    $this->actingAs(User::query()->findOrFail($this->staffId))->withSession($session)->get('/_test/home')->assertRedirect('/login');
})->with([
    'password reset' => [fn ($t) => UserService::resetUserPassword($t->staffId, 'temporary-password-1', $t->owner)],
    'permissions' => [fn ($t) => UserService::setUserPermissions($t->staffId, ['sales' => Permissions::FULL_ACCESS], $t->owner)],
    'role' => [fn ($t) => UserService::updateUser($t->staffId, ['displayName' => 'Ama', 'role' => UserRole::Owner], $t->owner)],
]);

it('does not end a session for a change that grants nothing new, such as a PIN', function () {
    $session = [Sessions::SIGNED_IN_AT => Carbon::now()->getTimestampMs(), Sessions::FINGERPRINT => Sessions::fingerprint($this->staffId)];
    UserService::setUserPin($this->staffId, '8351', $this->owner);
    $this->actingAs(User::query()->findOrFail($this->staffId))->withSession($session)->get('/_test/home')->assertOk();
});

it('refuses a session with no record of when it began', function () {
    $this->actingAs(User::query()->findOrFail($this->staffId))->get('/_test/home')->assertRedirect('/login');
});

describe('the permission gate', function () {
    it('defines one ability per module and action', function () {
        foreach (PermissionModule::cases() as $module) {
            foreach (Permissions::ACTIONS as $action) {
                expect(Gate::has("{$module->value}.{$action}"))->toBeTrue();
            }
        }
    });

    it('lets a permitted person through and turns the rest away, a page to /no-access and an action with 403', function () {
        signedInAs($this->staffId, Carbon::now())->post('/_test/till')->assertOk();
        DB::table('user_permissions')->where('user_id', $this->staffId)->where('module', 'sales')->update(['can_create' => false]);
        signedInAs($this->staffId, Carbon::now())->post('/_test/till')->assertForbidden();
        signedInAs($this->staffId, Carbon::now())->get('/_test/till')->assertRedirect('/no-access?area=sales');
    });

    it('sends somebody not signed in to sign in', function () {
        $this->get('/_test/till')->assertRedirect('/login');
        $this->postJson('/_test/till')->assertUnauthorized();
    });

    it('refuses to act for somebody who has not chosen their own password yet', function () {
        UserService::resetUserPassword($this->staffId, 'temporary-password-1', $this->owner);
        $user = User::query()->findOrFail($this->staffId);
        expect(Gate::forUser($user)->allows('sales.create'))->toBeFalse()
            ->and(Gate::forUser(User::query()->findOrFail($this->ownerId))->allows('settings.edit'))->toBeTrue();
    });

    it('reads rights from the database, not from what the session was given', function () {
        $user = User::query()->findOrFail($this->staffId);
        expect(Gate::forUser($user)->allows('reports.view'))->toBeFalse();
        DB::table('user_permissions')->insert(['user_id' => $this->staffId, 'module' => 'reports', 'can_view' => true]);
        expect(Gate::forUser($user)->allows('reports.view'))->toBeTrue();
    });
});

it('still answers a request with a redirect after a Livewire page whose mount aborted', function () {
    // Livewire's own Redirector stayed bound after the aborted mount, and this middleware returned it: a TypeError, a 500.
    $id = $this->ownerId;
    Livewire\Livewire::actingAs(User::find($id))->test(Batch::class, ['id' => '999999'])->assertNotFound();

    $this->get('/inventory/batches/999999')->assertRedirect('/login');
    signedIn($id)->get('/inventory/batches/999999')->assertNotFound();
});
