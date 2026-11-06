<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Somebody whose password was set by someone else chooses their own before
 * anything else: the old (app)/layout.tsx redirect, on every app route. The
 * password page sits outside this middleware, so it needs no exception.
 */
final class EnsurePasswordChosen
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = Auth::guard('web')->id();
        if ($id !== null && (bool) DB::table('users')->where('users.id', $id)->value('must_change_password')) {
            // Thrown, not returned: Livewire's persistent middleware honours only a redirect or an exception.
            abort_if($request->expectsJson(), 403, 'Choose your own password first.');

            return redirect('/account/password?required=1');
        }

        return $next($request);
    }
}
