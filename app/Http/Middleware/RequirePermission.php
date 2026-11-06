<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\PermissionModule;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * `permission:sales,create` on a route: the old requirePermission (an
 * action) and requirePageAccess (a page) in one. Not signed in: to the
 * sign-in screen (401 for code). Refused: a page goes to /no-access, which
 * explains; anything else gets 403. The decision itself is the Gate ability
 * "sales.create" (AppServiceProvider), which also refuses a person who has
 * not yet chosen their own password.
 */
final class RequirePermission
{
    public function handle(Request $request, Closure $next, string $module, string $action): Response
    {
        if (! Auth::guard('web')->check()) {
            return $request->expectsJson() ? response('Please sign in.', 401) : redirect()->guest('/login');
        }
        $area = PermissionModule::from($module);
        if (Gate::forUser(Auth::guard('web')->user())->denies("{$area->value}.{$action}")) {
            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                return redirect('/no-access?area='.rawurlencode($area->value));
            }

            return response('You do not have permission to do that.', 403);
        }

        return $next($request);
    }
}
