<?php

declare(strict_types=1);

/*
| Port of the old tests/services/auth.test.ts. Sessions are Laravel's now
| (PLAN Q7): the token cases become the session limits (App\Auth\Sessions,
| tested through the middleware in SessionLimitsTest). New here: the legacy
| scrypt hash is replaced on the first successful sign-in, one person at a
| time, and the demo users sign in.
*/

use App\Auth\Passwords;
use App\Auth\Permissions;
use App\Auth\Principal;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\ValidationError;
use App\Enums\PermissionModule;
use App\Enums\UserRole;
use App\Services\Actor;
use App\Services\AuthService;
use App\Support\Clock;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyHashes;

const OWNER_INPUT = ['username' => 'kwame', 'displayName' => 'Kwame Owusu', 'password' => 'adom-provisions-2026'];
const STAFF_INPUT = ['username' => 'ama', 'displayName' => 'Ama Serwaa', 'password' => 'counter-staff-2026'];

function createOwner(array $changes = []): int
{
    return AuthService::createUser([...OWNER_INPUT, 'role' => UserRole::Owner, ...$changes]);
}

function createStaff(array $changes = []): int
{
    return AuthService::createUser([...STAFF_INPUT, 'role' => UserRole::Staff, 'permissions' => Permissions::defaultStaffPermissions(), ...$changes]);
}

function userRow(string $username): object
{
    return DB::table('users')->where('username', $username)->first();
}

function signIn(string $username, string $password, ?Carbon $now = null): array
{
    return AuthService::login($username, $password, now: $now);
}

describe('initial setup', function () {
    it('reports that setup is needed on an empty database, and not once an account exists', function () {
        expect(AuthService::needsInitialSetup())->toBeTrue();
        createOwner();
        expect(AuthService::needsInitialSetup())->toBeFalse();
    });
});

describe('createUser', function () {
    it('creates an owner and never stores the password', function () {
        $user = DB::table('users')->find(createOwner());
        expect($user->role)->toBe('OWNER')
            ->and((bool) $user->is_active)->toBeTrue()
            ->and($user->password_hash)->not->toContain(OWNER_INPUT['password'])
            ->and($user->password_hash)->toStartWith('$argon2id$');
    });

    it('rejects a duplicate username regardless of case', function () {
        createOwner();
        expect(fn () => createOwner(['username' => 'KWAME']))->toThrow(ConflictError::class);
    });

    it('rejects an invalid username or a weak password', function () {
        expect(fn () => createOwner(['username' => 'a b']))->toThrow(ValidationError::class)
            ->and(fn () => createOwner(['password' => 'short']))->toThrow(ValidationError::class);
    });

    it('stores permissions for staff', function () {
        $id = createStaff();
        $result = signIn('ama', STAFF_INPUT['password']);
        expect($result['ok'])->toBeTrue()
            ->and($result['principal']->id)->toBe($id)
            ->and($result['principal']->permissions['sales']['canCreate'])->toBeTrue()
            ->and($result['principal']->permissions)->not->toHaveKey('settings');
    });

    it('rolls back completely if permission insertion fails', function () {
        // An unknown module breaks the CHECK on `module` after the user row went in.
        expect(fn () => createStaff(['permissions' => ['nonsense' => Permissions::FULL_ACCESS]]))->toThrow(QueryException::class)
            ->and(DB::table('users')->count())->toBe(0);
    });
});

describe('login', function () {
    beforeEach(fn () => createOwner());

    it('succeeds with correct credentials', function () {
        $result = signIn('kwame', OWNER_INPUT['password']);
        expect($result['ok'])->toBeTrue()
            ->and($result['principal']->role)->toBe(UserRole::Owner)
            ->and($result['user']->username)->toBe('kwame');
    });

    it('is case-insensitive on the username', function () {
        expect(signIn('KWAME', OWNER_INPUT['password'])['ok'])->toBeTrue()
            ->and(signIn('  kwame ', OWNER_INPUT['password'])['ok'])->toBeTrue();
    });

    it('fails on a wrong password, and on an unknown username with the same reason', function () {
        expect(signIn('kwame', 'wrong-one'))->toBe(['ok' => false, 'reason' => 'INVALID_CREDENTIALS'])
            ->and(signIn('ghost', 'anything-at-all'))->toBe(['ok' => false, 'reason' => 'INVALID_CREDENTIALS']);
    });

    it('locks a staff account after repeated failures and releases it after the window', function () {
        createStaff();
        $now = Clock::now();
        for ($i = 0; $i < AuthService::MAX_FAILED_LOGINS; $i++) {
            signIn('ama', 'wrong', $now);
        }
        $locked = signIn('ama', STAFF_INPUT['password'], $now);
        expect($locked['ok'])->toBeFalse()
            ->and($locked['reason'])->toBe('ACCOUNT_LOCKED')
            ->and(Clock::stamp($locked['lockedUntil']))->toBe(Clock::stamp($now->copy()->addMinutes(15)))
            ->and(signIn('ama', STAFF_INPUT['password'], $now->copy()->addMinutes(16))['ok'])->toBeTrue();
    });

    it('resets the failure count after a successful sign-in', function () {
        signIn('kwame', 'wrong');
        signIn('kwame', OWNER_INPUT['password']);
        $user = userRow('kwame');
        expect((int) $user->failed_login_count)->toBe(0)
            ->and($user->locked_until)->toBeNull()
            ->and($user->last_login_at)->not->toBeNull();
    });

    it('refuses a deactivated account even with the right password', function () {
        DB::table('users')->where('username', 'kwame')->update(['is_active' => false]);
        expect(signIn('kwame', OWNER_INPUT['password']))->toBe(['ok' => false, 'reason' => 'ACCOUNT_INACTIVE']);
    });

    it('writes audit records for success and failure without the password', function () {
        signIn('kwame', 'wrong-password-here', null);
        AuthService::login('kwame', OWNER_INPUT['password'], '192.168.1.20', 'Till 1');
        $entries = DB::table('audit_logs')->get();
        $json = json_encode($entries);
        expect($entries->pluck('action')->all())->toContain('LOGIN_FAILED', 'LOGIN_SUCCESS')
            ->and($json)->not->toContain('wrong-password-here')
            ->and($json)->not->toContain(OWNER_INPUT['password'])
            ->and($entries->firstWhere('action', 'LOGIN_SUCCESS')->ip_address)->toBe('192.168.1.20');
    });

    it('records the typed name of an unknown account, cut to the column', function () {
        signIn(str_repeat('g', 60), 'anything-at-all');
        $row = DB::table('audit_logs')->where('action', 'LOGIN_FAILED')->first();
        expect($row->user_id)->toBeNull()
            ->and($row->username)->toBe(str_repeat('g', 40))
            ->and($row->summary)->toContain(str_repeat('g', 60));
    });

    it('refuses a name that cannot exist without looking it up, and buckets every such name together', function () {
        DB::enableQueryLog();
        expect(signIn('kwame ; --', OWNER_INPUT['password']))->toBe(['ok' => false, 'reason' => 'INVALID_CREDENTIALS'])
            ->and(AuthService::loginWithPin('k', '8351'))->toBe(['ok' => false, 'reason' => 'INVALID_CREDENTIALS']);
        $lookups = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'LOWER(`users`.`username`)'));
        expect($lookups)->toBe([])
            ->and(AuthService::throttleKeyFor('a b'))->toBe('login:user:invalid')
            ->and(AuthService::throttleKeyFor(str_repeat('g', 41)))->toBe('login:user:invalid')
            // A valid name folds as the lookup does: trimmed, any case.
            ->and(AuthService::throttleKeyFor(" Kwame.O_1\u{00A0}"))->toBe('login:user:kwame.o_1')
            ->and(AuthService::findByUsername('KWAME')->username)->toBe('kwame');
    });
});

describe('after a lockout has been served', function () {
    beforeEach(function () {
        createStaff();
        $this->start = Clock::now();
        for ($i = 0; $i < AuthService::MAX_FAILED_LOGINS; $i++) {
            signIn('ama', 'wrong', $this->start);
        }
        $this->after = $this->start->copy()->addMinutes(16);
    });

    it('gives back a full set of attempts, not one', function () {
        signIn('ama', 'wrong-again', $this->after);
        $user = userRow('ama');
        expect((int) $user->failed_login_count)->toBe(1)->and($user->locked_until)->toBeNull();
    });

    it('still lets the right password through after a slip', function () {
        signIn('ama', 'wrong-again', $this->after);
        expect(signIn('ama', STAFF_INPUT['password'], $this->after)['ok'])->toBeTrue();
    });

    it('still locks again after another full run of failures', function () {
        for ($i = 0; $i < AuthService::MAX_FAILED_LOGINS; $i++) {
            signIn('ama', 'wrong', $this->after);
        }
        expect(signIn('ama', STAFF_INPUT['password'], $this->after)['reason'])->toBe('ACCOUNT_LOCKED');
    });
});

describe('an owner is slowed down, never locked out (D16)', function () {
    beforeEach(function () {
        createOwner();
        $this->t = Carbon::parse('2026-11-06 09:00:00', 'UTC');
    });

    it('waits 1 s at the fifth failure in a row, doubling after, never more than 60 s', function () {
        expect(array_map(AuthService::ownerDelaySeconds(...), [1, 4, 5, 6, 7, 10, 11, 500]))->toBe([0, 0, 1, 2, 4, 32, 60, 60]);
    });

    it('refuses even the right password until the delay is over, then lets it through', function () {
        for ($i = 0; $i < AuthService::MAX_FAILED_LOGINS; $i++) {
            signIn('kwame', 'wrong', $this->t);
        }
        signIn('kwame', 'wrong', $this->t->copy()->addSecond());
        expect(signIn('kwame', OWNER_INPUT['password'], $this->t->copy()->addSecond()))->toBe(['ok' => false, 'reason' => 'TOO_MANY_ATTEMPTS', 'retryAfterSeconds' => 2])
            ->and(signIn('kwame', OWNER_INPUT['password'], $this->t->copy()->addSeconds(3))['ok'])->toBeTrue();
    });

    it('never locks the owner for longer than a minute, however many guesses', function () {
        $at = $this->t->copy();
        for ($i = 0; $i < 20; $i++) {
            $at = $at->copy()->addSeconds(AuthService::OWNER_DELAY_CAP_SECONDS);
            expect(signIn('kwame', 'wrong', $at)['reason'])->toBe('INVALID_CREDENTIALS');
        }
        expect((int) userRow('kwame')->failed_login_count)->toBe(20)
            ->and(signIn('kwame', OWNER_INPUT['password'], $at->copy()->addSeconds(59))['reason'])->toBe('TOO_MANY_ATTEMPTS')
            ->and(signIn('kwame', OWNER_INPUT['password'], $at->copy()->addSeconds(60))['ok'])->toBeTrue();
    });

    it('waits no more than the cap on a lock taken before this rule', function () {
        DB::table('users')->where('username', 'kwame')->update(['failed_login_count' => 5, 'locked_until' => Clock::stamp($this->t->copy()->addMinutes(15))]);
        expect(signIn('kwame', OWNER_INPUT['password'], $this->t)['retryAfterSeconds'])->toBe(60);
    });

    it('tells the owner how many failed attempts there were since they last signed in, once', function () {
        foreach (range(1, 3) as $_) {
            signIn('kwame', 'wrong', $this->t);
        }
        expect(signIn('kwame', OWNER_INPUT['password'], $this->t)['failedAttemptsSinceLastSignIn'])->toBe(3)
            ->and((int) userRow('kwame')->failed_login_count)->toBe(0)
            ->and(signIn('kwame', OWNER_INPUT['password'], $this->t)['failedAttemptsSinceLastSignIn'])->toBe(0);
    });

    it('checks only one of two guesses sent together when the delay ends', function () {
        for ($i = 0; $i < AuthService::MAX_FAILED_LOGINS; $i++) {
            signIn('kwame', 'wrong', $this->t);
        }
        $after = $this->t->copy()->addSeconds(2);
        // The second guess arrives just after the first has read the account, before it is checked.
        $second = null;
        DB::listen(function ($query) use (&$second, $after): void {
            if ($second === null && str_contains($query->sql, 'LOWER(`users`.`username`)')) {
                $second = false;
                $second = signIn('kwame', 'guess-two', $after);
            }
        });

        $first = signIn('kwame', 'guess-one', $after);

        expect([$first['reason'], $second['reason']])->toEqualCanonicalizing(['INVALID_CREDENTIALS', 'TOO_MANY_ATTEMPTS'])
            ->and((int) userRow('kwame')->failed_login_count)->toBe(AuthService::MAX_FAILED_LOGINS + 1);
    });

    it('still refuses a switched-off owner with the right password', function () {
        for ($i = 0; $i < AuthService::MAX_FAILED_LOGINS; $i++) {
            signIn('kwame', 'wrong', $this->t);
        }
        DB::table('users')->where('username', 'kwame')->update(['is_active' => false]);
        expect(signIn('kwame', OWNER_INPUT['password'], $this->t)['reason'])->toBe('TOO_MANY_ATTEMPTS')
            ->and(signIn('kwame', OWNER_INPUT['password'], $this->t->copy()->addMinute()))->toBe(['ok' => false, 'reason' => 'ACCOUNT_INACTIVE']);
    });

    it('leaves the notice out for staff', function () {
        createStaff();
        signIn('ama', 'wrong', $this->t);
        expect(signIn('ama', STAFF_INPUT['password'], $this->t)['failedAttemptsSinceLastSignIn'])->toBe(0);
    });
});

describe('an owner and a till PIN (D17)', function () {
    it('refuses PIN sign-in for an owner who still holds an old PIN, as a wrong PIN, and counts it', function () {
        createOwner();
        DB::table('users')->where('username', 'kwame')->update(['pin_hash' => Passwords::hashPin('8351')]);
        expect(AuthService::loginWithPin('kwame', '8351'))->toBe(['ok' => false, 'reason' => 'INVALID_CREDENTIALS'])
            ->and((int) userRow('kwame')->failed_login_count)->toBe(1);
    });

    it('refuses to create an owner account with a PIN', function () {
        expect(fn () => createOwner(['pin' => '8351']))->toThrow(ValidationError::class, AuthService::OWNER_PIN_REFUSED)
            ->and(DB::table('users')->count())->toBe(0);
    });
});

describe('permissions', function () {
    it('gives the owner everything, without any permission rows', function () {
        createOwner();
        $owner = signIn('kwame', OWNER_INPUT['password'])['principal'];
        expect(Permissions::can($owner, PermissionModule::Settings, 'edit'))->toBeTrue()
            ->and(Permissions::can($owner, PermissionModule::Sales, 'void'))->toBeTrue()
            ->and(Permissions::can($owner, PermissionModule::Users, 'create'))->toBeTrue()
            ->and(array_filter(PermissionModule::cases(), fn (PermissionModule $m): bool => ! Permissions::can($owner, $m, 'view')))->toBe([])
            ->and(DB::table('user_permissions')->count())->toBe(0);
    });

    it('restricts staff to their granted modules', function () {
        createStaff();
        $staff = signIn('ama', STAFF_INPUT['password'])['principal'];
        expect($staff->can(PermissionModule::Sales, 'create'))->toBeTrue()
            ->and($staff->can(PermissionModule::Sales, 'void'))->toBeFalse()
            ->and($staff->can(PermissionModule::Settings, 'view'))->toBeFalse()
            ->and($staff->can(PermissionModule::Users, 'create'))->toBeFalse()
            ->and($staff->can(PermissionModule::Reports, 'view'))->toBeFalse()
            ->and($staff->can(PermissionModule::Sales, 'view'))->toBeTrue();
    });

    it('does not let a write permission bypass a missing view permission', function () {
        $staff = new Principal(1, 'x', 'X', UserRole::Staff, ['expenses' => ['canView' => false, 'canCreate' => true, 'canEdit' => true, 'canVoid' => true]]);
        foreach (['create', 'edit', 'void'] as $action) {
            expect(Permissions::can($staff, PermissionModule::Expenses, $action))->toBeFalse();
        }
    });
});

describe('changing a password', function () {
    it('ends every session of that person; the new password works and the old one does not', function () {
        $id = createOwner();
        DB::table('sessions')->insert([
            ['id' => 'till-1', 'user_id' => $id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'till-2', 'user_id' => $id, 'payload' => '', 'last_activity' => time()],
        ]);
        AuthService::changePassword($id, 'a-brand-new-password', new Actor($id, 'kwame'));

        expect(DB::table('sessions')->where('user_id', $id)->count())->toBe(0)
            ->and(signIn('kwame', OWNER_INPUT['password'])['ok'])->toBeFalse()
            ->and(signIn('kwame', 'a-brand-new-password')['ok'])->toBeTrue();
    });
});

describe('a password hash carried over from the old app', function () {
    it('signs in with the old password, then holds a Laravel hash and the same password still works', function () {
        $legacy = LegacyHashes::oldDemo('owner', 'password_hash');
        $id = (int) DB::table('users')->insertGetId(['username' => 'owner', 'display_name' => 'Demo Owner', 'role' => 'OWNER', 'password_hash' => $legacy]);

        $first = signIn('owner', 'demo-owner-2026');
        $after = userRow('owner');
        expect($first['ok'])->toBeTrue()
            ->and($after->password_hash)->toStartWith('$argon2id$')
            ->and(DB::table('audit_logs')->where('action', 'LOGIN_SUCCESS')->where('user_id', $id)->count())->toBe(1)
            ->and(signIn('owner', 'demo-owner-2026')['ok'])->toBeTrue()
            ->and(signIn('owner', 'demo-owner-2027')['ok'])->toBeFalse();
    });

    it('is not touched by a wrong password, or for anybody else', function () {
        $legacy = LegacyHashes::oldDemo('owner', 'password_hash');
        DB::table('users')->insert([
            ['username' => 'owner', 'display_name' => 'Demo Owner', 'role' => 'OWNER', 'password_hash' => $legacy],
            ['username' => 'other', 'display_name' => 'Other Owner', 'role' => 'OWNER', 'password_hash' => $legacy],
        ]);
        signIn('owner', 'not-the-password');
        expect(userRow('owner')->password_hash)->toBe($legacy)
            ->and(userRow('other')->password_hash)->toBe($legacy);
    });

    it('signs in with the old till PIN, which is then rehashed too', function () {
        DB::table('users')->insert(['username' => 'ama', 'display_name' => 'Ama', 'role' => 'STAFF', 'password_hash' => LegacyHashes::oldDemo('ama', 'password_hash'), 'pin_hash' => LegacyHashes::oldDemo('ama', 'pin_hash')]);
        expect(AuthService::loginWithPin('ama', '8351')['ok'])->toBeTrue()
            ->and(userRow('ama')->pin_hash)->toStartWith('$argon2id$')
            // The password was not part of this sign-in, so it stays as it was.
            ->and(userRow('ama')->password_hash)->toStartWith('scrypt$');
    });
});

describe('the demo users', function () {
    it('sign in with the published demo credentials', function () {
        $seeder = new DemoSeeder;
        $method = new ReflectionMethod($seeder, 'seedUser');
        $method->invoke($seeder, DemoSeeder::DEMO_OWNER, 'OWNER', Clock::now());
        $method->invoke($seeder, DemoSeeder::DEMO_STAFF, 'STAFF', Clock::now());

        expect(signIn(DemoSeeder::DEMO_OWNER['username'], DemoSeeder::DEMO_OWNER['password'])['ok'])->toBeTrue()
            ->and(signIn(DemoSeeder::DEMO_STAFF['username'], DemoSeeder::DEMO_STAFF['password'])['ok'])->toBeTrue()
            ->and(AuthService::loginWithPin(DemoSeeder::DEMO_STAFF['username'], DemoSeeder::DEMO_STAFF['pin'])['ok'])->toBeTrue();
    });
});
