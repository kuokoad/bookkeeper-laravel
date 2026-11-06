<?php

declare(strict_types=1);

use App\Livewire\Reports;
use Illuminate\Support\Facades\Route;

/* Reports (the old (app)/reports, whose layout refused anyone without reports,view). */

Route::middleware('permission:reports,view')->group(function () {
    Route::get('/reports', Reports\Index::class);
    Route::get('/reports/profit-and-loss', Reports\ProfitAndLoss::class);
    Route::get('/reports/balance-sheet', Reports\BalanceSheet::class);
    Route::get('/reports/cash-flow', Reports\CashFlow::class);
    Route::get('/reports/sales', Reports\Sales::class);
    Route::get('/reports/purchases', Reports\Purchases::class);
    Route::get('/reports/inventory', Reports\Inventory::class);
    Route::get('/reports/tax', Reports\Tax::class);
    Route::get('/reports/year-end', Reports\YearEnd::class);
});
