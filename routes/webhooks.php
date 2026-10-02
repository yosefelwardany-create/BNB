<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Inbound webhooks
|--------------------------------------------------------------------------
|
| Mounted at /webhooks. Each provider verifies its own signature inside the
| controller before anything is trusted; there is no shared authentication
| middleware because every provider signs differently.
|
*/

use App\Http\Controllers\Api\Webhooks\ChannelWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * A distribution channel telling us something changed.
 *
 * The account is named in the path and the request is verified by that
 * channel's own adapter — each one signs differently, which is why there is no
 * shared middleware here. An unverified request is refused without being
 * recorded, so nobody who finds the URL can fill the table.
 *
 * Throttled by account rather than by caller address: a channel's webhooks all
 * arrive from its own infrastructure, so limiting by address would have one
 * customer's busy day throttle another's.
 */
Route::post('channels/{account}', ChannelWebhookController::class)
    ->middleware('throttle:channel-webhooks')
    ->whereUlid('account')
    ->name('channels');
