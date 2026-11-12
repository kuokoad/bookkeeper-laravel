<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\ClientAddress;
use App\Auth\Principal;
use App\Auth\Sessions;
use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\SettingsService;
use App\Support\RateLimit;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Sign-in by password or till PIN, sign-out and switching user: the old
 * actions/auth.actions.ts login, pinLogin and logout. AuthService decides;
 * this throttles, starts or ends the session, and words the answer.
 *
 * Both doors count against the same two buckets (D15), so a PIN is not a
 * second set of guesses: 10 per 10 minutes for the typed username, and a
 * looser 50 for every sign-in in the shop (per address instead behind a
 * trusted proxy, ClientAddress::throttleKey). Guessing at one name never
 * blocks another below that ceiling, and a success clears only its own
 * name's bucket, never the shared count.
 *
 * An owner account is never held back by either bucket (the owner's
 * decision on D15/D16): its attempts are counted like anyone's, so the
 * work and the writes are the same, but only the D16 delay (60 s at most)
 * stops them. Nobody can lock the owner out by guessing.
 *
 * The wording is uniform: a guesser must not learn whether a username
 * exists, is switched off, has a PIN, or is an owner. A staff lock, the
 * owner delay and the throttle all say TOO_MANY_ATTEMPTS.
 */
final class LoginController extends Controller
{
    public const TOO_MANY_ATTEMPTS = 'Too many attempts. Wait a few minutes and try again.';

    public function show(Request $request): View|RedirectResponse
    {
        if (AuthService::needsInitialSetup()) {
            return redirect('/setup');
        }
        if (Auth::guard('web')->check()) {
            return redirect('/dashboard');
        }
        $settings = SettingsService::get();

        return view('auth.login', [
            'shopName' => $settings->business_name ?: 'NunaBooks',
            'mode' => $request->query('mode') === 'pin' || old('mode') === 'pin' ? 'pin' : 'password',
            'setupComplete' => $request->query('setup') === 'complete',
            // Both must hold: demo rows in the database AND not production.
            'demo' => ! app()->isProduction() && (bool) $settings->has_demo_data ? ['owner' => DemoSeeder::DEMO_OWNER, 'staff' => DemoSeeder::DEMO_STAFF] : null,
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:40'],
            'password' => ['required', 'string', 'max:200'],
        ], [
            'username.required' => 'Enter your username.',
            'password.required' => 'Enter your password.',
        ]);

        return $this->attempt($request, 'password', fn (?string $ip, ?string $ua) => AuthService::login($data['username'], $data['password'], $ip, $ua), 'Incorrect username or password.');
    }

    public function pinLogin(Request $request): RedirectResponse
    {
        $request->merge(['mode' => 'pin']);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:40'],
            'pin' => ['required', 'string', 'regex:/^\d{4,8}$/'],
        ], [
            'username.required' => 'Enter your username.',
            'pin.required' => 'A till PIN is 4 to 8 digits.',
            'pin.regex' => 'A till PIN is 4 to 8 digits.',
        ]);

        return $this->attempt($request, 'pin', fn (?string $ip, ?string $ua) => AuthService::loginWithPin($data['username'], $data['pin'], $ip, $ua), 'Incorrect username or PIN.');
    }

    /** Signing out, or "Switch user": the same, then the till PIN tab for the next person. */
    public function logout(Request $request): RedirectResponse
    {
        $id = Auth::guard('web')->id();
        $principal = $id === null ? null : Principal::load((int) $id);
        if ($principal !== null) {
            AuthService::logout($principal, ClientAddress::fromRequest($request), $request->userAgent());
        }
        Sessions::end($request);

        return redirect($request->boolean('switch') ? '/login?mode=pin' : '/login');
    }

    private function attempt(Request $request, string $mode, callable $login, string $refused): RedirectResponse
    {
        $ip = ClientAddress::fromRequest($request);
        $username = (string) $request->input('username');
        $key = AuthService::throttleKeyFor($username);
        // Every attempt is counted the same way; the owner is only exempt from being refused by the count.
        $isOwner = AuthService::isOwnerUsername($username);
        $shared = RateLimit::hit(ClientAddress::throttleKey('login', $ip), AuthService::LOGIN_SHARED_LIMIT, AuthService::LOGIN_WINDOW_MS);
        $own = RateLimit::hit($key, AuthService::LOGIN_ATTEMPT_LIMIT, AuthService::LOGIN_WINDOW_MS);
        if (! $isOwner && (! $shared['allowed'] || ! $own['allowed'])) {
            return $this->refuse($request, $mode, self::TOO_MANY_ATTEMPTS);
        }

        $result = $login($ip, $request->userAgent());
        if (! $result['ok']) {
            $waiting = in_array($result['reason'], ['TOO_MANY_ATTEMPTS', 'ACCOUNT_LOCKED'], true);

            return $this->refuse($request, $mode, $waiting ? self::TOO_MANY_ATTEMPTS : $refused);
        }

        RateLimit::reset($key);
        Sessions::begin($request, $result['user']);

        // A starting password set by someone else is changed first (EnsurePasswordChosen).
        $failed = $result['failedAttemptsSinceLastSignIn'];

        // Back to the page that sent them here (a sign-in that ended mid-form), else the dashboard.
        return $failed === 0 ? redirect()->intended('/dashboard')
            : redirect()->intended('/dashboard')->with('error', "{$failed} failed sign-in attempt".($failed === 1 ? '' : 's').' since your last sign-in.');
    }

    private function refuse(Request $request, string $mode, string $message): RedirectResponse
    {
        return redirect('/login'.($mode === 'pin' ? '?mode=pin' : ''))
            ->withErrors(['form' => $message])
            ->withInput(['username' => $request->input('username'), 'mode' => $mode]);
    }
}
