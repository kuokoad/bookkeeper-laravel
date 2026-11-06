<?php

declare(strict_types=1);

use App\Livewire\Settings\Index;
use Illuminate\Support\Facades\Route;

/*
| Settings. The example module route file: required by routes/web.php inside
| the signed-in group (auth, session.limits, password.chosen), so a module
| file adds only its own permission middleware.
*/

Route::get('/settings', Index::class)->middleware('permission:settings,view');
