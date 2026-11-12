<?php

declare(strict_types=1);

/*
| Port of the old tests/services/privilege.test.ts: nobody may hand
| themselves more rights than they were given.
*/

use App\Auth\Principal;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\ForbiddenError;
use App\Enums\UserRole;
use App\Services\Actor;
use App\Services\AuthService;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->ownerId = AuthService::createUser(['username' => 'kwame', 'displayName' => 'Kwame Owusu', 'password' => 'owner-password-2026', 'role' => UserRole::Owner]);
    $this->owner = new Actor($this->ownerId, 'kwame');
    // Staff trusted with the users module: "let Bob look after the team".
    $this->managerId = AuthService::createUser([
        'username' => 'bob', 'displayName' => 'Bob Mensah', 'password' => 'staff-password-2026', 'role' => UserRole::Staff,
        'permissions' => ['users' => ['canView' => true, 'canCreate' => true, 'canEdit' => true, 'canVoid' => false]],
    ], $this->owner);
    $this->manager = new Actor($this->managerId, 'bob');
});

function colleague(Actor $by, string $username = 'ama'): int
{
    return AuthService::createUser(['username' => $username, 'displayName' => 'Ama', 'password' => 'staff-password-2026', 'role' => UserRole::Staff], $by);
}

describe('promoting to owner', function () {
    it('REFUSES a staff member promoting themselves', function () {
        expect(fn () => UserService::updateUser($this->managerId, ['displayName' => 'Bob Mensah', 'role' => UserRole::Owner], $this->manager))->toThrow('owner')
            ->and(UserService::getUser($this->managerId)['role'])->toBe(UserRole::Staff);
    });

    it('REFUSES a staff member promoting a colleague', function () {
        $id = colleague($this->owner);
        expect(fn () => UserService::updateUser($id, ['displayName' => 'Ama', 'role' => UserRole::Owner], $this->manager))->toThrow('owner')
            ->and(UserService::getUser($id)['role'])->toBe(UserRole::Staff);
    });

    it('still lets a staff member rename a colleague who holds no more than they do', function () {
        $id = colleague($this->owner);
        UserService::updateUser($id, ['displayName' => 'Ama B. Mensah', 'role' => UserRole::Staff], $this->manager);
        expect(UserService::getUser($id)['displayName'])->toBe('Ama B. Mensah');
    });

    it('REFUSES a staff member renaming the owner: every later record of who did what would show the new name', function () {
        expect(fn () => UserService::updateUser($this->ownerId, ['displayName' => 'Kwame O. Owusu', 'role' => UserRole::Owner], $this->manager))->toThrow(ForbiddenError::class)
            ->and(UserService::getUser($this->ownerId)['displayName'])->toBe('Kwame Owusu');
    });

    it('allows an owner to promote someone', function () {
        $id = colleague($this->owner);
        UserService::updateUser($id, ['displayName' => 'Ama', 'role' => UserRole::Owner], $this->owner);
        expect(UserService::getUser($id)['role'])->toBe(UserRole::Owner);
    });

    it('REFUSES anyone changing their OWN role, owner included', function () {
        $second = AuthService::createUser(['username' => 'abena', 'displayName' => 'Abena', 'password' => 'owner-password-2026', 'role' => UserRole::Owner], $this->owner);
        expect(fn () => UserService::updateUser($second, ['displayName' => 'Abena', 'role' => UserRole::Staff], new Actor($second, 'abena')))->toThrow('your own role');
    });
});

describe('creating accounts', function () {
    it('REFUSES a staff member minting a new owner', function () {
        expect(fn () => AuthService::createUser(['username' => 'mole', 'displayName' => 'Mole', 'password' => 'a-password-2026', 'role' => UserRole::Owner], $this->manager))->toThrow('owner');
    });

    it('still lets a staff member with the right create ordinary staff', function () {
        expect(UserService::getUser(colleague($this->manager, 'newhire'))['role'])->toBe(UserRole::Staff);
    });

    it('REFUSES granting a new account rights the creator does not hold', function () {
        expect(fn () => AuthService::createUser([
            'username' => 'proxy', 'displayName' => 'Proxy', 'password' => 'a-password-2026', 'role' => UserRole::Staff,
            'permissions' => ['accounts' => ['canView' => true, 'canCreate' => true, 'canEdit' => true, 'canVoid' => true]],
        ], $this->manager))->toThrow('accounts');
    });

    it('lets the creator pass on rights they DO hold', function () {
        $id = AuthService::createUser([
            'username' => 'deputy', 'displayName' => 'Deputy', 'password' => 'a-password-2026', 'role' => UserRole::Staff,
            'permissions' => ['users' => ['canView' => true, 'canCreate' => true, 'canEdit' => false, 'canVoid' => false]],
        ], $this->manager);
        expect(UserService::getUserPermissions($id)['users']['canCreate'])->toBeTrue();
    });

    it('REFUSES passing on a level of a right the creator lacks', function () {
        expect(fn () => AuthService::createUser([
            'username' => 'voider', 'displayName' => 'Voider', 'password' => 'a-password-2026', 'role' => UserRole::Staff,
            'permissions' => ['users' => ['canView' => true, 'canCreate' => false, 'canEdit' => false, 'canVoid' => true]],
        ], $this->manager))->toThrow('users');
    });
});

describe('permissions', function () {
    it('REFUSES a staff member rewriting their own permissions', function () {
        expect(fn () => UserService::setUserPermissions($this->managerId, ['accounts' => ['canView' => true, 'canCreate' => true, 'canEdit' => true, 'canVoid' => true]], $this->manager))->toThrow('own permissions');
    });

    it('REFUSES a staff member granting rights to anyone else', function () {
        $id = colleague($this->owner);
        expect(fn () => UserService::setUserPermissions($id, ['accounts' => ['canView' => true, 'canCreate' => true, 'canEdit' => true, 'canVoid' => true]], $this->manager))->toThrow('owner');
    });

    it('allows an owner to set permissions', function () {
        $id = colleague($this->owner);
        UserService::setUserPermissions($id, ['sales' => ['canView' => true, 'canCreate' => true, 'canEdit' => false, 'canVoid' => false]], $this->owner);
        expect(UserService::getUserPermissions($id))->toHaveKey('sales');
    });
});

describe('taking over another account (D14)', function () {
    it('REFUSES a staff member with users:edit setting the owner’s PIN', function () {
        expect(fn () => UserService::setUserPin($this->ownerId, '8351', $this->manager))->toThrow(ForbiddenError::class)
            ->and(DB::table('users')->where('id', $this->ownerId)->value('pin_hash'))->toBeNull();
    });

    it('REFUSES a staff member with users:edit resetting the owner’s password', function () {
        expect(fn () => UserService::resetUserPassword($this->ownerId, 'taken-over-2026', $this->manager))->toThrow(ForbiddenError::class)
            ->and(AuthService::login('kwame', 'owner-password-2026')['ok'])->toBeTrue();
    });

    it('REFUSES setting the PIN or password of someone holding rights the actor lacks', function () {
        $id = AuthService::createUser([
            'username' => 'cashier', 'displayName' => 'Cashier', 'password' => 'staff-password-2026', 'role' => UserRole::Staff,
            'permissions' => ['accounts' => ['canView' => true, 'canCreate' => true, 'canEdit' => true, 'canVoid' => true]],
        ], $this->owner);
        expect(fn () => UserService::setUserPin($id, '8351', $this->manager))->toThrow(ForbiddenError::class)
            ->and(fn () => UserService::resetUserPassword($id, 'taken-over-2026', $this->manager))->toThrow(ForbiddenError::class)
            ->and(AuthService::login('cashier', 'staff-password-2026')['ok'])->toBeTrue();
    });

    it('REFUSES the owner resetting their own password without the current one, from a screen left signed in', function () {
        expect(fn () => UserService::resetUserPassword($this->ownerId, 'taken-over-2026', $this->owner))->toThrow(ConflictError::class, 'own password')
            ->and(AuthService::login('kwame', 'owner-password-2026')['ok'])->toBeTrue();
    });

    it('REFUSES a supervisor setting their own PIN, a way back in as them later', function () {
        $supervisor = AuthService::createUser([
            'username' => 'abena', 'displayName' => 'Abena', 'password' => 'staff-password-2026', 'role' => UserRole::Staff,
            'permissions' => ['users' => ['canView' => true, 'canCreate' => false, 'canEdit' => true, 'canVoid' => false]],
        ], $this->owner);
        expect(fn () => UserService::setUserPin($supervisor, '8351', new Actor($supervisor, 'abena')))->toThrow(ConflictError::class, 'own PIN')
            ->and(DB::table('users')->where('id', $supervisor)->value('pin_hash'))->toBeNull();
    });

    it('still lets them reset a colleague who holds no more than they do', function () {
        $id = colleague($this->owner);
        UserService::resetUserPassword($id, 'temporary-password-1', $this->manager);
        UserService::setUserPin($id, '8351', $this->manager);
        expect(AuthService::login('ama', 'temporary-password-1')['ok'])->toBeTrue();
    });

    it('REFUSES a staff member with users:edit switching off an owner', function () {
        AuthService::createUser(['username' => 'abena', 'displayName' => 'Abena', 'password' => 'owner-password-2026', 'role' => UserRole::Owner]);
        expect(fn () => UserService::setUserActive($this->ownerId, false, $this->manager))->toThrow(ForbiddenError::class)
            ->and(UserService::getUser($this->ownerId)['isActive'])->toBeTrue();
    });

    it('REFUSES switching off a colleague holding rights the actor lacks', function () {
        $id = AuthService::createUser([
            'username' => 'cashier', 'displayName' => 'Cashier', 'password' => 'staff-password-2026', 'role' => UserRole::Staff,
            'permissions' => ['accounts' => ['canView' => true, 'canCreate' => true, 'canEdit' => true, 'canVoid' => true]],
        ], $this->owner);
        expect(fn () => UserService::setUserActive($id, false, $this->manager))->toThrow(ForbiddenError::class)
            ->and(UserService::getUser($id)['isActive'])->toBeTrue();
    });

    it('still lets an owner switch off another owner, and a manager a colleague holding no more', function () {
        $second = AuthService::createUser(['username' => 'abena', 'displayName' => 'Abena', 'password' => 'owner-password-2026', 'role' => UserRole::Owner]);
        $id = colleague($this->owner);
        UserService::setUserActive($second, false, $this->owner);
        UserService::setUserActive($id, false, $this->manager);
        expect(UserService::getUser($second)['isActive'])->toBeFalse()
            ->and(UserService::getUser($id)['isActive'])->toBeFalse();
    });
});

it('refuses a switched-off account at the gate, even without the middleware', function () {
    UserService::setUserActive($this->managerId, false, $this->owner);
    expect(Principal::load($this->managerId))->toBeNull()
        ->and(Principal::load($this->ownerId))->not->toBeNull();
});
