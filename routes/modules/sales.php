<?php

declare(strict_types=1);

use App\Http\Controllers\Sales\SaleDocumentController;
use App\Livewire\Sales;
use Illuminate\Support\Facades\Route;

/* Sales and the till (group A): the old (app)/sales pages. Voiding is checked in the action. */

Route::get('/sales', Sales\Index::class)->middleware('permission:sales,view');
Route::get('/sales/new', Sales\Pos::class)->middleware('permission:sales,create');
Route::get('/sales/{id}', Sales\Show::class)->whereNumber('id')->middleware('permission:sales,view');
Route::get('/sales/{id}/receipt', [SaleDocumentController::class, 'receipt'])->whereNumber('id')->middleware('permission:sales,view');
Route::get('/sales/{id}/invoice', [SaleDocumentController::class, 'invoice'])->whereNumber('id')->middleware('permission:sales,view');
