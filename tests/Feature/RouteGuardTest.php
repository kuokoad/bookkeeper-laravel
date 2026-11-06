<?php

declare(strict_types=1);

/*
| Replaces the old tests/app/page-guards.test.ts and action-guards.test.ts:
| every screen is behind sign-in and a permission, over the real route list,
| plus what a person is actually sent when refused.
*/

use App\Auth\Permissions;
use App\Enums\PermissionModule;
use App\Services\Actor;
use App\Services\UserService;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/** Routes that need no permission middleware, and why. */
const OPEN_ROUTES = [
    '/' => 'redirects to setup, sign-in or the dashboard',
    'login' => 'sign-in', 'login/pin' => 'till PIN sign-in', 'setup' => 'first run only (refused once any account exists)',
    'logout' => 'signing out needs no right', 'account/password' => 'your own password', 'theme' => 'light or dark on this screen',
    'dashboard' => 'every sign-in lands here; each panel asks its own permission', 'no-access' => 'explains a refusal',
    'export/list/{list}' => 'permission per list, in ExportController (401/403/404 as the old route)',
    'export/report/{report}' => 'reports permission, in ExportController',
    'up' => 'health check',
];

function appRoutes(): array
{
    return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $r): bool => ! str_starts_with($r->uri(), 'livewire') && ! str_starts_with($r->uri(), '_') && $r->uri() !== 'storage/{path}'));
}

it('puts every route outside the short open list behind sign-in, session limits and a permission', function () {
    $offenders = [];
    foreach (appRoutes() as $route) {
        if (array_key_exists($route->uri(), OPEN_ROUTES)) {
            continue;
        }
        $middleware = $route->gatherMiddleware();
        $hasPermission = collect($middleware)->contains(fn ($m): bool => is_string($m) && str_starts_with($m, 'permission:'));
        if (! in_array('auth', $middleware, true) || ! in_array('session.limits', $middleware, true) || ! in_array('password.chosen', $middleware, true) || ! $hasPermission) {
            $offenders[] = implode('|', $route->methods()).' '.$route->uri();
        }
    }

    expect($offenders)->toBe([]);
});

it('names only real modules and actions in permission middleware', function () {
    foreach (appRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'permission:')) {
                [$module, $action] = explode(',', substr($m, strlen('permission:')));
                expect(PermissionModule::tryFrom($module))->not->toBeNull("{$route->uri()}: {$module}")
                    ->and(Permissions::ACTIONS)->toContain($action);
            }
        }
    }
});

it('sends somebody not signed in to sign in, from every screen', function (string $uri) {
    $this->get($uri)->assertRedirect('/login');
})->with(['/dashboard', '/settings', '/no-access', '/account/password']);

it('sends a staff member without the right to the no-access page, and refuses an action with 403', function () {
    $id = staff(['sales' => viewOnly()]);
    signedIn($id)->get('/settings')->assertRedirect('/no-access?area=settings');
    signedIn($id)->getJson('/settings')->assertForbidden();
    signedIn($id)->get('/no-access?area=settings')->assertOk()->assertSee('Your account is not set up to open settings.');
});

it('opens a screen for somebody who holds its right', function () {
    signedIn(staff(['settings' => viewOnly()]))->get('/settings')->assertOk()->assertSee('Settings');
    signedIn(owner())->get('/settings')->assertOk();
});

it('refuses a switched-off account at once, even mid-session', function () {
    $ownerId = owner();
    $id = staff(['settings' => viewOnly()]);
    signedIn($id)->get('/settings')->assertOk();
    UserService::setUserActive($id, false, new Actor($ownerId, 'kwame'));
    // signedIn() again only reloads the user row, as every real request does; the session is the same kind.
    signedIn($id)->get('/settings')->assertRedirect('/login');
    $this->assertGuest();
});

it('refuses a switched-off account at sign-in with the same words as a wrong password', function () {
    $ownerId = owner();
    $id = staff([], 'ama', 'staff-password-2026');
    UserService::setUserActive($id, false, new Actor($ownerId, 'kwame'));
    $this->post('/login', ['username' => 'ama', 'password' => 'staff-password-2026'])->assertRedirect('/login')->assertSessionHasErrors(['form' => 'Incorrect username or password.']);
    $this->assertGuest();
});

it('sends somebody whose password was set by someone else to choose their own first', function () {
    $id = staff(['settings' => viewOnly()]);
    DB::table('users')->where('id', $id)->update(['must_change_password' => true]);
    signedIn($id)->get('/settings')->assertRedirect('/account/password?required=1');
    signedIn($id)->get('/dashboard')->assertRedirect('/account/password?required=1');
    signedIn($id)->get('/account/password')->assertOk()->assertSee('Please choose your own password');
});
