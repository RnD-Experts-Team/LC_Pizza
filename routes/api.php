<?php
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\ApiKeyMiddleware;
use App\Http\Controllers\Data\ExportingController;


Route::middleware(ApiKeyMiddleware::class)->group(function () {
    // exporting route csv and excel
    Route::get('/export/{model}/csv/{start?}/{end?}/{hour?}/{stores?}', [ExportingController::class, 'exportCSV']);
});

// exporting route json
Route::get('/export/{model}/json/{start?}/{end?}/{hour?}/{stores?}', [ExportingController::class, 'exportJson']);
