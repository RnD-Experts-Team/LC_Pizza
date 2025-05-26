<?php


use Illuminate\Support\Facades\Route;
use App\Http\Middleware\ApiKeyMiddleware;
use App\Http\Controllers\Data\ExportFinanceController;
use App\Http\Controllers\Data\ExportBreadBoostController;

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
// Finance Data Export Routes
Route::get('/finance/export-csv/{start_date?}/{end_date?}/{franchise_store?}', [ExportFinanceController::class, 'exportCSVs']);
Route::get('/finance/export-json/{start_date?}/{end_date?}/{franchise_store?}', [ExportFinanceController::class, 'exportJson']);


// Bread Boost Export Routes
Route::get('/export/bread-boost/CSv/{start_date?}/{end_date?}/{franchise_store?}', [ExportBreadBoostController::class, 'exportCSV']);
Route::get('/export/bread-boost/json/{start_date?}/{end_date?}/{franchise_store?}', [ExportBreadBoostController::class, 'exportJson']);

Route::middleware(ApiKeyMiddleware::class)->group(function () {
    Route::get('/export-upselling-summary/{start_date?}/{end_date?}/{franchise_store?}', [ExportUpsellingController::class, 'exportUpselling']);
    Route::get('/hourly-sales/{start_date?}/{end_date?}/{franchise_store?}', [ExportHourlySalesController::class, 'exportHourlySales']);
    Route::get('/export-final-summary/{start_date?}/{end_date?}/{franchise_store?}', [ExportController::class, 'exportFinalSummary']);
    Route::get('/finance/export-excel/{start_date?}/{end_date?}/{franchise_store?}', [ExportFinanceController::class, 'exportToExcel']);
    Route::get('/export/bread-boost/excel/{start_date?}/{end_date?}/{franchise_store?}', [ExportBreadBoostController::class, 'exportExcel']);
});

