<?php

declare(strict_types=1);

use App\Http\Controllers\Customers\StatementController;
use App\Livewire\Customers;
use Illuminate\Support\Facades\Route;

/* Customers (group A): the old (app)/customers pages. Receiving and voiding payments are checked in the actions. */

Route::get('/customers', Customers\Index::class)->middleware('permission:customers,view');
Route::get('/customers/new', Customers\Form::class)->middleware('permission:customers,create');
Route::get('/customers/{id}', Customers\Show::class)->whereNumber('id')->middleware('permission:customers,view');
Route::get('/customers/{id}/edit', Customers\Form::class)->whereNumber('id')->middleware('permission:customers,edit');
Route::get('/customers/{id}/statement', StatementController::class)->whereNumber('id')->middleware('permission:customers,view');
