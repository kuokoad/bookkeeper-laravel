<?php

declare(strict_types=1);

use App\Http\Controllers\Search\SuggestController;
use App\Livewire\Search;
use Illuminate\Support\Facades\Route;

/*
| Search, for everyone who can sign in: SearchService queries only the
| record types the person may view, so a till operator looking up a product
| is never refused by the box in their own top bar. On RouteGuardTest's open list.
*/

Route::get('/search', Search\Index::class);
Route::get('/search/suggest', SuggestController::class);
