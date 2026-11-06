<?php

declare(strict_types=1);

use App\Livewire\Purchases;
use Illuminate\Support\Facades\Route;

Route::get('/purchases', Purchases\Index::class)->middleware('permission:purchases,view');
Route::get('/purchases/new', Purchases\Create::class)->middleware('permission:purchases,create');
Route::get('/purchases/{id}', Purchases\Show::class)->whereNumber('id')->middleware('permission:purchases,view');
