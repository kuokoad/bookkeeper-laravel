<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\ClientAddress;
use App\Database\Tx;
use App\Domain\Errors\DomainError;
use App\Enums\BusinessType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * First run: the shop and its owner account, once. The old (auth)/setup page
 * and setupOwnerAction. Refused as soon as any account exists, so it can
 * never mint a second owner. New: only from the shop PC itself (unless
 * NUNA_SETUP_FROM_LAN), so nobody on the LAN can claim the owner account first.
 */
final class SetupController extends Controller
{
    private const ALREADY_DONE = 'Setup has already been completed. Please sign in.';

    public function show(Request $request): View|RedirectResponse
    {
        self::refuseUnlessLocal($request);
        if (! AuthService::needsInitialSetup()) {
            return redirect('/login');
        }

        return view('auth.setup', ['types' => BusinessType::cases()]);
    }

    public function store(Request $request): RedirectResponse
    {
        self::refuseUnlessLocal($request);
        if (! AuthService::needsInitialSetup()) {
            return back()->withErrors(['form' => self::ALREADY_DONE]);
        }

        $data = $request->validate([
            'businessName' => ['required', 'string', 'min:2', 'max:120'],
            'displayName' => ['required', 'string', 'min:2', 'max:80'],
            'username' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'password' => ['required', 'string', 'min:8', 'max:200'],
            'confirmPassword' => ['same:password'],
        ], [
            'businessName.required' => 'Enter your shop name.',
            'businessName.min' => 'Enter your shop name.',
            'displayName.required' => 'Enter your name.',
            'displayName.min' => 'Enter your name.',
            'username.required' => 'Username must be at least 3 characters.',
            'username.min' => 'Username must be at least 3 characters.',
            'username.regex' => 'Use only letters, numbers, dots, dashes or underscores.',
            'password.required' => 'Password must be at least 8 characters.',
            'password.min' => 'Password must be at least 8 characters.',
            'confirmPassword.same' => 'The two passwords do not match.',
        ]);
        // At first run nothing is at stake: an unknown type starts the shop as general retail.
        $type = BusinessType::tryFrom((string) $request->input('businessType')) ?? BusinessType::GeneralRetail;

        try {
            // Two first-run posts queue on the settings row; the second then sees the first one's owner.
            $created = Tx::run(function () use ($data, $type): bool {
                DB::table('business_settings')->where('id', 1)->lockForUpdate()->first();
                if (! AuthService::needsInitialSetup()) {
                    return false;
                }
                AuthService::createUser(['username' => $data['username'], 'displayName' => $data['displayName'], 'password' => $data['password'], 'role' => UserRole::Owner]);
                SettingsService::completeSetup($data['businessName'], $type);

                return true;
            });
        } catch (DomainError $e) {
            return back()->withErrors(['form' => $e->userMessage])->withInput($request->except('password', 'confirmPassword'));
        }
        if (! $created) {
            return back()->withErrors(['form' => self::ALREADY_DONE]);
        }

        return redirect('/login?setup=complete');
    }

    /**
     * The proxy's report of the caller when one is trusted and sent it, else the peer's own address;
     * never a header a caller wrote.
     */
    private static function refuseUnlessLocal(Request $request): void
    {
        if (config('nuna.setup_from_lan') === true) {
            return;
        }
        $address = (config('nuna.trust_proxy_headers') === true ? ClientAddress::fromRequest($request) : null) ?? $request->server('REMOTE_ADDR');
        if (! in_array($address, ['127.0.0.1', '::1'], true)) {
            abort(response('Set up NunaBooks on the shop computer itself: open it there, in its own browser, at http://localhost '
                .'(not its network address, which counts as another computer). To set it up from another computer instead, set NUNA_SETUP_FROM_LAN=true '
                .'in the .env file on the shop computer, finish setup, then set it back to false.', 403, ['Content-Type' => 'text/plain']));
        }
    }
}
