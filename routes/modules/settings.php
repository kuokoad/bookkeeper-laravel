<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\BackupController;
use App\Http\Controllers\Settings\LogoController;
use App\Http\Controllers\Settings\UpdateStatusController;
use App\Livewire\Settings\Health;
use App\Livewire\Settings\Index;
use Illuminate\Support\Facades\Route;

/*
| Settings, the logo, and the Health screen (backup and update). Required by
| routes/web.php inside the signed-in group (auth, session.limits,
| password.chosen), so each route adds only its own permission.
*/

Route::get('/settings', Index::class)->middleware('permission:settings,view');
Route::get('/settings/health', Health::class)->middleware('permission:settings,view');

// Printed on every receipt, which till staff without the settings right print too: signed in is enough.
Route::get('/logo', [LogoController::class, 'show']);
Route::post('/settings/logo', [LogoController::class, 'store'])->middleware('permission:settings,edit');
Route::post('/settings/logo/remove', [LogoController::class, 'destroy'])->middleware('permission:settings,edit');

Route::post('/settings/backup', BackupController::class)->middleware('permission:settings,edit');
Route::get('/settings/update', UpdateStatusController::class)->middleware('permission:settings,edit');
