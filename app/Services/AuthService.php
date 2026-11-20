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
    /** Consecutive failures before a staff account is locked, or an owner's attempts are slowed (D16). */
    public const MAX_FAILED_LOGINS = 5;

    /** The first staff lock: long enough to stop guessing, short enough to trade. Each repeat doubles, up to LOCKOUT_FORGET_HOURS. */
    public const LOCKOUT_MINUTES = 15;

    /** Only stops the doubling shift overflowing; the day's cap is reached well before it. */
    private const MAX_LOCKOUT_DOUBLINGS = 10;

    /** The longest a staff lock grows to, and how long after a lock ends the earlier ones stop counting. */
    public const LOCKOUT_FORGET_HOURS = 24;

    /** An owner is never locked; from the fifth failure in a row each one doubles the wait, up to this (D16). */
    public const OWNER_DELAY_CAP_SECONDS = 60;

    /** The sign-in throttle in front of the lockout, per typed username (D15). */
    public const LOGIN_ATTEMPT_LIMIT = 10;

    /** The looser ceiling over every sign-in: shop-wide, or per address behind a trusted proxy (D15). */
    public const LOGIN_SHARED_LIMIT = 50;

    public const LOGIN_WINDOW_MS = 10 * 60 * 1000;

    public const OWNER_PIN_REFUSED = 'An owner signs in with their password only, so an owner account cannot have a till PIN.';

    private const USERNAME_PATTERN = '/^[a-zA-Z0-9._-]{3,40}\z/';

    /**
     * @return array{ok: true, user: User, principal: Principal, mustChangePassword: bool, failedAttemptsSinceLastSignIn: int}|array{ok: false, reason: 'INVALID_CREDENTIALS'|'ACCOUNT_LOCKED'|'ACCOUNT_INACTIVE'|'TOO_MANY_ATTEMPTS', lockedUntil?: Carbon, retryAfterSeconds?: int}
     */
    public static function login(string $username, string $password, ?string $ipAddress = null, ?string $userAgent = null, ?Carbon $now = null): array
    {
        $now ??= Clock::now();
        $username = JsCompat::trim($username);
        $user = self::lookUpSignInName($username);

        if ($user === null) {
            // The same work as a real check, so timing does not reveal which usernames exist.
            Passwords::burn();
            self::recordFailedAttempt(null, $username, $ipAddress, $userAgent, $now);

            return ['ok' => false, 'reason' => 'INVALID_CREDENTIALS'];
        }

        $lockedUntil = self::isOwner($user) ? self::claimOwnerTurn($user, $now) : self::lockedUntil($user, $now);
        if ($lockedUntil !== null && self::isOwner($user)) {
            $seconds = max(1, (int) ceil(($lockedUntil->getTimestampMs() - $now->getTimestampMs()) / 1000));
            Tx::run(fn () => AuditService::write(AuditAction::LoginFailed, 'user', "Owner sign-in attempt refused: {$seconds} s left of the delay after repeated failures", $user->id, new Actor((int) $user->id, $user->username), at: $now, ipAddress: $ipAddress, userAgent: $userAgent));

            return ['ok' => false, 'reason' => 'TOO_MANY_ATTEMPTS', 'retryAfterSeconds' => $seconds];
        }
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
     * answers exactly as a wrong PIN does. An owner never signs in with a
     * PIN (D17): a PIN they still hold is ignored, and the attempt answers
     * and counts as a wrong one.
     *
     * @return array{ok: true, user: User, principal: Principal, mustChangePassword: bool, failedAttemptsSinceLastSignIn: int}|array{ok: false, reason: 'INVALID_CREDENTIALS'|'ACCOUNT_LOCKED'|'ACCOUNT_INACTIVE', lockedUntil?: Carbon}
     */
    public static function loginWithPin(string $username, string $pin, ?string $ipAddress = null, ?string $userAgent = null, ?Carbon $now = null): array
    {
        $now ??= Clock::now();
        $username = JsCompat::trim($username);
        $user = self::lookUpSignInName($username);

        if ($user === null || $user->pin_hash === null || self::isOwner($user)) {
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

        $pin = $input['pin'] ?? null;
        if ($input['role'] === UserRole::Owner && $pin !== null && $pin !== '') {
            throw new ValidationError(self::OWNER_PIN_REFUSED);
        }
        $passwordHash = Passwords::hashPassword($input['password']);
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

    /** The per-username sign-in bucket, matched as findByUsername matches: trimmed, any case (D15). */
    public static function throttleKeyFor(string $username): string
    {
        $username = JsCompat::trim($username);

        // Only ASCII gets this far, so strtolower folds exactly as MySQL's LOWER() in the lookup.
        return 'login:user:'.(self::isWellFormedUsername($username) ? strtolower($username) : 'invalid');
    }

    /** Whether a typed name belongs to an owner account. A lookup for every well-formed name, owner or not. */
    public static function isOwnerUsername(string $username): bool
    {
        $user = self::lookUpSignInName(JsCompat::trim($username));

        return $user !== null && self::isOwner($user);
    }

    /** A trimmed name that could be an account: as createUser would accept it. */
    private static function isWellFormedUsername(string $username): bool
    {
        return preg_match(self::USERNAME_PATTERN, $username) === 1;
    }

    /** A sign-in's lookup: a name no account could have is not looked up at all, and fails as an unknown one. */
    private static function lookUpSignInName(string $trimmed): ?object
    {
        return self::isWellFormedUsername($trimmed) ? self::findByUsername($trimmed) : null;
    }

    /** 1 s at the fifth failure in a row, doubling with each one after, never more than the cap (D16). */
    public static function ownerDelaySeconds(int $consecutiveFailures): int
    {
        if ($consecutiveFailures < self::MAX_FAILED_LOGINS) {
            return 0;
        }

        return min(self::OWNER_DELAY_CAP_SECONDS, 2 ** min(30, $consecutiveFailures - self::MAX_FAILED_LOGINS));
    }

    /** @param  'password_hash'|'pin_hash'  $column */
    private static function succeed(object $user, string $column, string $secret, string $summary, ?string $ipAddress, ?string $userAgent, Carbon $now): array
    {
        $verified = $user->{$column};
        // Made before the write: a hash is slow, a row lock should not be.
        $rehashed = Passwords::needsRehash($verified) ? Hash::make($column === 'password_hash' ? LegacyScrypt::normalize($secret) : $secret) : null;

        $failedBefore = 0;
        $ok = Tx::run(function () use ($user, $column, $verified, $rehashed, $summary, $ipAddress, $userAgent, $now, &$failedBefore): bool {
            $current = DB::table('users')->where('users.id', $user->id)->lockForUpdate()->first();
            // Reset or changed while this check ran: what was checked is no longer the credential.
            if ($current === null || $current->{$column} !== $verified || ! (bool) $current->is_active) {
                return false;
            }
            // No lock ever cuts an owner's count short, so it is every failure since they last signed in (D16).
            $failedBefore = self::isOwner($current) ? (int) $current->failed_login_count : 0;
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
            'failedAttemptsSinceLastSignIn' => $failedBefore,
        ];
    }

    private static function recordFailedAttempt(?int $userId, string $username, ?string $ipAddress, ?string $userAgent, Carbon $now): void
    {
        Tx::run(function () use ($userId, $username, $ipAddress, $userAgent, $now): void {
            if ($userId !== null) {
                $current = DB::table('users')->where('users.id', $userId)->lockForUpdate()->first();
                $owner = $current !== null && self::isOwner($current);
                // The count runs on past a served lockout, so each further run of failures locks for
                // twice as long (the old app started again at 15 minutes every time, which let a
                // 4-digit PIN be worked through in weeks). A day after the last lock ended it is
                // forgotten, or one slip a month later would lock for hours.
                // Not an owner's: their count runs on, for the delay and the notice at their next sign-in.
                $lockedUntil = $current?->locked_until === null ? null : Clock::fromStamp($current->locked_until);
                // An attempt refused before the lock was looked at (a PIN tried on an account with none) must not
                // count while the lock runs, or the lock could be stretched to a day without one guess being checked.
                $lockRunning = ! $owner && $lockedUntil !== null && $lockedUntil->greaterThan($now);
                $forgotten = ! $owner && $lockedUntil !== null && $lockedUntil->copy()->addHours(self::LOCKOUT_FORGET_HOURS)->lessThanOrEqualTo($now);
                $failed = ($forgotten ? 0 : (int) ($current->failed_login_count ?? 0)) + 1;
                $lockouts = intdiv($failed, self::MAX_FAILED_LOGINS);
                // An owner's locked_until is only the end of a short delay, never a lock.
                $until = match (true) {
                    $owner => $failed < self::MAX_FAILED_LOGINS ? null : $now->copy()->addSeconds(self::ownerDelaySeconds($failed)),
                    $failed % self::MAX_FAILED_LOGINS === 0 => $now->copy()->addMinutes(min(self::LOCKOUT_FORGET_HOURS * 60, self::LOCKOUT_MINUTES << min($lockouts - 1, self::MAX_LOCKOUT_DOUBLINGS))),
                    // Kept after it has passed: it is how the next failure knows how long ago the last lock ended.
                    default => $forgotten ? null : $lockedUntil,
                };

                if (! $lockRunning) {
                    DB::table('users')->where('users.id', $userId)->update([
                        'failed_login_count' => $failed,
                        'locked_until' => $until === null ? null : Clock::stamp($until),
                        'updated_at' => Clock::stamp($now),
                    ]);
                }
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

    /**
     * An owner being slowed gets one guess checked per delay. The delay is
     * read under the row lock and the next one started before the password is
     * checked, so guesses sent together when a delay ends do not all get
     * through on the same stale read: the rest find the delay running.
     * A wrong password then sets the delay from the new count; the right one clears it.
     *
     * @return ?Carbon when the delay still running ends, or null when this attempt may be checked
     */
    private static function claimOwnerTurn(object $user, Carbon $now): ?Carbon
    {
        return Tx::run(function () use ($user, $now): ?Carbon {
            $current = DB::table('users')->where('users.id', $user->id)->lockForUpdate()->first();
            if ($current === null) {
                return null;
            }
            $until = self::lockedUntil($current, $now);
            $failed = (int) $current->failed_login_count;
            if ($until === null && self::ownerDelaySeconds($failed) > 0) {
                DB::table('users')->where('users.id', $user->id)->update([
                    'locked_until' => Clock::stamp($now->copy()->addSeconds(self::ownerDelaySeconds($failed + 1))),
                    'updated_at' => Clock::stamp($now),
                ]);
            }

            return $until;
        });
    }

    private static function lockedUntil(object $user, Carbon $now): ?Carbon
    {
        if ($user->locked_until === null) {
            return null;
        }
        $until = Clock::fromStamp($user->locked_until);
        // An owner locked for 15 minutes before D16 waits no longer than the cap.
        if (self::isOwner($user)) {
            $until = $until->min($now->copy()->addSeconds(self::OWNER_DELAY_CAP_SECONDS));
        }

        return $until->greaterThan($now) ? $until : null;
    }

    private static function isOwner(object $user): bool
    {
        return $user->role === UserRole::Owner->value;
    }

    /** Date.prototype.toISOString(): UTC, milliseconds, "Z". */
    private static function iso(Carbon $at): string
    {
        return $at->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
