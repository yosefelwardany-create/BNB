<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Agents\PropertyAgentController;
use App\Http\Controllers\Api\V1\Properties\AmenityController;
use App\Http\Controllers\Api\V1\Properties\CancellationPolicyController;
use App\Http\Controllers\Api\V1\Properties\ListingController;
use App\Http\Controllers\Api\V1\Properties\PortfolioController;
use App\Http\Controllers\Api\V1\Properties\PropertyController;
use App\Http\Controllers\Api\V1\Properties\PropertyPhotoController;
use App\Http\Controllers\Api\V1\Properties\UnitController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Estate: portfolios, properties, units and listings
|--------------------------------------------------------------------------
|
| Route middleware is the coarse filter; every controller action additionally
| authorizes the specific record through its policy, which is where property
| restrictions for on-site staff are applied.
|
*/

Route::prefix('portfolios')->name('portfolios.')->group(function (): void {
    Route::get('/', [PortfolioController::class, 'index'])
        ->middleware('permission:portfolios.view')->name('index');
    Route::post('/', [PortfolioController::class, 'store'])
        ->middleware('permission:portfolios.manage')->name('store');
    Route::get('{portfolio}', [PortfolioController::class, 'show'])
        ->middleware('permission:portfolios.view')->name('show');
    Route::patch('{portfolio}', [PortfolioController::class, 'update'])
        ->middleware('permission:portfolios.manage')->name('update');
    Route::delete('{portfolio}', [PortfolioController::class, 'destroy'])
        ->middleware('permission:portfolios.manage')->name('destroy');
});

Route::prefix('amenities')->name('amenities.')->group(function (): void {
    Route::get('/', [AmenityController::class, 'index'])
        ->middleware('permission:properties.view')->name('index');
    Route::post('/', [AmenityController::class, 'store'])
        ->middleware('permission:properties.create')->name('store');
});

Route::prefix('cancellation-policies')->name('cancellation-policies.')->group(function (): void {
    Route::get('/', [CancellationPolicyController::class, 'index'])
        ->middleware('permission:properties.view')->name('index');
    Route::post('/', [CancellationPolicyController::class, 'store'])
        ->middleware('permission:properties.update')->name('store');
    Route::patch('{policy}', [CancellationPolicyController::class, 'update'])
        ->middleware('permission:properties.update')->name('update');
    Route::post('{policy}/preview', [CancellationPolicyController::class, 'preview'])
        ->middleware('permission:properties.view')->name('preview');
});

Route::prefix('properties')->name('properties.')->group(function (): void {
    Route::get('/', [PropertyController::class, 'index'])
        ->middleware('permission:properties.view')->name('index');
    Route::post('/', [PropertyController::class, 'store'])
        ->middleware('permission:properties.create')->name('store');
    Route::get('{property}', [PropertyController::class, 'show'])
        ->middleware('permission:properties.view')->name('show');
    Route::patch('{property}', [PropertyController::class, 'update'])
        ->middleware('permission:properties.update')->name('update');
    Route::delete('{property}', [PropertyController::class, 'destroy'])
        ->middleware('permission:properties.delete')->name('destroy');

    Route::get('{property}/readiness', [PropertyController::class, 'readiness'])
        ->middleware('permission:properties.view')->name('readiness');
    Route::post('{property}/activate', [PropertyController::class, 'activate'])
        ->middleware('permission:properties.update')->name('activate');
    Route::post('{property}/deactivate', [PropertyController::class, 'deactivate'])
        ->middleware('permission:properties.update')->name('deactivate');

    // Units live under their property, because a unit is meaningless without
    // one and the property is what carries the access restrictions.
    Route::get('{property}/units', [UnitController::class, 'index'])
        ->middleware('permission:units.view')->name('units.index');
    Route::post('{property}/units', [UnitController::class, 'store'])
        ->middleware('permission:units.create')->name('units.store');
    Route::post('{property}/units/bulk', [UnitController::class, 'bulkCreate'])
        ->middleware('permission:units.create')->name('units.bulk');

    Route::get('{property}/listings', [ListingController::class, 'index'])
        ->middleware('permission:listings.view')->name('listings.index');
    Route::post('{property}/listings', [ListingController::class, 'store'])
        ->middleware('permission:listings.create')->name('listings.store');

    Route::get('{property}/photos', [PropertyPhotoController::class, 'index'])
        ->middleware('permission:properties.view')->name('photos.index');
    Route::post('{property}/photos', [PropertyPhotoController::class, 'store'])
        ->middleware('permission:properties.update')->name('photos.store');
    Route::post('{property}/photos/reorder', [PropertyPhotoController::class, 'reorder'])
        ->middleware('permission:properties.update')->name('photos.reorder');
});

Route::prefix('photos')->name('properties.photos.')->group(function (): void {
    Route::get('{photo}', [PropertyPhotoController::class, 'show'])
        ->middleware('permission:properties.view')->name('show');
    Route::patch('{photo}', [PropertyPhotoController::class, 'update'])
        ->middleware('permission:properties.update')->name('update');
    Route::delete('{photo}', [PropertyPhotoController::class, 'destroy'])
        ->middleware('permission:properties.update')->name('destroy');
});

Route::prefix('units')->name('units.')->group(function (): void {
    Route::get('{unit}', [UnitController::class, 'show'])
        ->middleware('permission:units.view')->name('show');
    Route::patch('{unit}', [UnitController::class, 'update'])
        ->middleware('permission:units.update')->name('update');
    Route::post('{unit}/status', [UnitController::class, 'changeStatus'])
        ->middleware('permission:units.update')->name('status');
    Route::delete('{unit}', [UnitController::class, 'destroy'])
        ->middleware('permission:units.delete')->name('destroy');
});

Route::prefix('listings')->name('listings.')->group(function (): void {
    Route::get('/', [ListingController::class, 'index'])
        ->middleware('permission:listings.view')->name('index');
    Route::get('{listing}', [ListingController::class, 'show'])
        ->middleware('permission:listings.view')->name('show');
    Route::patch('{listing}', [ListingController::class, 'update'])
        ->middleware('permission:listings.update')->name('update');
    Route::delete('{listing}', [ListingController::class, 'destroy'])
        ->middleware('permission:listings.delete')->name('destroy');

    Route::get('{listing}/readiness', [ListingController::class, 'readiness'])
        ->middleware('permission:listings.view')->name('readiness');
    Route::post('{listing}/publish', [ListingController::class, 'publish'])
        ->middleware('permission:listings.publish')->name('publish');
    Route::post('{listing}/pause', [ListingController::class, 'pause'])
        ->middleware('permission:listings.publish')->name('pause');

    Route::get('{listing}/versions', [ListingController::class, 'versions'])
        ->middleware('permission:listings.view')->name('versions');
    Route::post('{listing}/versions/{version}/restore', [ListingController::class, 'restoreVersion'])
        ->middleware('permission:listings.update')->name('versions.restore');
});

/*
|--------------------------------------------------------------------------
| The per-property guest agent
|--------------------------------------------------------------------------
|
| Configuration and the test bench. Nothing here sends a message: `ask` and
| `evaluate` return drafts and scores, and both say so in the payload.
|
| `ask` and `evaluate` are throttled because each one can spend money at a model
| provider, and the evaluate route more so — one request there is a whole
| scenario set.
|
*/

Route::prefix('properties/{property}/agent')->name('properties.agent.')->group(function (): void {
    Route::get('/', [PropertyAgentController::class, 'show'])
        ->middleware('permission:properties.view')->name('show');
    Route::patch('/', [PropertyAgentController::class, 'update'])
        ->middleware('permission:properties.update')->name('update');

    Route::post('ask', [PropertyAgentController::class, 'ask'])
        ->middleware(['permission:properties.update', 'throttle:30,1'])->name('ask');
    Route::post('evaluate', [PropertyAgentController::class, 'evaluate'])
        ->middleware(['permission:properties.update', 'throttle:6,1'])->name('evaluate');
});
