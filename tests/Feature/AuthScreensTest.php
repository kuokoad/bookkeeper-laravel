<?php

declare(strict_types=1);

/*
| The sign-in screens over HTTP: the old actions/auth.actions.ts (login,
| pinLogin, logout, setupOwnerAction), changeOwnPasswordAction and the theme
| switch. AuthService's own rules are tested in tests/Services/AuthTest.
*/

use App\Auth\Sessions;
use App\Enums\UserRole;
use App\Http\Controllers\Auth\LoginController;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

afterEach(fn () => Carbon::setTestNow());

describe('first run', function () {
    it('sends the first visitor to setup, and creates the owner and the shop once', function () {
        $this->get('/')->assertRedirect('/setup');
        $this->get('/login')->assertRedirect('/setup');
        $this->get('/setup')->assertOk()->assertSee('Create owner account');

        $this->post('/setup', [
            'businessName' => 'Adwoa Hardware', 'businessType' => 'building_materials', 'displayName' => 'Adwoa',
            'username' => 'adwoa', 'password' => 'a-good-password-1', 'confirmPassword' => 'a-good-password-1',
        ])->assertRedirect('/login?setup=complete');

        $settings = DB::table('business_settings')->first();
        expect(DB::table('users')->where('username', 'adwoa')->value('role'))->toBe('OWNER')
            ->and($settings->business_name)->toBe('Adwoa Hardware')
            ->and($settings->business_type)->toBe('building_materials')
            // The type's own switches, written explicitly: no dates and batches at a hardware yard.
            ->and((bool) $settings->feature_expiry_batches)->toBeFalse()
            ->and((bool) $settings->feature_quotations)->toBeTrue()
            ->and($settings->setup_completed_at)->not->toBeNull();

        $this->get('/setup')->assertRedirect('/login');
        $this->get('/login?setup=complete')->assertSee('Setup complete. Sign in with the owner account you just created.');
        $this->post('/setup', ['businessName' => 'X'])->assertSessionHasErrors(['form' => 'Setup has already been completed. Please sign in.']);
        expect(DB::table('users')->count())->toBe(1);
    });

    it('says what is wrong with each field, in the old words', function () {
        $this->from('/setup')->post('/setup', ['businessName' => 'A', 'displayName' => '', 'username' => 'a b', 'password' => 'short', 'confirmPassword' => 'other'])
            ->assertSessionHasErrors([
                'businessName' => 'Enter your shop name.',
                'displayName' => 'Enter your name.',
                'username' => 'Use only letters, numbers, dots, dashes or underscores.',
                'password' => 'Password must be at least 8 characters.',
                'confirmPassword' => 'The two passwords do not match.',
            ]);
        expect(AuthService::needsInitialSetup())->toBeTrue();
    });

    $valid = ['businessName' => 'Adwoa Hardware', 'displayName' => 'Adwoa', 'username' => 'adwoa', 'password' => 'a-good-password-1', 'confirmPassword' => 'a-good-password-1'];

    it('refuses a complete second setup, so nobody can mint a second owner', function () use ($valid) {
        $this->post('/setup', $valid)->assertRedirect('/login?setup=complete');
        $this->post('/setup', ['username' => 'kofi', 'displayName' => 'Kofi', 'businessName' => 'Kofi Stores'] + $valid)
            ->assertSessionHasErrors(['form' => 'Setup has already been completed. Please sign in.']);

        expect(DB::table('users')->pluck('username')->all())->toBe(['adwoa'])
            ->and(DB::table('business_settings')->value('business_name'))->toBe('Adwoa Hardware');
    });

    // What this proves is the re-check inside the write transaction; the row lock itself needs a second connection to show.
    it('re-checks for an account inside the write transaction, so a setup that passed the first check still loses', function () use ($valid) {
        // The other first-run post commits its owner while this one waits on the settings row.
        $raced = false;
        DB::listen(function ($query) use (&$raced) {
            if (! $raced && str_contains(strtolower($query->sql), 'business_settings') && str_contains(strtolower($query->sql), 'for update')) {
                $raced = true;
                AuthService::createUser(['username' => 'first', 'displayName' => 'First', 'password' => 'a-good-password-1', 'role' => UserRole::Owner]);
            }
        });

        $this->post('/setup', $valid)->assertSessionHasErrors(['form' => 'Setup has already been completed. Please sign in.']);
        expect($raced)->toBeTrue()
            ->and(DB::table('users')->pluck('username')->all())->toBe(['first']);
    });

    it('is reachable only from the shop PC itself unless the LAN is allowed', function () use ($valid) {
        $lan = ['REMOTE_ADDR' => '192.168.1.20'];
        $this->withServerVariables($lan)->get('/setup')->assertForbidden()->assertSee('on the shop computer itself')
            // The way out: the server PC's own browser, or the LAN switch for a moment.
            ->assertSee('http://localhost')->assertSee('NUNA_SETUP_FROM_LAN=true');
        $this->withServerVariables($lan)->post('/setup', $valid)->assertForbidden();
        // A forged proxy header does not make a LAN caller local.
        $this->withServerVariables($lan)->withHeader('X-Forwarded-For', '127.0.0.1')->get('/setup')->assertForbidden();
        expect(AuthService::needsInitialSetup())->toBeTrue();

        $this->withServerVariables(['REMOTE_ADDR' => '::1'])->get('/setup')->assertOk();
        config(['nuna.setup_from_lan' => true]);
        $this->withServerVariables($lan)->post('/setup', $valid)->assertRedirect('/login?setup=complete');
    });

    it('falls back to the peer address behind a trusted proxy that sent no client address', function () {
        config(['nuna.trust_proxy_headers' => true]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/setup')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])->get('/setup')->assertForbidden();
        // A client address the proxy reported still decides.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeader('X-Forwarded-For', '192.168.1.20')->get('/setup')->assertForbidden();
    });
});

describe('signing in', function () {
    beforeEach(function () {
        $this->ownerId = owner();
        $this->staffId = staff(['sales' => viewOnly()], 'ama', 'staff-password-2026', '8351');
    });

    it('signs in with a password, starting a fresh session that records what it was granted on', function () {
        $this->post('/login', ['username' => 'kwame', 'password' => 'owner-password-2026'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs(User::find($this->ownerId));
        expect(session(Sessions::FINGERPRINT))->toBe(Sessions::fingerprint($this->ownerId))
            ->and(session(Sessions::SIGNED_IN_AT))->toBeInt();
        $this->get('/login')->assertRedirect('/dashboard');
    });

    it('signs in at the till with a PIN, and words every refusal the same', function () {
        $this->post('/login/pin', ['username' => 'ama', 'pin' => '8351'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs(User::find($this->staffId));
    });

    it('refuses a wrong password or PIN without saying which part was wrong', function () {
        $this->post('/login', ['username' => 'kwame', 'password' => 'wrong'])->assertRedirect('/login')->assertSessionHasErrors(['form' => 'Incorrect username or password.']);
        $this->post('/login', ['username' => 'nobody', 'password' => 'wrong'])->assertSessionHasErrors(['form' => 'Incorrect username or password.']);
        $this->post('/login/pin', ['username' => 'kwame', 'pin' => '1234'])->assertRedirect('/login?mode=pin')->assertSessionHasErrors(['form' => 'Incorrect username or PIN.']);
        $this->assertGuest();
    });

    it('checks the fields before anything else', function () {
        $this->post('/login', ['username' => '', 'password' => ''])->assertSessionHasErrors(['username' => 'Enter your username.', 'password' => 'Enter your password.']);
        $this->post('/login/pin', ['username' => 'ama', 'pin' => '12'])->assertSessionHasErrors(['pin' => 'A till PIN is 4 to 8 digits.']);
        expect(DB::table('rate_limits')->count())->toBe(0);
    });

    it('locks a staff account after five wrong passwords, worded as any other wait', function () {
        foreach (range(1, 5) as $_) {
            $this->post('/login', ['username' => 'ama', 'password' => 'wrong']);
        }
        $this->post('/login', ['username' => 'ama', 'password' => 'staff-password-2026'])
            ->assertSessionHasErrors(['form' => LoginController::TOO_MANY_ATTEMPTS]);
        $this->assertGuest();
    });

    it('throttles the eleventh attempt in ten minutes on one username, password and PIN sharing one count', function () {
        foreach (range(1, 5) as $_) {
            $this->post('/login', ['username' => 'nobody', 'password' => 'wrong']);
            $this->post('/login/pin', ['username' => 'nobody', 'pin' => '0000']);
        }
        // The name is matched as sign-in matches it: any case, trimmed.
        $this->post('/login', ['username' => ' NoBody ', 'password' => 'wrong'])
            ->assertSessionHasErrors(['form' => LoginController::TOO_MANY_ATTEMPTS]);
        $this->assertGuest();
    });

    it('sends every name that cannot exist to one bucket', function () {
        foreach (range(1, 10) as $i) {
            $this->post('/login', ['username' => "no such {$i}", 'password' => 'wrong']);
        }
        $this->post('/login', ['username' => 'also/not-a-name', 'password' => 'wrong'])->assertSessionHasErrors(['form' => LoginController::TOO_MANY_ATTEMPTS]);
        expect(DB::table('rate_limits')->where('key', 'like', 'login:user:%')->pluck('key')->all())->toBe(['login:user:invalid']);
    });

    it('does not let wrong guesses on one name block another name (D15)', function () {
        foreach (range(1, 11) as $_) {
            $this->post('/login', ['username' => 'nobody', 'password' => 'wrong']);
        }
        $this->post('/login', ['username' => 'ama', 'password' => 'staff-password-2026'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs(User::find($this->staffId));
    });

    it('never throttles the owner by name: after 11 wrong guesses the right password works once the delay has passed', function () {
        $t = Carbon::parse('2026-11-06 09:00:00', 'UTC');
        Carbon::setTestNow($t);
        foreach (range(1, 11) as $_) {
            $this->post('/login', ['username' => 'kwame', 'password' => 'wrong']);
        }
        Carbon::setTestNow($t->copy()->addSeconds(AuthService::OWNER_DELAY_CAP_SECONDS));
        $this->post('/login', ['username' => 'KWAME', 'password' => 'owner-password-2026'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs(User::find($this->ownerId));
    });

    it('lets 51 guesses across the shop block staff but not the owner, once the owner delay has passed (D15)', function () {
        $t = Carbon::parse('2026-11-06 09:00:00', 'UTC');
        Carbon::setTestNow($t);
        foreach (range(1, 6) as $_) {
            $this->post('/login', ['username' => 'kwame', 'password' => 'wrong']);
        }
        foreach (range(1, AuthService::LOGIN_SHARED_LIMIT - 5) as $i) {
            $this->post('/login', ['username' => "guess{$i}", 'password' => 'wrong']);
        }
        $this->post('/login/pin', ['username' => 'ama', 'pin' => '8351'])->assertSessionHasErrors(['form' => LoginController::TOO_MANY_ATTEMPTS]);
        $this->post('/login', ['username' => 'ama', 'password' => 'staff-password-2026'])->assertSessionHasErrors(['form' => LoginController::TOO_MANY_ATTEMPTS]);
        $this->assertGuest();

        Carbon::setTestNow($t->copy()->addSeconds(AuthService::OWNER_DELAY_CAP_SECONDS));
        $this->post('/login', ['username' => 'kwame', 'password' => 'owner-password-2026'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs(User::find($this->ownerId));
    });

    it('clears only that name’s count on a successful sign-in, never the shared count (D15)', function () {
        $this->post('/login', ['username' => 'ama', 'password' => 'wrong']);
        $this->post('/login', ['username' => 'ama', 'password' => 'staff-password-2026'])->assertRedirect('/dashboard');
        expect(DB::table('rate_limits')->where('key', AuthService::throttleKeyFor('ama'))->exists())->toBeFalse()
            ->and((int) DB::table('rate_limits')->where('key', 'login:shared')->value('attempts'))->toBe(2);
    });

    it('behind a trusted proxy, counts the ceiling per address, the per-name bucket still applying (D15)', function () {
        config(['nuna.trust_proxy_headers' => true]);
        $this->post('/login', ['username' => 'ama', 'password' => 'wrong'], ['X-Forwarded-For' => '203.0.113.9']);
        expect(DB::table('rate_limits')->pluck('key')->sort()->values()->all())->toBe(['login:203.0.113.9', 'login:user:ama']);
    });

    it('words the owner delay, a staff lock and the throttle identically, so none reveals an owner', function () {
        Carbon::setTestNow(Carbon::parse('2026-11-06 09:00:00', 'UTC'));
        foreach (range(1, 5) as $_) {
            $this->post('/login', ['username' => 'kwame', 'password' => 'wrong']);
            $this->post('/login', ['username' => 'ama', 'password' => 'wrong']);
        }
        foreach (range(1, 11) as $_) {
            $this->post('/login', ['username' => 'nobody', 'password' => 'wrong']);
        }
        foreach ([['kwame', 'owner-password-2026'], ['ama', 'staff-password-2026'], ['nobody', 'wrong']] as [$name, $password]) {
            $this->post('/login', ['username' => $name, 'password' => $password])->assertSessionHasErrors(['form' => LoginController::TOO_MANY_ATTEMPTS]);
        }
        $this->assertGuest();
    });

    it('slows the owner down instead of locking them out, then says how many attempts failed (D16)', function () {
        $t = Carbon::parse('2026-11-06 09:00:00', 'UTC');
        Carbon::setTestNow($t);
        foreach (range(1, 5) as $_) {
            $this->post('/login', ['username' => 'kwame', 'password' => 'wrong']);
        }
        $this->post('/login', ['username' => 'kwame', 'password' => 'owner-password-2026'])
            ->assertSessionHasErrors(['form' => LoginController::TOO_MANY_ATTEMPTS]);
        $this->assertGuest();

        Carbon::setTestNow($t->copy()->addSeconds(2));
        $this->post('/login', ['username' => 'kwame', 'password' => 'owner-password-2026'])
            ->assertRedirect('/dashboard')->assertSessionHas('error', '5 failed sign-in attempts since your last sign-in.');
        $this->get('/dashboard')->assertSee('5 failed sign-in attempts since your last sign-in.');

        $this->post('/logout');
        $this->post('/login', ['username' => 'kwame', 'password' => 'owner-password-2026'])->assertRedirect('/dashboard')->assertSessionMissing('error');
    });

    it('refuses PIN sign-in for an owner, worded as a wrong PIN (D17)', function () {
        DB::table('users')->where('id', $this->ownerId)->update(['pin_hash' => DB::table('users')->where('id', $this->staffId)->value('pin_hash')]);
        $this->post('/login/pin', ['username' => 'kwame', 'pin' => '8351'])->assertSessionHasErrors(['form' => 'Incorrect username or PIN.']);
        $this->assertGuest();
        expect((int) DB::table('users')->where('id', $this->ownerId)->value('failed_login_count'))->toBe(1);
    });

    it('signs out, writing the audit row, and switches user to the till PIN tab', function () {
        signedIn($this->staffId)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        expect(DB::table('audit_logs')->where('action', 'LOGOUT')->where('user_id', $this->staffId)->count())->toBe(1);

        signedIn($this->ownerId)->post('/logout', ['switch' => '1'])->assertRedirect('/login?mode=pin');
        $this->get('/login?mode=pin')->assertSee('Sign in with PIN');
    });

    it('shows the demo credentials only while demo data is loaded', function () {
        $this->get('/login')->assertDontSee('Demo data is loaded');
        DB::table('business_settings')->update(['has_demo_data' => true]);
        $this->get('/login')->assertSee('Demo data is loaded');
    });
});

describe('your own password', function () {
    beforeEach(fn () => $this->id = staff([], 'ama', 'staff-password-2026'));

    it('changes it and clears the must-change flag, ending every other session but keeping this one (D13)', function () {
        DB::table('users')->where('id', $this->id)->update(['must_change_password' => true]);
        DB::table('sessions')->insert(['id' => 'till-2', 'user_id' => $this->id, 'payload' => '', 'last_activity' => time()]);
        $client = signedIn($this->id);
        $before = session()->getId();
        $client->post('/account/password', ['currentPassword' => 'staff-password-2026', 'newPassword' => 'a-new-password-2', 'confirmPassword' => 'a-new-password-2'])
            ->assertRedirect('/dashboard')->assertSessionHas('status', 'Password changed. You were signed out everywhere else.');

        $this->assertAuthenticatedAs(User::find($this->id));
        expect(DB::table('sessions')->where('id', 'till-2')->exists())->toBeFalse()
            ->and(session()->getId())->not->toBe($before)
            ->and(session(Sessions::FINGERPRINT))->toBe(Sessions::fingerprint($this->id))
            ->and((bool) DB::table('users')->where('id', $this->id)->value('must_change_password'))->toBeFalse();
        // EnforceSessionLimits takes the refreshed fingerprint: this session carries on.
        $this->get('/dashboard')->assertOk();
        $this->assertAuthenticated();
    });

    it('still signs in with the new password afterwards', function () {
        signedIn($this->id)->post('/account/password', ['currentPassword' => 'staff-password-2026', 'newPassword' => 'a-new-password-2', 'confirmPassword' => 'a-new-password-2']);
        $this->post('/logout');
        $this->post('/login', ['username' => 'ama', 'password' => 'a-new-password-2'])->assertRedirect('/dashboard');
    });

    it('keeps spaces in a password exactly as typed', function () {
        signedIn($this->id)->post('/account/password', ['currentPassword' => 'staff-password-2026', 'newPassword' => '  spaced out  ', 'confirmPassword' => '  spaced out  ']);
        $this->post('/logout');
        $this->post('/login', ['username' => 'ama', 'password' => 'spaced out'])->assertSessionHasErrors('form');
        $this->post('/login', ['username' => 'ama', 'password' => '  spaced out  '])->assertRedirect('/dashboard');
    });

    it('refuses a wrong current password with the service message', function () {
        signedIn($this->id)->post('/account/password', ['currentPassword' => 'nope', 'newPassword' => 'a-new-password-2', 'confirmPassword' => 'a-new-password-2'])
            ->assertSessionHasErrors(['form' => 'Your current password is not correct.']);
        $this->assertAuthenticated();
    });
});

it('remembers light or dark on this screen and paints it before the first byte of CSS', function () {
    $id = owner();
    signedIn($id)->post('/theme', ['theme' => 'dark'])->assertCookie('bk_theme', 'dark', false);
    $this->withUnencryptedCookie('bk_theme', 'dark');
    signedIn($id)->get('/dashboard')->assertSee('data-theme="dark"', false);
    signedIn($id)->post('/theme', ['theme' => 'junk'])->assertCookieExpired('bk_theme');
});

it('wears the shop’s look on every screen, sign-in included', function () {
    $this->get('/setup')->assertDontSee('data-look', false);
    DB::table('business_settings')->update(['look' => 'ledger']);
    $this->get('/setup')->assertSee('data-look="ledger"', false);
});

it('accepts the Jinu look in the database and wears it', function () {
    DB::table('business_settings')->update(['look' => 'jinu']);
    $this->get('/setup')->assertSee('data-look="jinu"', false);
});

it('accepts the Quikbooks look in the database and wears it', function () {
    DB::table('business_settings')->update(['look' => 'quikbooks']);
    $this->get('/setup')->assertSee('data-look="quikbooks"', false);
});

it('accepts the Mamut look in the database and wears it', function () {
    DB::table('business_settings')->update(['look' => 'mamut']);
    $this->get('/setup')->assertSee('data-look="mamut"', false);
});

it('accepts the Modern look in the database and wears it', function () {
    DB::table('business_settings')->update(['look' => 'modern']);
    $this->get('/setup')->assertSee('data-look="modern"', false);
});

it('accepts the Fable 5 look in the database and wears it', function () {
    DB::table('business_settings')->update(['look' => 'fable5']);
    $this->get('/setup')->assertSee('data-look="fable5"', false);
});
