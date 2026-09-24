<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AuthenticationController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\MfaController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\RegistrationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Version 1 API
|--------------------------------------------------------------------------
|
| Mounted at /api/v1. This is the only interface the admin SPA, the staff
| application and any future mobile client use — there is no separate private
| interface, which keeps one authorisation story for every surface.
|
| Every route below `organization` middleware operates inside a resolved
| tenant; the middleware rejects the request if the caller is not a member.
|
*/

Route::post('auth/login', [AuthenticationController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('auth.login');

Route::post('auth/register', [RegistrationController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('auth.register');

/*
 * The second half of a sign-in. Unauthenticated by necessity — it is reached
 * after a correct password and before any session exists — so the short-lived
 * reference issued by the login is its only credential, and it is throttled
 * tightly because six digits is a million guesses only if guessing is limited.
 */
Route::post('auth/mfa/challenge', [MfaController::class, 'challenge'])
    ->middleware('throttle:10,1')
    ->name('auth.mfa.challenge');

Route::post('auth/password/forgot', [PasswordResetController::class, 'sendLink'])
    ->middleware('throttle:5,1')
    ->name('auth.password.forgot');

Route::post('auth/password/reset', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:5,1')
    ->name('auth.password.reset');

Route::post('auth/invitations/accept', [RegistrationController::class, 'acceptInvitation'])
    ->middleware('throttle:10,1')
    ->name('auth.invitations.accept');

Route::get('auth/invitations/{token}', [RegistrationController::class, 'showInvitation'])
    ->middleware('throttle:30,1')
    ->name('auth.invitations.show');

// The verification link is signed, so possession of a valid link is the proof
// and no session is required. The route name is the one Laravel's built-in
// VerifyEmail notification builds its URL from.
Route::get('auth/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('verification.verify');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/logout', [AuthenticationController::class, 'logout'])->name('auth.logout');

    /*
     * Your own account. Outside the tenant middleware on purpose: this is
     * about a person, not a company's data, and a platform administrator holds
     * no membership anywhere — requiring a tenant would leave the one account
     * that governs the platform unable to change its own password.
     *
     * Throttled because both routes accept the current password, which makes
     * them a place to guess it.
     */
    Route::middleware('throttle:10,1')->group(function (): void {
        Route::patch('auth/profile', [ProfileController::class, 'update'])->name('auth.profile.update');
        Route::post('auth/password', [ProfileController::class, 'changePassword'])->name('auth.password.change');
    });

    Route::post('auth/email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('auth.email.resend');

    /*
     * Managing your own second factor. Outside the `organization` group: it is a
     * property of a person, not of a company, and somebody locked out of every
     * organization must still be able to fix their own account.
     */
    Route::prefix('auth/mfa')->name('auth.mfa.')->middleware('throttle:20,1')->group(function (): void {
        Route::get('/', [MfaController::class, 'show'])->name('show');
        Route::post('begin', [MfaController::class, 'begin'])->name('begin');
        Route::post('confirm', [MfaController::class, 'confirm'])->name('confirm');
        Route::post('recovery-codes', [MfaController::class, 'regenerateRecoveryCodes'])
            ->name('recovery-codes');
        Route::delete('/', [MfaController::class, 'destroy'])->name('disable');
    });

    /*
     * The session, not a tenant's data. `optional` because a platform
     * administrator holds no membership anywhere: requiring one here issued
     * them a token and then refused the very next call, which the SPA reads as
     * a dead session and answers by returning them to the sign-in screen.
     *
     * An organization is still resolved and fully checked whenever the caller
     * has one, so an ordinary user's session is unchanged.
     */
    Route::get('auth/me', [AuthenticationController::class, 'me'])
        ->middleware('organization:optional')
        ->name('auth.me');

    Route::middleware('organization')->group(function (): void {

        require __DIR__.'/api/organization.php';
        require __DIR__.'/api/properties.php';
        require __DIR__.'/api/people.php';
        require __DIR__.'/api/reservations.php';
        require __DIR__.'/api/operations.php';
        require __DIR__.'/api/messaging.php';
        require __DIR__.'/api/channels.php';
        require __DIR__.'/api/finance.php';
        require __DIR__.'/api/revenue.php';
        require __DIR__.'/api/reporting.php';
        require __DIR__.'/api/experience.php';
        require __DIR__.'/api/platform.php';
    });

    /*
     * The platform console sits *outside* the `organization` group, not inside
     * it. That is the whole point: it reads across every tenant, and resolving
     * one first would both filter its answers and require the operator to be a
     * member of a company in order to govern the platform.
     */
    require __DIR__.'/api/platform-console.php';
});
