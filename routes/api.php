<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Data\ExportingController;

use App\Http\Controllers\DSPR_Controller;
use App\Http\Controllers\Reports\StoreReportController;


Route::middleware('api.key')->group(function () {
    Route::get('/reports/store', StoreReportController::class)->name('reports.store');
    Route::get('/sold-with-alone', [StoreReportController::class, 'soldWithPizzaDetails'])->name('reports.sold_with_alone');

    // exporting route csv and excel
    Route::get('/export/{model}/csv/{start?}/{end?}/{hour?}/{stores?}', [ExportingController::class, 'exportCSV']);
});

Route::middleware('auth.verify')->group(function () {
    // exporting route json
    Route::get('/export/{model}/json/{start?}/{end?}/{hour?}/{stores?}', [ExportingController::class, 'exportJson'])
    ->name('export.json');

    Route::post('/dspr-report/{store}/{date}', [DSPR_Controller::class, 'index'])
    ->name('dspr-report');

// New: catalog of unique items (for populating the UI selector)
    Route::get('/dspr-items/{store}', [DSPR_Controller::class, 'items'])
    ->name('dspr-items');
});



