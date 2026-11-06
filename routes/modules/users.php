<?php

declare(strict_types=1);

use App\Livewire\Users;
use Illuminate\Support\Facades\Route;

/* People, their rights, and the audit log (behind the same right as managing people). */

Route::get('/users', Users\Index::class)->middleware('permission:users,view');
Route::get('/users/new', Users\Create::class)->middleware('permission:users,create');
Route::get('/users/audit', Users\Audit::class)->middleware('permission:users,view');
Route::get('/users/{user}', Users\Show::class)->whereNumber('user')->middleware('permission:users,view');
