<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use App\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sign-in sessions on Laravel's own machinery (the database session store),
 * with the old app's limits (lib/auth/session.ts):
 *
 *  - idle: a week without a request ends it (config session.lifetime);
 *  - absolute: 30 days from sign-in, however busy the till;
 *  - a password change or reset, a role or permission change, or switching
 *    the account off ends every session of that person.
 *
 * The last is done twice: the person's session rows are deleted in the
 * write that changes them, and each request compares a fingerprint of the
 * password hash, role and permissions taken at sign-in with the database
 * (EnforceSessionLimits). The second catches a request already in flight,
 * which Laravel would otherwise save back as a fresh row when it finishes.
 *
 * Old session rows are not imported (PLAN Q7): everyone signs in once.
 */
final class Sessions
{
    public const ABSOLUTE_MAX_DAYS = 30;

    public const SIGNED_IN_AT = 'nuna.signed_in_at';

    public const FINGERPRINT = 'nuna.auth_fingerprint';

    /** After AuthService::login succeeded: a fresh session id, the user, and what it was granted on. */
    public static function begin(Request $request, User $user, ?Carbon $now = null): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(self::SIGNED_IN_AT, ($now ?? Clock::now())->getTimestampMs());
        $request->session()->put(self::FINGERPRINT, self::fingerprint((int) $user->id));
    }

    public static function end(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /** Whether a session holding these values may still be used by this person. */
    public static function stillValid(mixed $signedInAtMs, mixed $fingerprint, ?User $user, ?Carbon $now = null): bool
    {
        if ($user === null || ! $user->is_active || ! is_int($signedInAtMs) || ! is_string($fingerprint)) {
            return false;
        }
        if (($now ?? Clock::now())->getTimestampMs() >= $signedInAtMs + self::ABSOLUTE_MAX_DAYS * 86_400_000) {
            return false;
        }

        return hash_equals(self::fingerprint((int) $user->id), $fingerprint);
    }

    /** What a session was granted on: the password hash, the role and the permission rows. */
    public static function fingerprint(int $userId): string
    {
        $user = DB::table('users')->where('users.id', $userId)->first(['password_hash', 'role']);
        $rights = Principal::permissionMap($userId);
        ksort($rights);

        return hash('sha256', ($user->password_hash ?? '').'|'.($user->role ?? '').'|'.json_encode($rights));
    }

    /** Every session this person holds, on every till. Called inside the write that changes them. */
    public static function invalidateAllFor(int $userId): int
    {
        return DB::table((string) config('session.table', 'sessions'))->where('user_id', $userId)->delete();
    }
}
