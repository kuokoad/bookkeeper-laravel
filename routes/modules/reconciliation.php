<?php

declare(strict_types=1);

use App\Livewire\Reconciliation\Index;
use Illuminate\Support\Facades\Route;

/* Counting cash, MoMo and bank against the books (the old (app)/reconciliation). */

Route::get('/reconciliation', Index::class)->middleware('permission:reconciliation,view');
