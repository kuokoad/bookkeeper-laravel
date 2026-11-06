<?php

declare(strict_types=1);

use App\Livewire\Suppliers;
use Illuminate\Support\Facades\Route;

Route::get('/suppliers', Suppliers\Index::class)->middleware('permission:suppliers,view');
Route::get('/suppliers/new', Suppliers\Form::class)->middleware('permission:suppliers,create');
Route::get('/suppliers/{id}', Suppliers\Show::class)->whereNumber('id')->middleware('permission:suppliers,view');
Route::get('/suppliers/{id}/edit', Suppliers\Form::class)->whereNumber('id')->middleware('permission:suppliers,edit');
