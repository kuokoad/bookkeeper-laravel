<?php

declare(strict_types=1);

use App\Livewire\Products;
use App\Reports\Csv\Csv;
use App\Services\ProductImportService;
use Illuminate\Support\Facades\Route;

Route::get('/products', Products\Index::class)->middleware('permission:products,view');
Route::get('/products/new', Products\Form::class)->middleware('permission:products,create');
Route::get('/products/{id}/edit', Products\Form::class)->whereNumber('id')->middleware('permission:products,edit');
// It creates a product and moves stock, so it needs all three rights, as the old page did.
Route::get('/products/{id}/replace', Products\Replace::class)->whereNumber('id')
    ->middleware(['permission:products,edit', 'permission:products,create', 'permission:inventory,create']);
Route::get('/products/categories', Products\Categories::class)->middleware('permission:products,view');
Route::get('/products/units', Products\Units::class)->middleware('permission:products,view');
Route::get('/products/import', Products\Import::class)->middleware('permission:products,create');

// The import template: the heading row only, so no example row is imported by mistake.
Route::get('/products/import/template', fn () => response(Csv::toCsv(ProductImportService::TEMPLATE_HEADINGS, []), 200, [
    'Content-Type' => 'text/csv; charset=utf-8',
    'Content-Disposition' => 'attachment; filename="bookkeeper_product-import-template.csv"',
    'Cache-Control' => 'no-store',
]))->middleware('permission:products,create');
