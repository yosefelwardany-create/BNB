<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Platform\ApiKeyController;
use App\Http\Controllers\Api\V1\Platform\WebhookEndpointController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform & integrations
|--------------------------------------------------------------------------
|
| The outward-facing edges: credentials for the public API, and the endpoints
| this platform sends domain events to.
|
| Both are narrowly gated, and neither is folded into a general "settings"
| permission. Registering a webhook endpoint is a data-export decision: it
| causes booking and guest data to be sent to an address of somebody's
| choosing. Minting an API key is a credential-issuing decision. Neither is
| configuration in the sense that a timezone is.
|
| Nothing here deletes. A revoked key's usage history is the whole of an
| incident investigation; a disabled endpoint's delivery history is the record
| of what this platform told an integrator and when.
|
*/

Route::prefix('api-keys')->name('api-keys.')
    ->middleware(['permission:api_keys.manage', 'feature:api_access'])
    ->group(function (): void {
        Route::get('/', [ApiKeyController::class, 'index'])->name('index');

        // The response to this call is the only place the token ever exists in a
        // readable form.
        Route::post('/', [ApiKeyController::class, 'store'])->name('store');

        Route::get('{apiKey}', [ApiKeyController::class, 'show'])->name('show');
        Route::patch('{apiKey}', [ApiKeyController::class, 'update'])->name('update');
        Route::delete('{apiKey}', [ApiKeyController::class, 'destroy'])->name('revoke');
    });

Route::prefix('webhook-endpoints')->name('webhook-endpoints.')
    ->middleware('feature:webhooks')
    ->group(function (): void {
        Route::get('/', [WebhookEndpointController::class, 'index'])
            ->middleware('permission:webhooks.manage,integrations.view')->name('index');

        Route::post('/', [WebhookEndpointController::class, 'store'])
            ->middleware('permission:webhooks.manage')->name('store');

        Route::get('{endpoint}', [WebhookEndpointController::class, 'show'])
            ->middleware('permission:webhooks.manage,integrations.view')->name('show');

        Route::patch('{endpoint}', [WebhookEndpointController::class, 'update'])
            ->middleware('permission:webhooks.manage')->name('update');

        // Issues a new signing secret and invalidates the old one immediately.
        // No overlap window: a rotation is usually a response to a suspected leak.
        Route::post('{endpoint}/rotate-secret', [WebhookEndpointController::class, 'rotateSecret'])
            ->middleware('permission:webhooks.manage')->name('rotate-secret');

        // Proves an endpoint works before deciding what it should receive, so it
        // deliberately ignores the subscription list.
        Route::post('{endpoint}/test', [WebhookEndpointController::class, 'test'])
            ->middleware('permission:webhooks.manage')->name('test');

        Route::get('{endpoint}/deliveries', [WebhookEndpointController::class, 'deliveries'])
            ->middleware('permission:webhooks.manage,integrations.view')->name('deliveries');

        Route::delete('{endpoint}', [WebhookEndpointController::class, 'destroy'])
            ->middleware('permission:webhooks.manage')->name('disable');
    });

// Replays a delivery as a new attempt rather than rewriting the failed one:
// the failure is evidence of what went wrong.
Route::post('webhook-deliveries/{delivery}/redeliver', [WebhookEndpointController::class, 'redeliver'])
    ->middleware('permission:webhooks.manage')
    ->name('webhook-deliveries.redeliver');
