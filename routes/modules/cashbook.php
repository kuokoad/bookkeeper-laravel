<?php

declare(strict_types=1);

use App\Livewire\Cashbook\Expenses;
use App\Livewire\Cashbook\Income;
use Illuminate\Support\Facades\Route;

/* Cashbook: expenses and other income (the old (app)/expenses and (app)/income). */

Route::get('/expenses', Expenses::class)->middleware('permission:expenses,view');
Route::get('/income', Income::class)->middleware('permission:income,view');
