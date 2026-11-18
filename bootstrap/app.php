<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // routes/web.php: ['auth', 'session.limits', 'password.chosen'] on the app group, 'permission:sales,create' per route.
        $middleware->alias([
            'permission' => App\Http\Middleware\RequirePermission::class,
            'session.limits' => App\Http\Middleware\EnforceSessionLimits::class,
            'password.chosen' => App\Http\Middleware\EnsurePasswordChosen::class,
        ]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
        // Light or dark is read on the server before the first paint; it is not a secret.
        $middleware->encryptCookies(except: ['bk_theme']);
        // A password is what was typed, spaces included, as the old forms took it.
        $middleware->trimStrings(except: ['password', 'currentPassword', 'newPassword', 'confirmPassword']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Files updated but `migrate` not run: pages die on missing columns. Say so, instead of "something went wrong".
        // Checked only on an error that would be a 500, so a working page pays nothing.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (config('app.debug') // a developer wants the stack trace
                || $e instanceof Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof Illuminate\Http\Exceptions\HttpResponseException
                || $e instanceof Illuminate\Auth\AuthenticationException
                || $e instanceof Illuminate\Validation\ValidationException) {
                return null;
            }
            try {
                $pending = App\Services\PreflightService::pendingMigrations();
            } catch (Throwable) {
                return null; // database unreachable: the ordinary error page
            }
            if ($pending === []) {
                return null;
            }
            $message = 'The last update was not finished: the database has not been brought up to date. Run scripts\update.ps1 again (or `php artisan migrate --force` with the full path to php.exe).';

            return $request->is('api/*') || $request->expectsJson()
                ? response()->json(['message' => $message], 503)
                : response()->view('errors.update-pending', ['count' => $pending === null ? null : count($pending)], 503);
        });
    })->create();
