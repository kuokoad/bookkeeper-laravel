<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\LegacyScrypt;
use App\Auth\Passwords;
use App\Auth\Principal;
use App\Auth\RoleGuard;
use App\Auth\Sessions;
use App\Database\Tx;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\ValidationError;
use App\Domain\JsCompat;
use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Signing in, first-run setup and creating accounts: a port of the old
 * services/auth.service.ts.
 *
 * The HTTP layer (Phase 9) signs a person in with login() or loginWithPin()
 * and, on success, Sessions::begin(). Never Auth::attempt(): that knows
 * nothing of the lockout, the legacy hashes or the audit log.
 *
 * The password is checked before the write, as the old app did (a legacy
 * scrypt check takes seconds and must not hold a row lock); the write then
 * locks the user row, makes sure the hash it checked is still the one
 * stored, and in the same transaction clears the failure count, rehashes a
 * legacy or outdated hash to Laravel's, and writes the audit row.
 */
final class AuthService
{
    /** Consecutive failures before the account is locked. */
    public const MAX_FAILED_LOGINS = 5;

    /** How long the lock lasts: long enough to stop guessing, short enough to trade. */
    public const LOCKOUT_MINUTES = 15;

    /** The sign-in throttle in front of the lockout: per client (or one shared bucket), not per account. */
    public const LOGIN_ATTEMPT_LIMIT = 10;

    public const LOGIN_WINDOW_MS = 10 * 60 * 1000;

    private const USERNAME_PATTERN = '/^[a-zA-Z0-9._-]{3,40}\z/';

    /**
     * @return array{ok: true, user: User, principal: Principal, mustChangePassword: bool}|array{ok: false, reason: 'INVALID_CREDENTIALS'|'ACCOUNT_LOCKED'|'ACCOUNT_INACTIVE', lockedUntil?: Carbon}
     */
    public static function login(string $username, string $password, ?string $ipAddress = null, ?string $userAgent = null, ?Carbon $now = null): array
    {
        $now ??= Clock::now();
        $username = JsCompat::trim($username);
        $user = self::findByUsername($username);

        if ($user === null) {
            // The same work as a real check, so timing does not reveal which usernames exist.
            Passwords::burn();
            self::recordFailedAttempt(null, $username, $ipAddress, $userAgent, $now);

            return ['ok' => false, 'reason' => 'INVALID_CREDENTIALS'];
        }

        $lockedUntil = self::lockedUntil($user, $now);
        if ($lockedUntil !== null) {
            Tx::run(fn () => AuditService::write(AuditAction::LoginFailed, 'user', 'Login attempt while account locked until '.self::iso($lockedUntil), $user->id, new Actor((int) $user->id, $user->username), at: $now, ipAddress: $ipAddress, userAgent: $userAgent));

            return ['ok' => false, 'reason' => 'ACCOUNT_LOCKED', 'lockedUntil' => $lockedUntil];
        }

        if (! Passwords::verify($password, $user->password_hash)) {
            self::recordFailedAttempt((int) $user->id, $user->username, $ipAddress, $userAgent, $now);

            return ['ok' => false, 'reason' => 'INVALID_CREDENTIALS'];
        }

        // Checked AFTER the password, so a switched-off account looks like any other to a guesser.
        if (! (bool) $user->is_active) {
            Tx::run(fn () => AuditService::write(AuditAction::LoginFailed, 'user', 'Login attempt on a deactivated account', $user->id, new Actor((int) $user->id, $user->username), at: $now, ipAddress: $ipAddress, userAgent: $userAgent));

            return ['ok' => false, 'reason' => 'ACCOUNT_INACTIVE'];
        }

        return self::succeed($user, 'password_hash', $password, "{$user->display_name} signed in", $ipAddress, $userAgent, $now);
    }

    /**
     * Fast sign-in at the till with a short PIN. The same lockout counter as
     * the password, so a PIN is not a second set of guesses; "no PIN set"
     * answers exactly as a wrong PIN does.
     *
     * @return array{ok: true, user: User, principal: Principal, mustChangePassword: bool}|array{ok: false, reason: 'INVALID_CREDENTIALS'|'ACCOUNT_LOCKED'|'ACCOUNT_INACTIVE', lockedUntil?: Carbon}
     */
    public static function loginWithPin(string $username, string $pin, ?string $ipAddress = null, ?string $userAgent = null, ?Carbon $now = null): array
    {
        $now ??= Clock::now();
        $username = JsCompat::trim($username);
        $user = self::findByUsername($username);

        if ($user === null || $user->pin_hash === null) {
            Passwords::burn();
            self::recordFailedAttempt($user === null ? null : (int) $user->id, $username, $ipAddress, $userAgent, $now);

            return ['ok' => false, 'reason' => 'INVALID_CREDENTIALS'];
        }

        // As the old app: no audit row for a locked or switched-off PIN attempt.
        $lockedUntil = self::lockedUntil($user, $now);
        if ($lockedUntil !== null) {
            return ['ok' => false, 'reason' => 'ACCOUNT_LOCKED', 'lockedUntil' => $lockedUntil];
        }

        if (! Passwords::verify($pin, $user->pin_hash)) {
            self::recordFailedAttempt((int) $user->id, $user->username, $ipAddress, $userAgent, $now);

            return ['ok' => false, 'reason' => 'INVALID_CREDENTIALS'];
        }

        if (! (bool) $user->is_active) {
            return ['ok' => false, 'reason' => 'ACCOUNT_INACTIVE'];
        }

        return self::succeed($user, 'pin_hash', $pin, "{$user->display_name} signed in with a PIN", $ipAddress, $userAgent, $now);
    }

    public static function logout(Principal $principal, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        Tx::run(fn () => AuditService::write(AuditAction::Logout, 'user', "{$principal->displayName} signed out", $principal->id, $principal->actor(), ipAddress: $ipAddress, userAgent: $userAgent));
    }

    /** True when the shop has no accounts at all: gates first-run setup. */
    public static function needsInitialSetup(): bool
    {
        return ! DB::table('users')->exists();
    }

    /**
     * A null actor is first-run setup. Once the shop has an owner, an
     * account that outranks you, or one with rights you were never given, is
     * escalation with one extra sign-in, so both are owners only.
     *
     * @param  array{username: string, displayName: string, password: string, role: UserRole, pin?: ?string, permissions?: array<string, array{canView: bool, canCreate: bool, canEdit: bool, canVoid: bool}>, mustChangePassword?: bool}  $input
     */
    public static function createUser(array $input, ?Actor $actor = null, ?Carbon $now = null): int
    {
        $now ??= Clock::now();
        $username = JsCompat::trim($input['username']);
        $displayName = JsCompat::trim($input['displayName']);

        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            throw new ValidationError('Username must be 3-40 characters and contain only letters, numbers, dots, dashes or underscores.');
        }
        if (mb_strlen($displayName) < 2) {
            throw new ValidationError('Enter the person’s name.');
        }
        if (self::findByUsername($username) !== null) {
            throw new ConflictError('That username is already taken.');
        }
        if ($actor !== null) {
            if ($input['role'] === UserRole::Owner) {
                RoleGuard::assertActorIsOwner($actor, 'create an owner account');
            }
            if (isset($input['permissions'])) {
                RoleGuard::assertPermissionsGrantable($actor, $input['permissions']);
            }
        }

        $passwordHash = Passwords::hashPassword($input['password']);
        $pin = $input['pin'] ?? null;
        $pinHash = $pin !== null && $pin !== '' ? Passwords::hashPin($pin) : null;

        return Tx::run(function () use ($input, $actor, $now, $username, $displayName, $passwordHash, $pinHash): int {
            $at = Clock::stamp($now);
            $id = (int) DB::table('users')->insertGetId([
                'username' => $username,
                'display_name' => $displayName,
                'role' => $input['role']->value,
                'password_hash' => $passwordHash,
                'pin_hash' => $pinHash,
                'is_active' => true,
                'must_change_password' => $input['mustChangePassword'] ?? false,
                'created_by' => $actor?->id,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            // An owner bypasses the permission table, so rows for one would mislead.
            if ($input['role'] === UserRole::Staff) {
                foreach ($input['permissions'] ?? [] as $module => $permission) {
                    DB::table('user_permissions')->insert([
                        'user_id' => $id,
                        'module' => $module,
                        'can_view' => $permission['canView'],
                        'can_create' => $permission['canCreate'],
                        'can_edit' => $permission['canEdit'],
                        'can_void' => $permission['canVoid'],
                        'created_at' => $at,
                        'updated_at' => $at,
                    ]);
                }
            }

            AuditService::write(AuditAction::Create, 'user', 'Created '.strtolower($input['role']->value)." account \"{$username}\"", $id, $actor, ['role' => $input['role']->value, 'displayName' => $input['displayName']], $now);

            return $id;
        });
    }

    /** Sets a new password and ends every session of that person. */
    public static function changePassword(int $userId, string $newPassword, Actor $actor, ?Carbon $now = null): void
    {
        $now ??= Clock::now();
        $passwordHash = Passwords::hashPassword($newPassword);

        Tx::run(function () use ($userId, $passwordHash, $actor, $now): void {
            $user = DB::table('users')->where('users.id', $userId)->lockForUpdate()->first()
                ?? throw new ValidationError('That user account no longer exists.');
            DB::table('users')->where('users.id', $userId)->update(['password_hash' => $passwordHash, 'must_change_password' => false, 'updated_at' => Clock::stamp($now)]);
            Sessions::invalidateAllFor($userId);
            AuditService::write(AuditAction::PasswordChange, 'user', "Password changed for \"{$user->username}\"; all sessions signed out", $userId, $actor, at: $now);
        });
    }

    /** Case-insensitive, as the old lower(username) = lower(?). */
    public static function findByUsername(string $username): ?object
    {
        return DB::table('users')->whereRaw('LOWER(`users`.`username`) = LOWER(?)', [$username])->first();
    }

    /** @param  'password_hash'|'pin_hash'  $column */
    private static function succeed(object $user, string $column, string $secret, string $summary, ?string $ipAddress, ?string $userAgent, Carbon $now): array
    {
        $verified = $user->{$column};
        // Made before the write: a hash is slow, a row lock should not be.
        $rehashed = Passwords::needsRehash($verified) ? Hash::make($column === 'password_hash' ? LegacyScrypt::normalize($secret) : $secret) : null;

        $ok = Tx::run(function () use ($user, $column, $verified, $rehashed, $summary, $ipAddress, $userAgent, $now): bool {
            $current = DB::table('users')->where('users.id', $user->id)->lockForUpdate()->first();
            // Reset or changed while this check ran: what was checked is no longer the credential.
            if ($current === null || $current->{$column} !== $verified || ! (bool) $current->is_active) {
                return false;
            }
            $changes = ['failed_login_count' => 0, 'locked_until' => null, 'last_login_at' => Clock::stamp($now), 'updated_at' => Clock::stamp($now)];
            if ($rehashed !== null) {
                // One person at a time, on their own correct password: never a bulk rewrite.
                $changes[$column] = $rehashed;
            }
            DB::table('users')->where('users.id', $user->id)->update($changes);
            AuditService::write(AuditAction::LoginSuccess, 'user', $summary, $user->id, new Actor((int) $user->id, $user->username), at: $now, ipAddress: $ipAddress, userAgent: $userAgent);

            return true;
        });

        if (! $ok) {
            return ['ok' => false, 'reason' => 'INVALID_CREDENTIALS'];
        }

        return [
            'ok' => true,
            'user' => User::query()->findOrFail($user->id),
            'principal' => Principal::load((int) $user->id),
            'mustChangePassword' => (bool) $user->must_change_password,
        ];
    }

    private static function recordFailedAttempt(?int $userId, string $username, ?string $ipAddress, ?string $userAgent, Carbon $now): void
    {
        Tx::run(function () use ($userId, $username, $ipAddress, $userAgent, $now): void {
            if ($userId !== null) {
                $current = DB::table('users')->where('users.id', $userId)->lockForUpdate()->first();
                // Serving the lockout gives the attempts back, or one slip a month later re-locks at once.
                $lockedUntil = $current?->locked_until === null ? null : Clock::fromStamp($current->locked_until);
                $lockHasExpired = $lockedUntil !== null && $lockedUntil->lessThanOrEqualTo($now);
                $failed = ($lockHasExpired ? 0 : (int) ($current->failed_login_count ?? 0)) + 1;

                DB::table('users')->where('users.id', $userId)->update([
                    'failed_login_count' => $failed,
                    'locked_until' => $failed >= self::MAX_FAILED_LOGINS ? Clock::stamp($now->copy()->addMinutes(self::LOCKOUT_MINUTES)) : null,
                    'updated_at' => Clock::stamp($now),
                ]);
            }

            // The attempted password is never recorded.
            AuditService::write(
                AuditAction::LoginFailed,
                'user',
                "Failed sign-in attempt for \"{$username}\"",
                $userId,
                $userId === null ? null : new Actor($userId, $username),
                at: $now,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                username: $username,
            );
        });
    }

    private static function lockedUntil(object $user, Carbon $now): ?Carbon
    {
        if ($user->locked_until === null) {
            return null;
        }
        $until = Clock::fromStamp($user->locked_until);

        return $until->greaterThan($now) ? $until : null;
    }

    /** Date.prototype.toISOString(): UTC, milliseconds, "Z". */
    private static function iso(Carbon $at): string
    {
        return $at->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
