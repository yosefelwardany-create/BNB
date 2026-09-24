<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Organization\SubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The organization's own account
|--------------------------------------------------------------------------
|
| What a tenant can see about its subscription, the notices the platform has
| published to it, and the record of every time the platform operator looked
| inside its account.
|
| The plan and usage endpoints carry no permission gate. Every member of an
| organization may see the plan they are working inside: somebody who cannot add
| a property is entitled to know that the reason is a cap and not a fault, and
| hiding it only turns a clear refusal into a support ticket.
|
| The support-session log is gated, because it names individual staff accounts —
| that is the organization's business and not every seat's. It exists at all
| because it is what makes read-only impersonation defensible: the customer can
| see who looked, when and why, without having to ask.
|
*/

Route::prefix('organization')->name('organization.')->group(function (): void {
    Route::get('plan', [SubscriptionController::class, 'show'])->name('plan');

    Route::get('announcements', [SubscriptionController::class, 'announcements'])
        ->name('announcements');

    Route::get('support-sessions', [SubscriptionController::class, 'supportSessions'])
        ->middleware('permission:organization.view,users.view')
        ->name('support-sessions');
});
