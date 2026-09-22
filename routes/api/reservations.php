<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Reservations\CalendarController;
use App\Http\Controllers\Api\V1\Reservations\ReservationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reservations and the calendar
|--------------------------------------------------------------------------
*/

Route::prefix('reservations')->name('reservations.')->group(function (): void {
    Route::get('/', [ReservationController::class, 'index'])
        ->middleware('permission:reservations.view')->name('index');

    // Pricing a stay is a read, not a booking, so it needs only view access.
    Route::post('quote', [ReservationController::class, 'quote'])
        ->middleware('permission:reservations.view')->name('quote');

    Route::post('/', [ReservationController::class, 'store'])
        ->middleware('permission:reservations.create')->name('store');

    Route::get('{reservation}', [ReservationController::class, 'show'])
        ->middleware('permission:reservations.view')->name('show');

    // Changing dates, unit or occupancy re-prices and re-checks availability.
    Route::patch('{reservation}', [ReservationController::class, 'update'])
        ->middleware('permission:reservations.update')->name('update');

    // Notes and arrival times: no inventory or price consequences.
    Route::patch('{reservation}/details', [ReservationController::class, 'updateDetails'])
        ->middleware('permission:reservations.update')->name('details');

    Route::post('{reservation}/{action}', [ReservationController::class, 'transition'])
        ->whereIn('action', ['confirm', 'check-in', 'check-out', 'no-show'])
        ->middleware('permission:reservations.update,reservations.checkin')
        ->name('transition');

    Route::get('{reservation}/refund-preview', [ReservationController::class, 'refundPreview'])
        ->middleware('permission:reservations.view')->name('refund-preview');

    Route::post('{reservation}/cancel', [ReservationController::class, 'cancel'])
        ->middleware('permission:reservations.cancel')->name('cancel');

    Route::post('{reservation}/reinstate', [ReservationController::class, 'reinstate'])
        ->middleware('permission:reservations.reinstate')->name('reinstate');

    Route::post('{reservation}/charges', [ReservationController::class, 'addCharge'])
        ->middleware('permission:reservations.update')->name('charges.store');
});

Route::prefix('calendar')->name('calendar.')->group(function (): void {
    Route::get('/', [CalendarController::class, 'index'])
        ->middleware('permission:calendar.view')->name('index');

    Route::post('check', [CalendarController::class, 'check'])
        ->middleware('permission:calendar.view')->name('check');

    Route::patch('listings/{listing}/days', [CalendarController::class, 'updateDays'])
        ->middleware('permission:calendar.update')->name('days.update');

    Route::post('blocks', [CalendarController::class, 'storeBlock'])
        ->middleware('permission:calendar.update')->name('blocks.store');

    Route::delete('blocks/{block}', [CalendarController::class, 'destroyBlock'])
        ->middleware('permission:calendar.update')->name('blocks.destroy');
});
