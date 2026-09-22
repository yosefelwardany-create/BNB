<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\People\GuestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guests and owners
|--------------------------------------------------------------------------
*/

Route::prefix('guests')->name('guests.')->group(function (): void {
    Route::get('/', [GuestController::class, 'index'])
        ->middleware('permission:guests.view')->name('index');
    Route::post('/', [GuestController::class, 'store'])
        ->middleware('permission:guests.create')->name('store');

    Route::get('duplicates', [GuestController::class, 'duplicateClusters'])
        ->middleware('permission:guests.merge')->name('duplicates');

    Route::get('{guest}', [GuestController::class, 'show'])
        ->middleware('permission:guests.view')->name('show');
    Route::patch('{guest}', [GuestController::class, 'update'])
        ->middleware('permission:guests.update')->name('update');

    Route::get('{guest}/duplicates', [GuestController::class, 'duplicates'])
        ->middleware('permission:guests.merge')->name('duplicates.show');
    Route::post('{guest}/merge', [GuestController::class, 'merge'])
        ->middleware('permission:guests.merge')->name('merge');
});
