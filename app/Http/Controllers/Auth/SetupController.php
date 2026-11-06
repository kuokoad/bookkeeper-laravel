<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Errors\DomainError;
use App\Enums\BusinessType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * First run: the shop and its owner account, once. The old (auth)/setup page
 * and setupOwnerAction. Refused as soon as any account exists, so it can
 * never mint a second owner.
 */
final class SetupController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (! AuthService::needsInitialSetup()) {
            return redirect('/login');
        }

        return view('auth.setup', ['types' => BusinessType::cases()]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! AuthService::needsInitialSetup()) {
            return back()->withErrors(['form' => 'Setup has already been completed. Please sign in.']);
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
            AuthService::createUser(['username' => $data['username'], 'displayName' => $data['displayName'], 'password' => $data['password'], 'role' => UserRole::Owner]);
        } catch (DomainError $e) {
            return back()->withErrors(['form' => $e->userMessage])->withInput($request->except('password', 'confirmPassword'));
        }
        SettingsService::completeSetup($data['businessName'], $type);

        return redirect('/login?setup=complete');
    }
}
