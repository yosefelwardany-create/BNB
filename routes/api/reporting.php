<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Reporting\ReportController;
use App\Http\Controllers\Api\V1\Reporting\SavedReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reporting
|--------------------------------------------------------------------------
|
| `reports.view` is the coarse gate: it says whether somebody may open the
| reporting section at all. It is not what decides which reports they can run.
| Each report declares its own permission — an occupancy report and an owner
| profit-and-loss are not the same disclosure — and the catalogue is filtered
| to what the caller can actually open rather than listing everything and
| refusing later.
|
| Reports are hand-written classes, never stored queries. A saved report holds
| a key and some parameters, so saving or scheduling one can never become a way
| to run arbitrary SQL against a multi-tenant database.
|
*/

Route::prefix('reports')->name('reports.')->group(function (): void {
    // The catalogue, already narrowed to what this caller may run.
    Route::get('/', [ReportController::class, 'index'])
        ->middleware('permission:reports.view')->name('index');

    // Where a scheduled report can be sent, and what each destination needs.
    // Above the {key} routes for the same reason "saved" is.
    Route::get('destinations', [SavedReportController::class, 'destinations'])
        ->middleware('permission:reports.view')->name('destinations');

    /*
     * Saved reports sit above the {key} routes so that "saved" is never
     * mistaken for a report key.
     */
    Route::prefix('saved')->name('saved.')->group(function (): void {
        Route::get('/', [SavedReportController::class, 'index'])
            ->middleware('permission:reports.view')->name('index');

        Route::post('/', [SavedReportController::class, 'store'])
            ->middleware('permission:reports.view')->name('store');

        Route::get('{savedReport}', [SavedReportController::class, 'show'])
            ->middleware('permission:reports.view')->name('show');

        Route::patch('{savedReport}', [SavedReportController::class, 'update'])
            ->middleware('permission:reports.view')->name('update');

        Route::post('{savedReport}/run', [SavedReportController::class, 'run'])
            ->middleware('permission:reports.view')->name('run');

        Route::delete('{savedReport}', [SavedReportController::class, 'destroy'])
            ->middleware('permission:reports.view')->name('destroy');
    });

    Route::get('{key}', [ReportController::class, 'run'])
        ->middleware('permission:reports.view')->name('run');

    // Exporting is its own permission: reading a figure on screen and carrying
    // a spreadsheet of it out of the building are different acts.
    Route::get('{key}/export', [ReportController::class, 'export'])
        ->middleware('permission:reports.export')->name('export');
});
