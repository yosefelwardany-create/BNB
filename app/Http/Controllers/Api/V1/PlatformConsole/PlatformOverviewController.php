<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Platform\Services\PlatformHealth;
use App\Domain\Platform\Services\PlatformMetrics;
use App\Domain\Platform\Services\PlatformSettings;
use App\Domain\Platform\Support\PlanFeature;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The platform console's front page.
 *
 * No authorisation calls here: the `platform-admin` middleware on the route
 * group is the whole gate, and it is a flag rather than a permission precisely
 * so that a tenant cannot grant its way in. A policy would imply there is a
 * permission that opens this, and there is not.
 */
class PlatformOverviewController extends Controller
{
    public function __construct(
        private readonly PlatformMetrics $metrics,
        private readonly PlatformHealth $health,
    ) {}

    /**
     * Counts across every tenant.
     */
    public function overview(): JsonResponse
    {
        return response()->json(['data' => $this->metrics->overview()]);
    }

    /**
     * Sign-ups by month.
     */
    public function growth(Request $request): JsonResponse
    {
        $months = max(1, min((int) $request->integer('months', 12), 36));

        return response()->json(['data' => $this->metrics->growth($months)]);
    }

    /**
     * Whether anything is stuck, failing, or quietly simulated.
     */
    public function health(): JsonResponse
    {
        return response()->json(['data' => $this->health->snapshot()]);
    }

    /**
     * The registries the console builds its forms from.
     *
     * Served rather than duplicated in the frontend, so adding a plan feature or
     * a limit is one change in one place and appears in the console without a
     * rebuild. A hard-coded copy in TypeScript is how a feature ends up
     * grantable in the API and invisible in the interface.
     */
    public function vocabulary(): JsonResponse
    {
        return response()->json([
            'data' => [
                'features' => collect(PlanFeature::all())
                    ->map(fn (string $description, string $key): array => [
                        'key' => $key,
                        'description' => $description,
                    ])
                    ->values(),
                'limits' => collect(PlanFeature::limits())
                    ->map(fn (string $label, string $key): array => [
                        'key' => $key,
                        'label' => $label,
                    ])
                    ->values(),
                'settings' => app(PlatformSettings::class)->describe(),
            ],
        ]);
    }
}
