<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Agents\PropertyAgentController;
use App\Http\Controllers\Api\V1\Properties\AmenityController;
use App\Http\Controllers\Api\V1\Properties\CancellationPolicyController;
use App\Http\Controllers\Api\V1\Properties\ListingController;
use App\Http\Controllers\Api\V1\Properties\PortfolioController;
use App\Http\Controllers\Api\V1\Properties\PropertyController;
use App\Http\Controllers\Api\V1\Properties\PropertyHelperController;
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

    /*
     * Who to call about this property.
     *
     * Under the property because a helper is a role *at a property* and means
     * nothing without one. Authorised against the property itself rather than a
     * permission of its own — see the controller.
     */
    Route::get('{property}/helpers', [PropertyHelperController::class, 'index'])
        ->middleware('permission:properties.view')->name('helpers.index');
    Route::post('{property}/helpers', [PropertyHelperController::class, 'store'])
        ->middleware('permission:properties.update')->name('helpers.store');

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

Route::prefix('helpers')->name('properties.helpers.')->group(function (): void {
    Route::patch('{helper}', [PropertyHelperController::class, 'update'])
        ->middleware('permission:properties.update')->name('update');
    Route::delete('{helper}', [PropertyHelperController::class, 'destroy'])
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
    // Undoing the line above. Same permission: putting something back on the
    // books is the same authority as taking it off.
    Route::post('{listing}/restore', [ListingController::class, 'restore'])
        ->middleware('permission:listings.delete')->name('restore');

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

    // Does this property's bot answer at all? Separate from `ask` so a failure
    // says which half is broken: the endpoint, or the agent's reading of it.
    Route::post('test-bot', [PropertyAgentController::class, 'testBot'])
        ->middleware(['permission:properties.update', 'throttle:20,1'])->name('test-bot');

    /*
     * The slow path: fire the property's webhook and take the answer later.
     *
     * `ask-later` is throttled harder than `ask` because each one starts an
     * agent run somebody pays for, and because an ask that is never answered
     * leaves a live callback token behind until it expires.
     *
     * `asks` is the screen's poll and is read-only, so it is only limited by
     * the usual API throttle.
     */
    Route::post('ask-later', [PropertyAgentController::class, 'askLater'])
        ->middleware(['permission:properties.update', 'throttle:12,1'])->name('ask-later');
    Route::get('asks', [PropertyAgentController::class, 'asks'])
        ->middleware('permission:properties.view')->name('asks');

    // What this agent has actually done. Read-only, and viewable by anybody who
    // may see the property: "what did the bot say to my guest" is not a
    // privileged question.
    Route::get('activity', [PropertyAgentController::class, 'activity'])
        ->middleware('permission:properties.view')->name('activity');

    /*
     * Things the agent proposes to do.
     *
     * `actions` is the approval queue and is read-only, so it is only limited by
     * the usual API throttle. The three that change something are throttled, and
     * each also checks the capability's own permission inside the controller:
     * approving a cancellation needs `reservations.cancel`, the same as doing it
     * by hand. Without that, asking the bot would be a way around the permission
     * system.
     */
    Route::get('actions', [PropertyAgentController::class, 'actions'])
        ->middleware('permission:properties.view')->name('actions');
    Route::post('actions', [PropertyAgentController::class, 'propose'])
        ->middleware(['permission:properties.update', 'throttle:30,1'])->name('actions.propose');
    Route::post('actions/{action}/approve', [PropertyAgentController::class, 'approveAction'])
        ->middleware(['permission:properties.update', 'throttle:60,1'])->name('actions.approve');
    Route::post('actions/{action}/reject', [PropertyAgentController::class, 'rejectAction'])
        ->middleware(['permission:properties.update', 'throttle:60,1'])->name('actions.reject');

    /*
     * The documents the agent reads.
     *
     * `refresh` costs an outbound fetch, so it is throttled; sharing a document
     * with guests is its own action rather than a field on the brief, because it
     * is the one setting here that can hand a door code to somebody with no
     * booking and should take a deliberate act.
     */
    Route::get('documents', [PropertyAgentController::class, 'documents'])
        ->middleware('permission:properties.view')->name('documents');
    Route::post('documents/{document}/refresh', [PropertyAgentController::class, 'refreshDocument'])
        ->middleware(['permission:properties.update', 'throttle:20,1'])->name('documents.refresh');
    Route::patch('documents/{document}/sharing', [PropertyAgentController::class, 'shareDocument'])
        ->middleware('permission:properties.update')->name('documents.sharing');
});
