<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Passwords;
use App\Auth\Principal;
use App\Auth\RoleGuard;
use App\Auth\Sessions;
use App\Database\Tx;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Domain\JsCompat;
use App\Enums\AuditAction;
use App\Enums\PermissionModule;
use App\Enums\UserRole;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Managing people: a port of the old services/user.service.ts. The guards
 * stop the shop locking itself out: the last owner cannot be demoted or
 * switched off, and nobody switches off their own account or changes their
 * own role or rights. Nobody is ever deleted: a leaver is switched off, so
 * their sales and audit trail stay attributable.
 *
 * Owner checks and only-owner counts read the user rows FOR UPDATE (every
 * owner plus the target, in id order), so two owners switching each other
 * off at the same moment cannot both succeed.
 */
final class UserService
{
    /** @return list<array{id: int, username: string, displayName: string, role: UserRole, isActive: bool, hasPin: bool, mustChangePassword: bool, lastLoginAt: ?string, lockedUntil: ?string, isLocked: bool, failedLoginCount: int, moduleCount: int, createdAt: string}> */
    public static function listUsers(?Carbon $asOf = null): array
    {
        $asOf ??= Clock::now();

        return DB::table('users')
            ->select('users.*')
            ->selectRaw('(SELECT COUNT(*) FROM user_permissions up WHERE up.user_id = users.id AND up.can_view = 1) AS module_count')
            ->orderBy('users.display_name')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'username' => $row->username,
                'displayName' => $row->display_name,
                'role' => UserRole::from($row->role),
                'isActive' => (bool) $row->is_active,
                'hasPin' => $row->pin_hash !== null,
                'mustChangePassword' => (bool) $row->must_change_password,
                'lastLoginAt' => $row->last_login_at,
                'lockedUntil' => $row->locked_until,
                // Settled here, at one moment, not by each render.
                'isLocked' => $row->locked_until !== null && Clock::fromStamp($row->locked_until)->greaterThan($asOf),
                'failedLoginCount' => (int) $row->failed_login_count,
                // An owner is not subject to the permission table.
                'moduleCount' => $row->role === UserRole::Owner->value ? count(PermissionModule::cases()) : (int) $row->module_count,
                'createdAt' => $row->created_at,
            ])
            ->all();
    }

    public static function getUser(int $id): array
    {
        foreach (self::listUsers() as $user) {
            if ($user['id'] === $id) {
                return $user;
            }
        }
        throw new NotFoundError('User', $id);
    }

    public static function getUserPermissions(int $userId): array
    {
        return Principal::permissionMap($userId);
    }

    /** @param  array{displayName: string, role: UserRole}  $input */
    public static function updateUser(int $id, array $input, Actor $actor, ?Carbon $now = null): void
    {
        $displayName = JsCompat::trim($input['displayName']);
        if (mb_strlen($displayName) < 2) {
            throw new ValidationError('Enter the person’s name.');
        }
        $now ??= Clock::now();

        Tx::run(function () use ($id, $input, $actor, $now, $displayName): void {
            $existing = self::lockForChange($id);
            $role = $input['role'];
            $roleChanges = $existing->role !== $role->value;

            if ($roleChanges) {
                // What somebody may do is an owner's decision, not a chore that comes with `users`.
                RoleGuard::assertActorIsOwner($actor, 'change a person’s role');
                if ($id === $actor->id) {
                    throw new ConflictError('You cannot change your own role. Another owner has to do that for you.');
                }
            }
            if ($existing->role === UserRole::Owner->value && $role !== UserRole::Owner && self::activeOwnerCount($id) === 0) {
                throw new ConflictError('This is the only owner. Make someone else an owner first, otherwise nobody could manage the shop.');
            }

            DB::table('users')->where('users.id', $id)->update(['display_name' => $displayName, 'role' => $role->value, 'updated_at' => Clock::stamp($now)]);
            if ($role === UserRole::Owner && $existing->role !== UserRole::Owner->value) {
                DB::table('user_permissions')->where('user_permissions.user_id', $id)->delete();
            }
            // Their open sessions must not keep running on the old rights.
            if ($roleChanges) {
                Sessions::invalidateAllFor($id);
            }

            AuditService::write(
                $roleChanges ? AuditAction::PermissionChange : AuditAction::Update,
                'user',
                $roleChanges ? "Changed {$existing->username} from {$existing->role} to {$role->value}" : "Updated {$existing->username}",
                $id,
                $actor,
                ['before' => ['displayName' => $existing->display_name, 'role' => $existing->role], 'after' => ['displayName' => $displayName, 'role' => $role->value]],
                $now,
            );
        });
    }

    public static function setUserActive(int $id, bool $isActive, Actor $actor, ?Carbon $now = null): void
    {
        $now ??= Clock::now();

        Tx::run(function () use ($id, $isActive, $actor, $now): void {
            $existing = self::lockForChange($id);
            if (! $isActive) {
                if ($id === $actor->id) {
                    throw new ConflictError('You cannot switch off your own account.');
                }
                if ($existing->role === UserRole::Owner->value && self::activeOwnerCount($id) === 0) {
                    throw new ConflictError('This is the only active owner. Switching it off would lock everyone out of the shop.');
                }
            }
            // After the only-owner refusal, so that clearer message still wins.
            self::assertMayTakeOver($existing, $actor, $isActive ? 'reactivate this person' : 'switch this person off');

            DB::table('users')->where('users.id', $id)->update(['is_active' => $isActive, 'updated_at' => Clock::stamp($now)]);
            // Switched off means stopped now, not when the session happens to expire.
            if (! $isActive) {
                Sessions::invalidateAllFor($id);
            }
            AuditService::write($isActive ? AuditAction::Restore : AuditAction::Archive, 'user', ($isActive ? 'Reactivated' : 'Switched off')." {$existing->username}", $id, $actor, at: $now);
        });
    }

    /** Replaces the whole set; a row granting nothing is left out. */
    public static function setUserPermissions(int $id, array $permissions, Actor $actor, ?Carbon $now = null): void
    {
        $now ??= Clock::now();

        Tx::run(function () use ($id, $permissions, $actor, $now): void {
            // Rewriting your own row is the shortest route to more rights.
            if ($id === $actor->id) {
                throw new ConflictError('You cannot change your own permissions. Another owner has to do that for you.');
            }
            RoleGuard::assertPermissionsGrantable($actor, $permissions);

            $existing = DB::table('users')->where('users.id', $id)->lockForUpdate()->first() ?? throw new NotFoundError('User', $id);
            if ($existing->role === UserRole::Owner->value) {
                throw new ValidationError('An owner already has full access, so there is nothing to set here.');
            }

            $at = Clock::stamp($now);
            $before = Principal::permissionMap($id);
            DB::table('user_permissions')->where('user_permissions.user_id', $id)->delete();
            foreach (PermissionModule::cases() as $module) {
                $p = $permissions[$module->value] ?? null;
                if ($p === null || ! ($p['canView'] || $p['canCreate'] || $p['canEdit'] || $p['canVoid'])) {
                    continue;
                }
                DB::table('user_permissions')->insert([
                    'user_id' => $id, 'module' => $module->value,
                    'can_view' => $p['canView'], 'can_create' => $p['canCreate'], 'can_edit' => $p['canEdit'], 'can_void' => $p['canVoid'],
                    'created_at' => $at, 'updated_at' => $at,
                ]);
            }
            Sessions::invalidateAllFor($id);

            AuditService::write(AuditAction::PermissionChange, 'user', "Changed what {$existing->username} can do", $id, $actor, ['before' => $before, 'after' => $permissions], $now);
        });
    }

    /** The owner sets a starting password; the person must choose their own on next sign-in. */
    public static function resetUserPassword(int $id, string $newPassword, Actor $actor, ?Carbon $now = null): void
    {
        $now ??= Clock::now();
        $hash = Passwords::hashPassword($newPassword);

        Tx::run(function () use ($id, $hash, $actor, $now): void {
            $existing = DB::table('users')->where('users.id', $id)->lockForUpdate()->first() ?? throw new NotFoundError('User', $id);
            self::assertMayTakeOver($existing, $actor, 'reset this person’s password');
            DB::table('users')->where('users.id', $id)->update([
                'password_hash' => $hash, 'must_change_password' => true, 'failed_login_count' => 0, 'locked_until' => null, 'updated_at' => Clock::stamp($now),
            ]);
            Sessions::invalidateAllFor($id);
            AuditService::write(AuditAction::PasswordChange, 'user', "Reset the password for {$existing->username}; they must set a new one on next sign-in", $id, $actor, at: $now);
        });
    }

    /**
     * Needs the current password, so a walked-away session cannot be turned
     * into a takeover. Ends every session of the person; the caller starts
     * the current one again (D13).
     */
    public static function changeOwnPassword(int $userId, string $currentPassword, string $newPassword, ?Carbon $now = null): void
    {
        $now ??= Clock::now();
        $existing = DB::table('users')->where('users.id', $userId)->first() ?? throw new NotFoundError('User', $userId);
        if (! Passwords::verify($currentPassword, $existing->password_hash)) {
            throw new ValidationError('Your current password is not correct.');
        }
        if (Passwords::verify($newPassword, $existing->password_hash)) {
            throw new ValidationError('The new password must be different from the current one.');
        }
        $hash = Passwords::hashPassword($newPassword);

        Tx::run(function () use ($userId, $existing, $hash, $now): void {
            $current = DB::table('users')->where('users.id', $userId)->lockForUpdate()->first();
            // Changed by someone else while the current one was being checked.
            if ($current === null || $current->password_hash !== $existing->password_hash) {
                throw new ConflictError('Your password was changed by someone else just now. Nothing was saved; sign in again.');
            }
            DB::table('users')->where('users.id', $userId)->update(['password_hash' => $hash, 'must_change_password' => false, 'updated_at' => Clock::stamp($now)]);
            Sessions::invalidateAllFor($userId);
            AuditService::write(AuditAction::PasswordChange, 'user', "{$existing->username} changed their own password", $userId, new Actor($userId, $existing->username), at: $now);
        });
    }

    /** A short PIN for switching at the till, or null to remove it. Never a substitute for the password. */
    public static function setUserPin(int $id, ?string $pin, Actor $actor, ?Carbon $now = null): void
    {
        $now ??= Clock::now();
        $hash = $pin === null ? null : Passwords::hashPin($pin);

        Tx::run(function () use ($id, $pin, $hash, $actor, $now): void {
            $existing = DB::table('users')->where('users.id', $id)->lockForUpdate()->first() ?? throw new NotFoundError('User', $id);
            self::assertMayTakeOver($existing, $actor, 'set this person’s PIN');
            // Removing one an owner still holds from before D17 is allowed; it is ignored at sign-in anyway.
            if ($pin !== null && $existing->role === UserRole::Owner->value) {
                throw new ValidationError(AuthService::OWNER_PIN_REFUSED);
            }
            DB::table('users')->where('users.id', $id)->update(['pin_hash' => $hash, 'updated_at' => Clock::stamp($now)]);
            AuditService::write(AuditAction::PasswordChange, 'user', $pin === null ? "Removed the till PIN for {$existing->username}" : "Set a till PIN for {$existing->username}", $id, $actor, at: $now);
        });
    }

    /** Release a lockout early, when the owner knows the person simply forgot. */
    public static function unlockUser(int $id, Actor $actor, ?Carbon $now = null): void
    {
        $now ??= Clock::now();

        Tx::run(function () use ($id, $actor, $now): void {
            $existing = DB::table('users')->where('users.id', $id)->lockForUpdate()->first() ?? throw new NotFoundError('User', $id);
            DB::table('users')->where('users.id', $id)->update(['failed_login_count' => 0, 'locked_until' => null, 'updated_at' => Clock::stamp($now)]);
            AuditService::write(AuditAction::Update, 'user', "Unlocked {$existing->username} after failed sign-in attempts", $id, $actor, at: $now);
        });
    }

    /** Blank rights for every module, for the editor to fill in. */
    public static function emptyPermissionMatrix(): array
    {
        $matrix = [];
        foreach (PermissionModule::cases() as $module) {
            $matrix[$module->value] = ['canView' => false, 'canCreate' => false, 'canEdit' => false, 'canVoid' => false];
        }

        return $matrix;
    }

    /**
     * Setting someone's credential lets you sign in as them, so it is allowed only
     * over accounts holding no more than you: an owner's needs an owner (D14).
     */
    private static function assertMayTakeOver(object $target, Actor $actor, string $what): void
    {
        if ($target->role === UserRole::Owner->value) {
            RoleGuard::assertActorIsOwner($actor, $what);

            return;
        }
        RoleGuard::assertPermissionsGrantable($actor, Principal::permissionMap((int) $target->id));
    }

    /** Every owner and the target, locked in id order; returns the target. */
    private static function lockForChange(int $id): object
    {
        $rows = DB::table('users')->where(fn ($q) => $q->where('users.role', UserRole::Owner->value)->orWhere('users.id', $id))
            ->orderBy('users.id')->lockForUpdate()->get()->keyBy('id');

        return $rows[$id] ?? throw new NotFoundError('User', $id);
    }

    /** Owners who can still sign in, other than this one. Call after lockForChange(). */
    private static function activeOwnerCount(int $excludingId): int
    {
        return DB::table('users')->where('users.role', UserRole::Owner->value)->where('users.is_active', true)->where('users.id', '<>', $excludingId)->count();
    }
}
