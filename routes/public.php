<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Public surfaces
|--------------------------------------------------------------------------
|
| Mounted at /api/public. Nothing here is authenticated with a user account:
| the guest portal identifies a reservation by an unguessable token, and the
| booking engine is open to the internet. Both are therefore rate limited and
| return deliberately narrow representations.
|
*/

use App\Http\Controllers\Api\Public\GuestPortalController;
use Illuminate\Support\Facades\Route;

/*
 * The guest portal.
 *
 * The link is the credential, so every route is rate limited by the token
 * itself. That is what makes brute-forcing a 32-character token pointless
 * rather than merely slow, and it bounds the damage of a link that has been
 * shared more widely than the guest intended.
 */
Route::prefix('portal/{token}')
    ->name('portal.')
    ->middleware('throttle:guest-portal')
    ->whereAlphaNumeric('token')
    ->group(function (): void {
        Route::get('/', [GuestPortalController::class, 'show'])->name('show');

        Route::get('payments', [GuestPortalController::class, 'payments'])->name('payments');
        Route::post('payments', [GuestPortalController::class, 'pay'])
            // Tighter: this one moves money.
            ->middleware('throttle:guest-portal-write')->name('pay');

        Route::get('messages', [GuestPortalController::class, 'messages'])->name('messages');
        Route::post('messages', [GuestPortalController::class, 'sendMessage'])
            ->middleware('throttle:guest-portal-write')->name('messages.send');

        Route::post('check-in', [GuestPortalController::class, 'checkIn'])
            ->middleware('throttle:guest-portal-write')->name('check-in');
    });
