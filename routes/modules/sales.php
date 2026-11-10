<?php

declare(strict_types=1);

use App\Http\Controllers\Sales\SaleDocumentController;
use App\Livewire\Sales;
use Illuminate\Support\Facades\Route;

/* Sales and the till (group A): the old (app)/sales pages. Voiding is checked in the action. */

Route::get('/sales', Sales\Index::class)->middleware('permission:sales,view');
Route::get('/sales/new', Sales\Pos::class)->middleware('permission:sales,create');
// D34: editing is owner-only; the component checks the owner, the route only the module.
Route::get('/sales/{id}/edit', Sales\Pos::class)->whereNumber('id')->middleware('permission:sales,view');
Route::get('/sales/{id}', Sales\Show::class)->whereNumber('id')->middleware('permission:sales,view');
Route::get('/sales/{id}/receipt', [SaleDocumentController::class, 'receipt'])->whereNumber('id')->middleware('permission:sales,view');
Route::get('/sales/{id}/invoice', [SaleDocumentController::class, 'invoice'])->whereNumber('id')->middleware('permission:sales,view');
