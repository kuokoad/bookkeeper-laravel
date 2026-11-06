<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\Sessions;
use App\Domain\Errors\DomainError;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Changing your own password, including the forced change for somebody
 * whose password was set by someone else: the old (account)/account/password
 * page. It sits outside EnsurePasswordChosen, so it needs no exception.
 *
 * Every OTHER session of the person ends; this one carries on under a fresh
 * id and a fingerprint of the new password, so EnforceSessionLimits keeps it
 * (PLAN D13: what the old comment intended, not what its code did).
 */
final class PasswordController extends Controller
{
    public function show(Request $request): View
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return view('auth.password', [
            'user' => $user,
            'shopName' => SettingsService::get()->business_name ?: 'NunaBooks',
            'required' => $request->query('required') === '1' || $user->must_change_password,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:8', 'max:200'],
            'confirmPassword' => ['same:newPassword'],
        ], [
            'currentPassword.required' => 'Enter your current password.',
            'newPassword.required' => 'Password must be at least 8 characters.',
            'newPassword.min' => 'Password must be at least 8 characters.',
            'confirmPassword.same' => 'The two passwords do not match.',
        ]);

        try {
            UserService::changeOwnPassword((int) Auth::guard('web')->id(), $data['currentPassword'], $data['newPassword']);
        } catch (DomainError $e) {
            return back()->withErrors(['form' => $e->userMessage]);
        }
        // The service deleted every session row of theirs, this one's too; begin() saves it under a new id.
        Sessions::begin($request, User::query()->findOrFail((int) Auth::guard('web')->id()));

        return redirect('/dashboard')->with('status', 'Password changed. You were signed out everywhere else.');
    }
}
