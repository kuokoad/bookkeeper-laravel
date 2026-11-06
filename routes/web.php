<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ThemeController;
use App\Livewire\Dashboard;
use App\Services\AuthService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
| The shell's routes. Module screens do NOT go here: each module adds
| routes/modules/<module>.php, required below inside the signed-in group, and
| puts `permission:<module>,<action>` on every route (docs/UI-CONVENTIONS.md).
| tests/Feature/RouteGuardTest.php refuses a route that has neither.
*/

// Entry point: first run -> setup, signed in -> dashboard, otherwise -> sign in.
Route::get('/', fn () => redirect(match (true) {
    AuthService::needsInitialSetup() => '/setup',
    Auth::guard('web')->check() => '/dashboard',
    default => '/login',
}));

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/login/pin', [LoginController::class, 'pinLogin']);
Route::get('/setup', [SetupController::class, 'show']);
Route::post('/setup', [SetupController::class, 'store']);

// Signed in, but reachable before choosing your own password.
Route::middleware(['auth', 'session.limits'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::get('/account/password', [PasswordController::class, 'show']);
    Route::post('/account/password', [PasswordController::class, 'update']);
    Route::post('/theme', ThemeController::class);
});

// CSV downloads answer 401/403/404 in plain text; the permission is per list, checked in the controller.
Route::middleware('session.limits')->group(function () {
    Route::get('/export/list/{list}', [ExportController::class, 'list']);
    Route::get('/export/report/{report}', [ExportController::class, 'report']);
});

Route::middleware(['auth', 'session.limits', 'password.chosen'])->group(function () {
    // Any signed-in person: every sign-in lands here, and the no-access page explains a refusal.
    Route::get('/dashboard', Dashboard::class);
    Route::view('/no-access', 'no-access');

    $modules = glob(__DIR__.'/modules/*.php') ?: [];
    sort($modules);
    foreach ($modules as $file) {
        require $file;
    }
});
