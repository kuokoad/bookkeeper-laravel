<?php

declare(strict_types=1);

/*
| The sign-in screens over HTTP: the old actions/auth.actions.ts (login,
| pinLogin, logout, setupOwnerAction), changeOwnPasswordAction and the theme
| switch. AuthService's own rules are tested in tests/Services/AuthTest.
*/

use App\Auth\Sessions;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Support\Facades\DB;

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

    it('locks an account after five wrong passwords and says for how long', function () {
        foreach (range(1, 5) as $_) {
            $this->post('/login', ['username' => 'ama', 'password' => 'wrong']);
        }
        $this->post('/login', ['username' => 'ama', 'password' => 'staff-password-2026'])
            ->assertSessionHasErrors(['form' => 'This account is temporarily locked after too many failed attempts. Try again in 15 minute(s).']);
        $this->assertGuest();
    });

    it('throttles the eleventh attempt in ten minutes, password and PIN sharing one count', function () {
        foreach (range(1, 5) as $_) {
            $this->post('/login', ['username' => 'nobody', 'password' => 'wrong']);
            $this->post('/login/pin', ['username' => 'nobody', 'pin' => '0000']);
        }
        $this->post('/login', ['username' => 'kwame', 'password' => 'owner-password-2026'])
            ->assertSessionHasErrors(['form' => 'Too many sign-in attempts. Please wait 10 minute(s) and try again.']);
        $this->assertGuest();
    });

    it('clears the count on a successful sign-in', function () {
        $this->post('/login', ['username' => 'kwame', 'password' => 'wrong']);
        $this->post('/login', ['username' => 'kwame', 'password' => 'owner-password-2026'])->assertRedirect('/dashboard');
        expect(DB::table('rate_limits')->count())->toBe(0);
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

    it('changes it, ends every session including this one, and clears the must-change flag', function () {
        DB::table('users')->where('id', $this->id)->update(['must_change_password' => true]);
        signedIn($this->id)->post('/account/password', ['currentPassword' => 'staff-password-2026', 'newPassword' => 'a-new-password-2', 'confirmPassword' => 'a-new-password-2'])
            ->assertRedirect('/login?passwordChanged=1');
        $this->assertGuest();
        expect((bool) DB::table('users')->where('id', $this->id)->value('must_change_password'))->toBeFalse();
        $this->post('/login', ['username' => 'ama', 'password' => 'a-new-password-2'])->assertRedirect('/dashboard');
    });

    it('keeps spaces in a password exactly as typed', function () {
        signedIn($this->id)->post('/account/password', ['currentPassword' => 'staff-password-2026', 'newPassword' => '  spaced out  ', 'confirmPassword' => '  spaced out  ']);
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
