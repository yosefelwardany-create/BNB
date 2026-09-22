<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

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
            Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->web(prepend: [
            AssignRequestId::class,
        ]);

        $middleware->alias([
            'organization' => ResolveOrganization::class,
            'permission' => EnsurePermission::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // Tenancy always resolves immediately after authentication.
        $middleware->priority([
            AssignRequestId::class,
            Illuminate\Cookie\Middleware\EncryptCookies::class,
            Illuminate\Session\Middleware\StartSession::class,
            Illuminate\Auth\Middleware\Authenticate::class,
            ResolveOrganization::class,
            Illuminate\Routing\Middleware\SubstituteBindings::class,
            EnsurePermission::class,
            Illuminate\Auth\Middleware\Authorize::class,
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

        $exceptions->render(function (App\Support\Tenancy\TenantNotResolvedException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'No organization is bound to this request.',
                ], 403);
            }

            return null;
        });

        $exceptions->render(function (App\Support\Concerns\CrossTenantWriteException $e, Request $request) {
            report($e);

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            return null;
        });
    })->create();
