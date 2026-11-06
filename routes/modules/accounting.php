<?php

declare(strict_types=1);

use App\Livewire\Accounting;
use Illuminate\Support\Facades\Route;

/* Accounting: the books check, books lock and year-end close, and the ledger pages (old (app)/accounting). */

Route::middleware('permission:accounts,view')->group(function () {
    Route::get('/accounting', Accounting\Index::class);
    Route::get('/accounting/chart', Accounting\Chart::class);
    Route::get('/accounting/journal', Accounting\Journal::class);
    Route::get('/accounting/journal/{id}', Accounting\Entry::class);
    Route::get('/accounting/ledger/{id}', Accounting\Ledger::class);
    Route::get('/accounting/trial-balance', Accounting\TrialBalance::class);
    Route::get('/accounting/receivables', Accounting\Receivables::class);
    Route::get('/accounting/payables', Accounting\Payables::class);
});
