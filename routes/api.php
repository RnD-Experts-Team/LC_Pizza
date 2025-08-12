<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Data\ExportingController;


Route::middleware('api.key')->group(function () {
    // exporting route csv and excel
    Route::get('/export/{model}/csv/{start?}/{end?}/{hour?}/{stores?}', [ExportingController::class, 'exportCSV']);
});

Route::middleware('auth.verify')->group(function () {
    // exporting route json
    Route::get('/export/{model}/json/{start?}/{end?}/{hour?}/{stores?}', [ExportingController::class, 'exportJson'])
    ->name('export.json');
});
