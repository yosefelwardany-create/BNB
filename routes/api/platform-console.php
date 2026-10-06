<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\PlatformConsole\PlatformAuditController;
use App\Http\Controllers\Api\V1\PlatformConsole\PlatformClientController;
use App\Http\Controllers\Api\V1\PlatformConsole\PlatformOverviewController;
use App\Http\Controllers\Api\V1\PlatformConsole\PlatformSettingController;
use App\Http\Controllers\Api\V1\PlatformConsole\PlatformTenantController;
use App\Http\Controllers\Api\V1\PlatformConsole\PlatformUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Account administration
|--------------------------------------------------------------------------
|
| For the platform owner, who manages every client account from inside the
| operational workspace. This used to be a separate "platform console" with
| plans, trials, announcements and read-only support sessions; those are gone
| with the subscription model, and what remains is the administration a managed
| service still needs: creating client accounts, suspending and reinstating
| them, who may sign in, who else is a platform owner, and the record of what
| the platform did.
|
| One gate covers everything here: `platform-admin`, which checks the
| `is_platform_admin` flag on the user. Deliberately not a permission. Every
| permission in this product is grantable by a client's own administrator, so a
| permission that opened this would be a path from "administrator of one
| company" to "administrator of everybody's". There is no such path.
|
| The same middleware clears the resolved tenant, because everything here reads
| across organizations and a request running inside one would silently answer
| about a single account. Failures are 404 rather than 403, so a client's key
| cannot map these paths by watching which answer differently.
|
| Still absent, deliberately: nothing here deletes an organization or reads a
| client's records. The owner reads and manages a client's records through the
| ordinary tenant API with an `X-Organization` header, where every action is
| scoped to that one account and audited in that account's own trail.
|
*/

Route::prefix('platform')->name('platform.')->middleware('platform-admin')->group(function (): void {
    Route::get('overview', [PlatformOverviewController::class, 'overview'])->name('overview');
    Route::get('growth', [PlatformOverviewController::class, 'growth'])->name('growth');
    Route::get('health', [PlatformOverviewController::class, 'health'])->name('health');

    // ---------------------------------------------------------------------
    // Client accounts
    // ---------------------------------------------------------------------
    Route::prefix('organizations')->name('organizations.')->group(function (): void {
        Route::get('/', [PlatformTenantController::class, 'index'])->name('index');

        // Creates the client's organization, its account-holder owner record,
        // the management agreement and the client's login, and sends the
        // invitation. Throttled: each call creates an account somebody can
        // sign in to.
        Route::post('/', [PlatformClientController::class, 'store'])
            ->middleware('throttle:10,1')->name('store');

        Route::get('{organization}', [PlatformTenantController::class, 'show'])->name('show');

        // Writes the platform's private notes, and nothing else. A client's own
        // name, currency and timezone are edited through the tenant API.
        Route::patch('{organization}', [PlatformTenantController::class, 'update'])->name('update');

        Route::get('{organization}/users', [PlatformTenantController::class, 'users'])->name('users');

        // Lifecycle. Each requires a reason, recorded in the client's own audit
        // trail. None of them deletes anything.
        Route::post('{organization}/suspend', [PlatformTenantController::class, 'suspend'])->name('suspend');
        Route::post('{organization}/reinstate', [PlatformTenantController::class, 'reinstate'])->name('reinstate');
        Route::post('{organization}/cancel', [PlatformTenantController::class, 'cancel'])->name('cancel');

        // Re-send the client's sign-in invitation (a password-reset link).
        Route::post('{organization}/invite', [PlatformClientController::class, 'invite'])
            ->middleware('throttle:10,1')->name('invite');
    });

    // ---------------------------------------------------------------------
    // People
    // ---------------------------------------------------------------------
    Route::prefix('users')->name('users.')->group(function (): void {
        Route::get('/', [PlatformUserController::class, 'index'])->name('index');
        Route::get('{user}', [PlatformUserController::class, 'show'])->name('show');

        // The most sensitive write in the product: the only way, besides the
        // console command, to create somebody who can administer every client.
        // Narrow by design — the flag and a reason, nothing else.
        Route::patch('{user}', [PlatformUserController::class, 'update'])->name('update');
    });

    // ---------------------------------------------------------------------
    // Settings and the record of what the platform did
    // ---------------------------------------------------------------------
    Route::get('settings', [PlatformSettingController::class, 'index'])->name('settings.index');
    Route::put('settings', [PlatformSettingController::class, 'update'])->name('settings.update');

    // Both read-only. An audit log somebody can edit is not an audit log.
    //
    // Two endpoints because they answer two questions: `audit/platform` is what
    // the owner did to accounts, and `audit/tenants` is what happened inside
    // client accounts. Merging them would make every row ambiguous about whose
    // action it was.
    Route::get('audit/platform', [PlatformAuditController::class, 'platform'])->name('audit.platform');
    Route::get('audit/tenants', [PlatformAuditController::class, 'index'])->name('audit.tenants');
});
