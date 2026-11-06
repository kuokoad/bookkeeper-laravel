<?php

declare(strict_types=1);

use App\Livewire\Inventory;
use Illuminate\Support\Facades\Route;

Route::get('/inventory', Inventory\Index::class)->middleware('permission:inventory,view');
Route::get('/inventory/adjustments', Inventory\Adjustments::class)->middleware('permission:inventory,view');
Route::get('/inventory/adjustments/new', Inventory\NewAdjustment::class)->middleware('permission:inventory,create');
Route::get('/inventory/adjustments/{id}', Inventory\Adjustment::class)->whereNumber('id')->middleware('permission:inventory,view');
// The old batches/[id] page asks products:view, not inventory:view; the old page wins (UI-CONVENTIONS §9).
Route::get('/inventory/batches/{id}', Inventory\Batch::class)->whereNumber('id')->middleware('permission:products,view');
