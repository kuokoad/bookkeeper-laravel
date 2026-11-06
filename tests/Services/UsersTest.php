<?php

declare(strict_types=1);

/*
| Port of the old tests/services/users.test.ts. "Signed out" is asserted on
| the person's session rows (and, for a request already in flight, by
| SessionLimitsTest).
*/

use App\Auth\Permissions;
use App\Auth\Principal;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Enums\AuditAction;
use App\Enums\PermissionModule;
use App\Enums\UserRole;
use App\Services\Actor;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $id = AuthService::createUser(['username' => 'kwame', 'displayName' => 'Kwame Owusu', 'password' => 'owner-password-2026', 'role' => UserRole::Owner]);
    $this->owner = new Actor($id, 'kwame');
});

function makeOwner(string $username = 'abena'): int
{
    return AuthService::createUser(['username' => $username, 'displayName' => 'Abena Mensah', 'password' => 'owner-password-2026', 'role' => UserRole::Owner]);
}

function makeStaff(string $username = 'ama'): int
{
    return AuthService::createUser([
        'username' => $username, 'displayName' => 'Ama Serwaa', 'password' => 'staff-password-2026', 'role' => UserRole::Staff,
        'permissions' => ['sales' => ['canView' => true, 'canCreate' => true, 'canEdit' => false, 'canVoid' => false]],
    ]);
}

/** A session on a till, as Laravel's database store keeps it. */
function openSession(int $userId, string $id = 'till-1'): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
}

function sessionCount(int $userId): int
{
    return DB::table('sessions')->where('user_id', $userId)->count();
}

describe('protecting the shop from locking itself out', function () {
    it('refuses to switch off the only owner', function () {
        makeStaff();
        $someone = new Actor(makeStaff('esi'), 'esi');
        expect(fn () => UserService::setUserActive($this->owner->id, false, $someone))->toThrow('only active owner')
            ->and(UserService::getUser($this->owner->id)['isActive'])->toBeTrue();
    });

    it('refuses to demote the only owner (the own-role rule meets it first)', function () {
        expect(fn () => UserService::updateUser($this->owner->id, ['displayName' => 'Kwame Owusu', 'role' => UserRole::Staff], $this->owner))->toThrow('your own role')
            ->and(UserService::getUser($this->owner->id)['role'])->toBe(UserRole::Owner);
    });

    it('lets an owner hand over, leaving the shop with an owner throughout', function () {
        $second = makeOwner();
        UserService::updateUser($this->owner->id, ['displayName' => 'Kwame Owusu', 'role' => UserRole::Staff], new Actor($second, 'abena'));
        expect(UserService::getUser($this->owner->id)['role'])->toBe(UserRole::Staff)
            ->and(UserService::getUser($second)['role'])->toBe(UserRole::Owner);
    });

    it('allows switching an owner off once a SECOND owner exists', function () {
        $second = makeOwner();
        UserService::setUserActive($this->owner->id, false, new Actor($second, 'abena'));
        expect(UserService::getUser($this->owner->id)['isActive'])->toBeFalse();
    });

    it('refuses to let someone switch off their own account', function () {
        makeOwner();
        expect(fn () => UserService::setUserActive($this->owner->id, false, $this->owner))->toThrow('your own account');
    });

    it('never deletes a user: switched off, the row survives', function () {
        $staffId = makeStaff();
        openSession($staffId);
        UserService::setUserActive($staffId, false, $this->owner);
        expect(DB::table('users')->where('id', $staffId)->exists())->toBeTrue()
            ->and(UserService::getUser($staffId)['isActive'])->toBeFalse()
            ->and(sessionCount($staffId))->toBe(0)
            ->and(method_exists(UserService::class, 'deleteUser'))->toBeFalse();
    });
});

describe('changing what someone can do', function () {
    it('replaces the permission set and signs them out', function () {
        $staffId = makeStaff();
        openSession($staffId);
        UserService::setUserPermissions($staffId, [
            'sales' => Permissions::FULL_ACCESS,
            'reports' => ['canView' => true, 'canCreate' => false, 'canEdit' => false, 'canVoid' => false],
        ], $this->owner);

        $permissions = UserService::getUserPermissions($staffId);
        expect(sessionCount($staffId))->toBe(0)
            ->and($permissions['sales']['canVoid'])->toBeTrue()
            ->and($permissions['reports']['canView'])->toBeTrue()
            ->and($permissions)->toHaveCount(2);
    });

    it('drops rows that grant nothing', function () {
        $staffId = makeStaff();
        UserService::setUserPermissions($staffId, [
            'sales' => ['canView' => true, 'canCreate' => false, 'canEdit' => false, 'canVoid' => false],
            'settings' => Permissions::NO_ACCESS,
        ], $this->owner);
        expect(DB::table('user_permissions')->where('user_id', $staffId)->pluck('module')->all())->toBe(['sales']);
    });

    it('refuses to set permissions on an owner, who already has everything', function () {
        expect(fn () => UserService::setUserPermissions(makeOwner(), ['sales' => Permissions::FULL_ACCESS], $this->owner))->toThrow('already has full access');
    });

    it('clears permission rows when someone is promoted to owner, and signs them out', function () {
        $staffId = makeStaff();
        openSession($staffId);
        UserService::updateUser($staffId, ['displayName' => 'Ama Serwaa', 'role' => UserRole::Owner], $this->owner);
        expect(DB::table('user_permissions')->where('user_id', $staffId)->count())->toBe(0)
            ->and(sessionCount($staffId))->toBe(0)
            ->and(Principal::load($staffId)->can(PermissionModule::Settings, 'edit'))->toBeTrue();
    });

    it('does not sign anyone out for a rename', function () {
        $staffId = makeStaff();
        openSession($staffId);
        UserService::updateUser($staffId, ['displayName' => 'Ama S.', 'role' => UserRole::Staff], $this->owner);
        expect(sessionCount($staffId))->toBe(1);
    });
});

describe('passwords', function () {
    it('an owner reset forces the person to choose their own', function () {
        $staffId = makeStaff();
        UserService::resetUserPassword($staffId, 'temporary-password-1', $this->owner);
        $result = AuthService::login('ama', 'temporary-password-1');
        expect($result['ok'])->toBeTrue()->and($result['mustChangePassword'])->toBeTrue();
    });

    it('a reset clears any lockout and ends open sessions', function () {
        $staffId = makeStaff();
        openSession($staffId);
        for ($i = 0; $i < AuthService::MAX_FAILED_LOGINS; $i++) {
            AuthService::login('ama', 'wrong');
        }
        expect(UserService::getUser($staffId)['lockedUntil'])->not->toBeNull();

        UserService::resetUserPassword($staffId, 'temporary-password-1', $this->owner);
        expect(UserService::getUser($staffId)['lockedUntil'])->toBeNull()
            ->and(sessionCount($staffId))->toBe(0);
    });

    it('changing your own password requires the current one', function () {
        $staffId = makeStaff();
        expect(fn () => UserService::changeOwnPassword($staffId, 'not-the-right-one', 'a-new-password-2026'))->toThrow('current password is not correct')
            ->and(AuthService::login('ama', 'staff-password-2026')['ok'])->toBeTrue();
    });

    it('refuses reusing the same password', function () {
        expect(fn () => UserService::changeOwnPassword(makeStaff(), 'staff-password-2026', 'staff-password-2026'))->toThrow('different from the current one');
    });

    it('a successful self-change clears the must-change flag and ends sessions', function () {
        $staffId = makeStaff();
        UserService::resetUserPassword($staffId, 'temporary-password-1', $this->owner);
        openSession($staffId);
        UserService::changeOwnPassword($staffId, 'temporary-password-1', 'chosen-by-me-2026');

        $after = AuthService::login('ama', 'chosen-by-me-2026');
        expect(UserService::getUser($staffId)['mustChangePassword'])->toBeFalse()
            ->and(sessionCount($staffId))->toBe(0)
            ->and($after['ok'])->toBeTrue()
            ->and($after['mustChangePassword'])->toBeFalse();
    });

    it('unlocking releases a lockout early', function () {
        $staffId = makeStaff();
        for ($i = 0; $i < AuthService::MAX_FAILED_LOGINS; $i++) {
            AuthService::login('ama', 'wrong');
        }
        expect(AuthService::login('ama', 'staff-password-2026')['ok'])->toBeFalse();
        UserService::unlockUser($staffId, $this->owner);
        expect(AuthService::login('ama', 'staff-password-2026')['ok'])->toBeTrue();
    });
});

describe('till PIN', function () {
    it('signs in with a PIN', function () {
        $staffId = makeStaff();
        UserService::setUserPin($staffId, '8351', $this->owner);
        $result = AuthService::loginWithPin('ama', '8351');
        expect($result['ok'])->toBeTrue()->and($result['principal']->username)->toBe('ama');
    });

    it('refuses a wrong PIN, and refuses when no PIN is set, alike', function () {
        $staffId = makeStaff();
        expect(AuthService::loginWithPin('ama', '8351'))->toBe(['ok' => false, 'reason' => 'INVALID_CREDENTIALS']);
        UserService::setUserPin($staffId, '8351', $this->owner);
        expect(AuthService::loginWithPin('ama', '9999')['ok'])->toBeFalse();
    });

    it('locks the account after repeated wrong PINs, exactly like a password', function () {
        $staffId = makeStaff();
        UserService::setUserPin($staffId, '8351', $this->owner);
        for ($i = 0; $i < AuthService::MAX_FAILED_LOGINS; $i++) {
            AuthService::loginWithPin('ama', '0000');
        }
        expect(AuthService::loginWithPin('ama', '8351')['reason'])->toBe('ACCOUNT_LOCKED')
            // One account, one lockout.
            ->and(AuthService::login('ama', 'staff-password-2026')['ok'])->toBeFalse();
    });

    it('rejects a weak PIN and can remove one', function () {
        $staffId = makeStaff();
        expect(fn () => UserService::setUserPin($staffId, '1234', $this->owner))->toThrow(ValidationError::class)
            ->and(fn () => UserService::setUserPin($staffId, '1111', $this->owner))->toThrow(ValidationError::class);
        UserService::setUserPin($staffId, '8351', $this->owner);
        expect(UserService::getUser($staffId)['hasPin'])->toBeTrue();
        UserService::setUserPin($staffId, null, $this->owner);
        expect(UserService::getUser($staffId)['hasPin'])->toBeFalse();
    });

    it('a switched-off account cannot sign in with a PIN either', function () {
        $staffId = makeStaff();
        makeOwner();
        UserService::setUserPin($staffId, '8351', $this->owner);
        UserService::setUserActive($staffId, false, $this->owner);
        expect(AuthService::loginWithPin('ama', '8351'))->toBe(['ok' => false, 'reason' => 'ACCOUNT_INACTIVE']);
    });
});

describe('the audit log', function () {
    it('records every administrative action against the person who did it', function () {
        $staffId = makeStaff();
        UserService::setUserPermissions($staffId, ['reports' => ['canView' => true, 'canCreate' => false, 'canEdit' => false, 'canVoid' => false]], $this->owner);
        UserService::resetUserPassword($staffId, 'temporary-password-1', $this->owner);
        UserService::setUserActive($staffId, false, $this->owner);

        $entries = AuditService::list(['entityType' => 'user']);
        expect($entries->pluck('action')->all())->toContain('CREATE', 'PERMISSION_CHANGE', 'PASSWORD_CHANGE', 'ARCHIVE')
            ->and($entries->every(fn ($e) => strlen($e->summary) > 0))->toBeTrue()
            ->and($entries->where('action', 'ARCHIVE')->first()->user_id)->toBe($this->owner->id);
    });

    it('never records a password or a PIN', function () {
        $staffId = makeStaff();
        UserService::resetUserPassword($staffId, 'super-secret-value-99', $this->owner);
        UserService::setUserPin($staffId, '8351', $this->owner);
        AuthService::login('ama', 'another-secret-attempt');

        $everything = json_encode(DB::table('audit_logs')->get());
        expect($everything)->not->toContain('super-secret-value-99')
            ->and($everything)->not->toContain('another-secret-attempt')
            ->and($everything)->not->toContain('8351');
    });

    it('filters by action, kind and free text, and counts what it lists', function () {
        $staffId = makeStaff();
        makeOwner();
        UserService::setUserActive($staffId, false, $this->owner);

        expect(AuditService::list(['action' => AuditAction::Archive])->count())->toBeGreaterThan(0)
            ->and(AuditService::list(['search' => 'switched off'])->count())->toBeGreaterThan(0)
            ->and(AuditService::list(['search' => 'nothing matches this'])->count())->toBe(0)
            ->and(AuditService::count(['entityType' => 'user']))->toBe(AuditService::list(['entityType' => 'user', 'limit' => 500])->count())
            ->and(AuditService::entityTypes())->toContain('user');
    });
});

describe('listing people', function () {
    it('summarises each account', function () {
        makeOwner();
        $staffId = makeStaff();
        UserService::setUserPin($staffId, '8351', $this->owner);

        $all = collect(UserService::listUsers());
        $owner = $all->firstWhere('username', 'kwame');
        $staff = $all->firstWhere('username', 'ama');
        expect($owner['role'])->toBe(UserRole::Owner)
            ->and($owner['moduleCount'])->toBeGreaterThan(10)
            ->and($staff['hasPin'])->toBeTrue()
            ->and($staff['moduleCount'])->toBe(1)
            ->and($staff['lastLoginAt'])->toBeNull();
    });

    it('throws for an unknown user rather than returning nothing', function () {
        expect(fn () => UserService::getUser(999_999))->toThrow(NotFoundError::class);
    });
});
