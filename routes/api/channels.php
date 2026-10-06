<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Channels\ChannelAccountController;
use App\Http\Controllers\Api\V1\Channels\ChannelListingController;
use App\Http\Controllers\Api\V1\Channels\HostexFinancialController;
use App\Http\Controllers\Api\V1\Channels\SyncJobController;
use Illuminate\Support\Facades\Route;

Route::get('hostex-transactions', [HostexFinancialController::class, 'index'])
    ->middleware('permission:payments.view,financials.view')->name('hostex-transactions.index');

/*
|--------------------------------------------------------------------------
| Channel distribution
|--------------------------------------------------------------------------
|
| Connections to booking channels, the mappings between our listings and
| theirs, and the record of every conversation with them.
|
| Three permissions, and the split is not cosmetic. `channels.manage` changes
| what a channel is — its credentials, its commission, whether it collects the
| guest's money. `channels.map` points a channel's calendar at a particular
| apartment, which is the most expensive mistake available in distribution.
| `channels.sync` merely asks the platform to do now what it already does on a
| schedule, and is safe to hand to a revenue manager.
|
| Nothing here deletes. An account holds the history of every booking that came
| through it; a mapping is what a channel booking points at to say where it came
| from. Both are deactivated instead.
|
*/

Route::prefix('channels')->name('channels.')->group(function (): void {
    // What the platform can connect to, and — stated per channel — which of
    // those connections are real and which are served by a local simulation
    // until a partner agreement exists.
    Route::get('available', [ChannelAccountController::class, 'available'])
        ->middleware('permission:channels.view,channels.manage')->name('available');

    Route::get('/', [ChannelAccountController::class, 'index'])
        ->middleware('permission:channels.view,channels.manage')->name('index');

    Route::post('/', [ChannelAccountController::class, 'store'])
        ->middleware('permission:channels.manage')->name('store');

    Route::get('{account}', [ChannelAccountController::class, 'show'])
        ->middleware('permission:channels.view,channels.manage')->name('show');

    Route::get('{account}/diagnostics', [ChannelAccountController::class, 'diagnostics'])
        ->middleware('permission:channels.manage')->name('diagnostics');

    Route::patch('{account}', [ChannelAccountController::class, 'update'])
        ->middleware('permission:channels.manage')->name('update');

    Route::post('{account}/verify', [ChannelAccountController::class, 'verify'])
        ->middleware('permission:channels.manage,channels.sync')->name('verify');

    Route::post('{account}/push', [ChannelAccountController::class, 'push'])
        ->middleware('permission:channels.sync,channels.manage')->name('push');

    /*
     * The secret this company pastes into its own channel settings.
     *
     * POST rather than GET because it issues a new one every time and stops the
     * old one: it is not a reading of state, it is a change to it. Shown once,
     * like an API key.
     */
    // Slow, and it costs the channel's rate limit, so it is throttled harder
    // than the rest.
    Route::post('{account}/pull', [ChannelAccountController::class, 'pull'])
        ->middleware(['permission:channels.sync,channels.manage', 'throttle:6,1'])->name('pull');

    Route::post('{account}/webhook', [ChannelAccountController::class, 'webhook'])
        ->middleware('permission:channels.manage')->name('webhook');

    Route::delete('{account}', [ChannelAccountController::class, 'disconnect'])
        ->middleware('permission:channels.manage')->name('disconnect');
});

Route::prefix('channel-listings')->name('channel-listings.')->group(function (): void {
    Route::get('/', [ChannelListingController::class, 'index'])
        ->middleware('permission:channels.view,channels.manage')->name('index');

    Route::post('/', [ChannelListingController::class, 'store'])
        ->middleware('permission:channels.map,channels.manage')->name('store');

    Route::get('{mapping}', [ChannelListingController::class, 'show'])
        ->middleware('permission:channels.view,channels.manage')->name('show');

    Route::patch('{mapping}', [ChannelListingController::class, 'update'])
        ->middleware('permission:channels.map,channels.manage')->name('update');

    /*
     * Make a property out of a listing the channel discovered.
     *
     * Needs the permission to map, because that is what it ends up doing — the
     * property is a means to the mapping. Throttled because each one creates a
     * property.
     */
    Route::post('{mapping}/adopt', [ChannelListingController::class, 'adopt'])
        ->middleware(['permission:channels.map,channels.manage', 'throttle:20,1'])->name('adopt');

    Route::post('{mapping}/push', [ChannelListingController::class, 'push'])
        ->middleware('permission:channels.sync,channels.manage')->name('push');

    Route::delete('{mapping}', [ChannelListingController::class, 'destroy'])
        ->middleware('permission:channels.map,channels.manage')->name('unmap');
});

/*
 * The synchronisation log. Read-only: these rows are the evidence of what was
 * sent and what came back, and the only account of why a calendar went wrong.
 */
Route::prefix('channel-sync')->name('channel-sync.')->group(function (): void {
    Route::get('health', [SyncJobController::class, 'health'])
        ->middleware('permission:channels.view,channels.manage')->name('health');

    Route::get('jobs', [SyncJobController::class, 'index'])
        ->middleware('permission:channels.view,channels.manage')->name('jobs.index');

    Route::get('jobs/{job}', [SyncJobController::class, 'show'])
        ->middleware('permission:channels.view,channels.manage')->name('jobs.show');
});
