<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\PlatformConsole\PlatformAnnouncementController;
use App\Http\Controllers\Api\V1\PlatformConsole\PlatformAuditController;
use App\Http\Controllers\Api\V1\PlatformConsole\PlatformImpersonationController;
use App\Http\Controllers\Api\V1\PlatformConsole\PlatformOverviewController;
use App\Http\Controllers\Api\V1\PlatformConsole\PlatformPlanController;
use App\Http\Controllers\Api\V1\PlatformConsole\PlatformSettingController;
use App\Http\Controllers\Api\V1\PlatformConsole\PlatformTenantController;
use App\Http\Controllers\Api\V1\PlatformConsole\PlatformUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform console
|--------------------------------------------------------------------------
|
| For whoever runs the platform, not for anybody on it.
|
| One gate covers everything here: `platform-admin`, which checks the
| `is_platform_admin` flag on the user. Deliberately not a permission. Every
| permission in this product is grantable by a tenant's own administrator, so a
| permission that opened this console would be a path from "administrator of one
| company" to "administrator of everybody's". There is no such path.
|
| The same middleware clears the resolved tenant, because everything here reads
| across organizations and a request running inside one would silently answer
| about a single tenant. It also refuses an impersonation token, closing the only
| escalation this design has: a borrowed session must not reach the console that
| issued it.
|
| Failures are 404 rather than 403, so a tenant's key cannot map the console by
| watching which paths answer differently.
|
| What is absent is as deliberate as what is here. There is no endpoint that
| deletes an organization, edits a tenant's own data, or reads a customer's
| records. A company that leaves keeps every reservation, statement and ledger
| entry its former owners and its tax authority may need for years; reading a
| customer's data is done through a support session, which is read-only and
| which the customer gets a record of.
|
*/

Route::prefix('platform')->name('platform.')->middleware('platform-admin')->group(function (): void {
    // ---------------------------------------------------------------------
    // Overview
    // ---------------------------------------------------------------------
    Route::get('overview', [PlatformOverviewController::class, 'overview'])->name('overview');
    Route::get('growth', [PlatformOverviewController::class, 'growth'])->name('growth');
    Route::get('health', [PlatformOverviewController::class, 'health'])->name('health');

    // The feature, limit and setting registries, so the console renders its
    // forms from the server's definitions rather than a copy in TypeScript that
    // drifts.
    Route::get('vocabulary', [PlatformOverviewController::class, 'vocabulary'])->name('vocabulary');

    // ---------------------------------------------------------------------
    // Tenants
    // ---------------------------------------------------------------------
    Route::prefix('organizations')->name('organizations.')->group(function (): void {
        Route::get('/', [PlatformTenantController::class, 'index'])->name('index');
        Route::get('{organization}', [PlatformTenantController::class, 'show'])->name('show');

        // Writes the platform's private notes, and nothing else. A tenant's own
        // name, currency and timezone belong to the tenant.
        Route::patch('{organization}', [PlatformTenantController::class, 'update'])->name('update');

        Route::get('{organization}/users', [PlatformTenantController::class, 'users'])->name('users');

        // Lifecycle. Each requires a reason, recorded in the customer's audit
        // trail. None of them deletes anything.
        Route::post('{organization}/suspend', [PlatformTenantController::class, 'suspend'])->name('suspend');
        Route::post('{organization}/reinstate', [PlatformTenantController::class, 'reinstate'])->name('reinstate');
        Route::post('{organization}/cancel', [PlatformTenantController::class, 'cancel'])->name('cancel');

        // Commercial terms.
        Route::post('{organization}/plan', [PlatformTenantController::class, 'changePlan'])->name('plan');
        Route::post('{organization}/trial', [PlatformTenantController::class, 'setTrial'])->name('trial');
        Route::post('{organization}/overrides', [PlatformTenantController::class, 'setOverrides'])->name('overrides');

        // Read-only support session.
        Route::post('{organization}/impersonate', [PlatformImpersonationController::class, 'store'])
            ->name('impersonate');
    });

    // ---------------------------------------------------------------------
    // Plans
    // ---------------------------------------------------------------------
    Route::prefix('plans')->name('plans.')->group(function (): void {
        Route::get('/', [PlatformPlanController::class, 'index'])->name('index');
        Route::post('/', [PlatformPlanController::class, 'store'])->name('store');
        Route::get('{plan}', [PlatformPlanController::class, 'show'])->name('show');
        Route::patch('{plan}', [PlatformPlanController::class, 'update'])->name('update');

        // Refused while anybody is on it; deactivate instead.
        Route::delete('{plan}', [PlatformPlanController::class, 'destroy'])->name('retire');
    });

    // ---------------------------------------------------------------------
    // People
    // ---------------------------------------------------------------------
    Route::prefix('users')->name('users.')->group(function (): void {
        Route::get('/', [PlatformUserController::class, 'index'])->name('index');
        Route::get('{user}', [PlatformUserController::class, 'show'])->name('show');

        // The most sensitive write in the product: the only way to create
        // somebody who can reach this console. Narrow by design — the flag and
        // a reason, nothing else.
        Route::patch('{user}', [PlatformUserController::class, 'update'])->name('update');
    });

    // ---------------------------------------------------------------------
    // Announcements, settings, and the record of what the platform did
    // ---------------------------------------------------------------------
    Route::prefix('announcements')->name('announcements.')->group(function (): void {
        Route::get('/', [PlatformAnnouncementController::class, 'index'])->name('index');
        Route::post('/', [PlatformAnnouncementController::class, 'store'])->name('store');
        Route::get('{announcement}', [PlatformAnnouncementController::class, 'show'])->name('show');
        Route::patch('{announcement}', [PlatformAnnouncementController::class, 'update'])->name('update');
        Route::delete('{announcement}', [PlatformAnnouncementController::class, 'destroy'])->name('withdraw');
    });

    Route::get('settings', [PlatformSettingController::class, 'index'])->name('settings.index');
    Route::put('settings', [PlatformSettingController::class, 'update'])->name('settings.update');

    Route::get('impersonations', [PlatformImpersonationController::class, 'index'])->name('impersonations.index');
    Route::delete('impersonations/{session}', [PlatformImpersonationController::class, 'destroy'])
        ->name('impersonations.end');

    // Both read-only. An audit log somebody can edit is not an audit log.
    //
    // Two endpoints because they answer two questions for two audiences:
    // `audit/platform` is what the operator did, and `audit/tenants` is what
    // happened inside customers' accounts. Merging them would make every row
    // ambiguous about whose action it was.
    Route::get('audit/platform', [PlatformAuditController::class, 'platform'])->name('audit.platform');
    Route::get('audit/tenants', [PlatformAuditController::class, 'index'])->name('audit.tenants');
});
