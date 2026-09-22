<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AuthenticationController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
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

    Route::post('auth/email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('auth.email.resend');

    Route::middleware('organization')->group(function (): void {
        Route::get('auth/me', [AuthenticationController::class, 'me'])->name('auth.me');

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
});
