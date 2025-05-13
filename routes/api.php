<?php


use Illuminate\Support\Facades\Route;
use App\Http\Middleware\ApiKeyMiddleware;

use App\Http\Controllers\Data\ExportController;
Route::get('/final-summary-json/{start_date?}/{end_date?}/{franchise_store?}', [ExportController::class, 'getFinalSummaryJson']);
Route::get('/final-summary-csv/{start_date?}/{end_date?}/{franchise_store?}', [ExportController::class, 'exportFinalSummaryCsv']);

// Routes for ExportHourlySalesController
use App\Http\Controllers\Data\ExportHourlySalesController;
Route::get('/hourly-sales-json/{start_date?}/{end_date?}/{franchise_store?}', [ExportHourlySalesController::class, 'getHourlySalesJson']);
Route::get('/hourly-sales-csv/{start_date?}/{end_date?}/{franchise_store?}', [ExportHourlySalesController::class, 'exportHourlySalesCsv']);

//Upselling
use App\Http\Controllers\Data\ExportUpsellingController;
Route::get('/upselling-summary-json/{start_date?}/{end_date?}/{franchise_store?}', [ExportUpsellingController::class, 'getUpsellingJson']);
Route::get('/upselling-summary-csv/{start_date?}/{end_date?}/{franchise_store?}', [ExportUpsellingController::class, 'exportUpsellingCsv']);


Route::middleware(ApiKeyMiddleware::class)->group(function () {
    Route::get('/export-upselling-summary/{start_date?}/{end_date?}/{franchise_store?}', [ExportUpsellingController::class, 'exportUpselling']);
    Route::get('/hourly-sales/{start_date?}/{end_date?}/{franchise_store?}', [ExportHourlySalesController::class, 'exportHourlySales']);
    Route::get('/export-final-summary/{start_date?}/{end_date?}/{franchise_store?}', [ExportController::class, 'exportFinalSummary']);
});
