<?php

use Illuminate\Support\Facades\Route;


use App\Http\Controllers\Data\ExportController;
Route::get('/export-final-summary/{start_date?}/{end_date?}/{franchise_store?}', [ExportController::class, 'exportFinalSummary']);
Route::get('/final-summary-json/{start_date?}/{end_date?}/{franchise_store?}', [ExportController::class, 'getFinalSummaryJson']);
Route::get('/final-summary-csv/{start_date?}/{end_date?}/{franchise_store?}', [ExportController::class, 'exportFinalSummaryCsv']);



// Routes for ExportUpsellingController
use App\Http\Controllers\Data\ExportUpsellingController;
Route::get('/upselling-summary/{start_date?}/{end_date?}/{franchise_store?}/{menu_items?}', [ExportUpsellingController::class, 'exportFinalSummary']);
Route::get('/upselling-summary-json/{start_date?}/{end_date?}/{franchise_store?}/{menu_items?}', [ExportUpsellingController::class, 'getFinalSummaryJson']);
Route::get('/upselling-summary-csv/{start_date?}/{end_date?}/{franchise_store?}/{menu_items?}', [ExportUpsellingController::class, 'exportFinalSummaryCsv']);
