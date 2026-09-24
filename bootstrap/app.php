<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePlanFeature;
use App\Http\Middleware\EnsurePlatformAdministrator;
use App\Http\Middleware\ResolveOrganization;
use App\Http\Middleware\RestrictImpersonatedSession;
use App\Support\Concerns\CrossTenantWriteException;
use App\Support\Tenancy\TenantNotResolvedException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // The admin SPA, the staff app and future mobile clients all talk
            // to the same versioned REST API.
            Route::middleware('api')
                ->prefix('api/v1')
                ->name('api.v1.')
                ->group(base_path('routes/api.php'));

            // Tokenised, unauthenticated surfaces: the guest portal, the
            // public booking engine and inbound provider webhooks.
            Route::middleware('api')
                ->prefix('api/public')
                ->name('api.public.')
                ->group(base_path('routes/public.php'));

            Route::middleware('api')
                ->prefix('webhooks')
                ->name('webhooks.')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            AssignRequestId::class,
            EnsureFrontendRequestsAreStateful::class,
        ]);

        // Appended to every API request: a read-only support session must not
        // be able to write through any route, so the check lives here rather
        // than on each of the routes that would have to remember it.
        $middleware->api(append: [
            RestrictImpersonatedSession::class,
        ]);

        $middleware->web(prepend: [
            AssignRequestId::class,
        ]);

        $middleware->alias([
            'organization' => ResolveOrganization::class,
            // Machine callers. Binds its own tenant from the key, so unlike a
            // user session it can never be pointed at another organization by
            // a header.
            'api-key' => AuthenticateApiKey::class,
            'permission' => EnsurePermission::class,
            // What a plan includes, as opposed to what a person may do. Both
            // usually apply to the same route; see the middleware for why they
            // are deliberately not the same gate.
            'feature' => EnsurePlanFeature::class,
            // The platform console's own gate: the is_platform_admin flag, not
            // a permission, because every permission is grantable by a tenant's
            // administrator and none of them may lead here.
            'platform-admin' => EnsurePlatformAdministrator::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // Tenancy always resolves immediately after authentication.
        $middleware->priority([
            AssignRequestId::class,
            EncryptCookies::class,
            StartSession::class,
            Authenticate::class,
            AuthenticateApiKey::class,
            ResolveOrganization::class,
            RestrictImpersonatedSession::class,
            EnsurePlatformAdministrator::class,
            SubstituteBindings::class,
            EnsurePermission::class,
            EnsurePlanFeature::class,
            Authorize::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        (require __DIR__.'/../routes/schedule.php')($schedule);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->is('webhooks/*')
                || $request->expectsJson(),
        );

        $exceptions->render(function (TenantNotResolvedException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'No organization is bound to this request.',
                ], 403);
            }

            return null;
        });

        $exceptions->render(function (CrossTenantWriteException $e, Request $request) {
            report($e);

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            return null;
        });
    })->create();
