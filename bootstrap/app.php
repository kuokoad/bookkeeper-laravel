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
        $middleware->redirectUsersTo('/dashboard');
        // Light or dark is read on the server before the first paint; it is not a secret.
        $middleware->encryptCookies(except: ['bk_theme']);
        // A password is what was typed, spaces included, as the old forms took it.
        $middleware->trimStrings(except: ['password', 'currentPassword', 'newPassword', 'confirmPassword']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
