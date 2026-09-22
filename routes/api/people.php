<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\People\GuestController;
use App\Http\Controllers\Api\V1\People\OwnerController;
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

Route::prefix('owners')->name('owners.')->group(function (): void {
    Route::get('/', [OwnerController::class, 'index'])
        ->middleware('permission:owners.view')->name('index');
    Route::post('/', [OwnerController::class, 'store'])
        ->middleware('permission:owners.create')->name('store');

    Route::get('{owner}', [OwnerController::class, 'show'])
        ->middleware('permission:owners.view')->name('show');
    Route::patch('{owner}', [OwnerController::class, 'update'])
        ->middleware('permission:owners.update')->name('update');

    // Deactivates rather than deletes: statements and payouts reference it.
    Route::delete('{owner}', [OwnerController::class, 'destroy'])
        ->middleware('permission:owners.update')->name('destroy');

    // Shares in properties. Dated, because properties change hands mid-year
    // and a statement attributes each night using the shares in force then.
    Route::post('{owner}/ownerships', [OwnerController::class, 'storeOwnership'])
        ->middleware('permission:owners.update')->name('ownerships.store');
    Route::patch('{owner}/ownerships/{ownership}', [OwnerController::class, 'updateOwnership'])
        ->middleware('permission:owners.update')->name('ownerships.update');
    Route::post('{owner}/ownerships/{ownership}/end', [OwnerController::class, 'endOwnership'])
        ->middleware('permission:owners.update')->name('ownerships.end');

    // Portal access is its own permission: granting a login is a different
    // decision from correcting somebody's postcode.
    Route::post('{owner}/portal', [OwnerController::class, 'enablePortal'])
        ->middleware('permission:owners.portal')->name('portal.enable');
    Route::delete('{owner}/portal', [OwnerController::class, 'disablePortal'])
        ->middleware('permission:owners.portal')->name('portal.disable');

    Route::get('{owner}/agreements', [OwnerController::class, 'agreements'])
        ->middleware('permission:owners.view')->name('agreements.index');
    Route::post('{owner}/agreements', [OwnerController::class, 'storeAgreement'])
        ->middleware('permission:owners.update')->name('agreements.store');
    Route::patch('{owner}/agreements/{agreement}', [OwnerController::class, 'updateAgreement'])
        ->middleware('permission:owners.update')->name('agreements.update');
});

// Ownership read from the property's side: who owns this, and how much of it
// is unallocated.
Route::get('properties/{property}/ownership', [OwnerController::class, 'propertyOwnership'])
    ->middleware('permission:owners.view')
    ->name('properties.ownership');
