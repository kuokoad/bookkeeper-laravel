<?php

declare(strict_types=1);

use App\Livewire\Accounts\Index;
use App\Livewire\Accounts\Show;
use App\Livewire\Accounts\Transfers;
use Illuminate\Support\Facades\Route;

/* Payment accounts, transfers and one account's statement (the old (app)/accounts). */

Route::get('/accounts', Index::class)->middleware('permission:accounts,view');
// Before /accounts/{id}, or "transfers" would be read as an account.
Route::get('/accounts/transfers', Transfers::class)->middleware('permission:accounts,view');
Route::get('/accounts/{id}', Show::class)->middleware('permission:accounts,view');
