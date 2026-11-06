<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\ClientAddress;
use App\Auth\Principal;
use App\Auth\Sessions;
use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\SettingsService;
use App\Support\Clock;
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
 * Both doors share ONE throttle key, so a PIN is not a second set of guesses.
 * The wording is uniform: a guesser must not learn whether a username exists,
 * is switched off, or has a PIN at all.
 */
final class LoginController extends Controller
{
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
        $key = ClientAddress::throttleKey('login', $ip);
        $throttle = RateLimit::hit($key, AuthService::LOGIN_ATTEMPT_LIMIT, AuthService::LOGIN_WINDOW_MS);
        if (! $throttle['allowed']) {
            $minutes = (int) ceil($throttle['retryAfterMs'] / 60_000);

            return $this->refuse($request, $mode, "Too many sign-in attempts. Please wait {$minutes} minute(s) and try again.");
        }

        $result = $login($ip, $request->userAgent());
        if (! $result['ok']) {
            if ($result['reason'] === 'ACCOUNT_LOCKED') {
                $minutes = max(1, (int) ceil(($result['lockedUntil']->getTimestampMs() - Clock::now()->getTimestampMs()) / 60_000));

                return $this->refuse($request, $mode, "This account is temporarily locked after too many failed attempts. Try again in {$minutes} minute(s).");
            }

            return $this->refuse($request, $mode, $refused);
        }

        RateLimit::reset($key);
        Sessions::begin($request, $result['user']);

        // A starting password set by someone else is changed first (EnsurePasswordChosen).
        return redirect('/dashboard');
    }

    private function refuse(Request $request, string $mode, string $message): RedirectResponse
    {
        return redirect('/login'.($mode === 'pin' ? '?mode=pin' : ''))
            ->withErrors(['form' => $message])
            ->withInput(['username' => $request->input('username'), 'mode' => $mode]);
    }
}
