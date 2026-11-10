<?php

declare(strict_types=1);

use App\Auth\Sessions;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuthService;
use App\Support\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Tests run against a real MySQL database (nunabooks_test_run_<pid>, one per run,
| see TestCase::ownDatabase),
| never SQLite: the point is to test MySQL's own behaviour — CHECKs,
| triggers, collation and locking.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Database', 'Feature', 'Services', 'Invariants');

pest()->extend(TestCase::class)->in('Domain');

// These run real processes that commit, so they use their own databases and no RefreshDatabase.
pest()->extend(TestCase::class)->in('Processes');

/**
 * A person with a real account and a live signed-in session, for screen
 * tests: `signedIn(staff(['sales' => Permissions::FULL_ACCESS]))->get('/sales')`.
 * The session carries what EnforceSessionLimits checks, as Sessions::begin
 * would write it. Livewire::test() after this runs as that person too.
 */
function signedIn(int $userId): mixed
{
    return test()->actingAs(User::query()->findOrFail($userId))->withSession([
        Sessions::SIGNED_IN_AT => Clock::now()->getTimestampMs(),
        Sessions::FINGERPRINT => Sessions::fingerprint($userId),
    ]);
}

/** An owner account (every right), through the real AuthService. */
function owner(string $username = 'kwame', string $password = 'owner-password-2026'): int
{
    return AuthService::createUser(['username' => $username, 'displayName' => ucfirst($username), 'password' => $password, 'role' => UserRole::Owner]);
}

/**
 * A staff account holding exactly these rights.
 *
 * @param  array<string, array{canView: bool, canCreate: bool, canEdit: bool, canVoid: bool}>  $permissions
 */
function staff(array $permissions = [], string $username = 'ama', string $password = 'staff-password-2026', ?string $pin = null): int
{
    return AuthService::createUser(['username' => $username, 'displayName' => ucfirst($username), 'password' => $password, 'role' => UserRole::Staff, 'permissions' => $permissions, 'pin' => $pin]);
}

/** Only `view` on a module: the commonest staff right in these tests. */
function viewOnly(): array
{
    return ['canView' => true, 'canCreate' => false, 'canEdit' => false, 'canVoid' => false];
}
