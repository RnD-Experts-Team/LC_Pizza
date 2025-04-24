<?php

use Illuminate\Support\Facades\Route;


use App\Http\Controllers\Data\ExportController;
Route::get('/export-final-summary/{start_date?}/{end_date?}/{franchise_store?}', [ExportController::class, 'exportFinalSummary']);
Route::get('/final-summary-json/{start_date?}/{end_date?}/{franchise_store?}', [ExportController::class, 'getFinalSummaryJson']);
Route::get('/final-summary-csv/{start_date?}/{end_date?}/{franchise_store?}', [ExportController::class, 'exportFinalSummaryCsv']);



// Routes for ExportHourlySalesController
use App\Http\Controllers\Data\ExportHourlySalesController;
Route::get('/hourly-sales/{start_date?}/{end_date?}/{franchise_store?}', [ExportHourlySalesController::class, 'exportHourlySales']);
Route::get('/hourly-sales-json/{start_date?}/{end_date?}/{franchise_store?}', [ExportHourlySalesController::class, 'getHourlySalesJson']);
Route::get('/hourly-sales-csv/{start_date?}/{end_date?}/{franchise_store?}', [ExportHourlySalesController::class, 'exportHourlySalesCsv']);



