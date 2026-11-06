<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Sessions;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a signed-in session that has outlived the old app's rules: past 30
 * days from sign-in, the account switched off, or its password, role or
 * permissions changed since. Runs on every route behind sign-in (Phase 9),
 * after Laravel's own idle-lifetime check.
 */
final class EnforceSessionLimits
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if ($user instanceof User && ! Sessions::stillValid($request->session()->get(Sessions::SIGNED_IN_AT), $request->session()->get(Sessions::FINGERPRINT), $user)) {
            Sessions::end($request);

            // Thrown, not returned: Livewire's persistent middleware honours only a redirect or an exception.
            abort_if($request->expectsJson(), 401, 'Please sign in.');

            return redirect()->guest('/login');
        }

        return $next($request);
    }
}
