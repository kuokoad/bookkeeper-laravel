<?php

declare(strict_types=1);

use App\Livewire\Help;
use Illuminate\Support\Facades\Route;

/*
| In-app Help, for everyone who can sign in: the staff member who most needs
| to read how the till works holds the fewest rights. No permission, so both
| routes are on RouteGuardTest's open list.
*/

Route::get('/help', Help\Index::class);
Route::get('/help/{slug}', Help\Show::class);
