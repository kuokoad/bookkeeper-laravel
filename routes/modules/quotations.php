<?php

declare(strict_types=1);

use App\Http\Controllers\Quotations\PrintController;
use App\Livewire\Quotations;
use Illuminate\Support\Facades\Route;

/* Quotations (group A): the old (app)/quotations pages. Converting and cancelling are checked in the actions. */

Route::get('/quotations', Quotations\Index::class)->middleware('permission:quotations,view');
Route::get('/quotations/new', Quotations\Form::class)->middleware('permission:quotations,create');
Route::get('/quotations/{id}', Quotations\Show::class)->whereNumber('id')->middleware('permission:quotations,view');
Route::get('/quotations/{id}/edit', Quotations\Form::class)->whereNumber('id')->middleware('permission:quotations,edit');
Route::get('/quotations/{id}/print', PrintController::class)->whereNumber('id')->middleware('permission:quotations,view');
