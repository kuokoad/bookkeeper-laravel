<?php

declare(strict_types=1);

use App\Livewire\Purchases;
use Illuminate\Support\Facades\Route;

Route::get('/purchases', Purchases\Index::class)->middleware('permission:purchases,view');
Route::get('/purchases/new', Purchases\Create::class)->middleware('permission:purchases,create');
// D34: editing is owner-only; the component checks the owner, the route only the module.
Route::get('/purchases/{id}/edit', Purchases\Create::class)->whereNumber('id')->middleware('permission:purchases,view');
Route::get('/purchases/{id}', Purchases\Show::class)->whereNumber('id')->middleware('permission:purchases,view');
