<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Support\PlanFeature;
use App\Http\Controllers\Controller;
use App\Http\Resources\Platform\PlanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * What the platform sells.
 *
 * A plan is retired rather than deleted, and the endpoint says so: organizations
 * reference a plan, and a plan somebody was on last March has to stay nameable
 * on that month's invoice. Deactivating takes it off the shelf; the soft delete
 * is only reachable once nothing is on it.
 */
class PlatformPlanController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Plan::query()->withCount('organizations');

        if ($request->boolean('active_only')) {
            $query->active();
        }

        return PlanResource::collection(
            $query->orderBy('position')->orderBy('price_amount')->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $plan = new Plan;
        $plan->fill($data);
        $plan->slug = $data['slug'] ?? Str::slug($data['name']);
        $plan->save();

        return (new PlanResource($plan))->response()->setStatusCode(201);
    }

    public function show(Plan $plan): PlanResource
    {
        return new PlanResource($plan->loadCount('organizations'));
    }

    public function update(Request $request, Plan $plan): PlanResource
    {
        $data = $request->validate($this->rules($plan));

        $plan->fill($data)->save();

        return new PlanResource($plan->fresh()->loadCount('organizations'));
    }

    /**
     * Retire a plan.
     *
     * Refused while organizations are still on it, with the count, because the
     * alternative — silently moving somebody's customers off their plan — is a
     * billing incident.
     */
    public function destroy(Plan $plan): JsonResponse
    {
        $inUse = $plan->organizations()->count();

        if ($inUse > 0) {
            return response()->json([
                'message' => sprintf(
                    '%d organization(s) are on this plan. Move them first, or deactivate the plan to take it off the shelf without affecting them.',
                    $inUse,
                ),
            ], 422);
        }

        $plan->delete();

        return response()->json([
            'message' => 'The plan has been retired. Its history is intact.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Plan $plan = null): array
    {
        $creating = $plan === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'slug' => [
                'sometimes', 'string', 'max:64', 'alpha_dash',
                Rule::unique('plans', 'slug')->ignore($plan?->getKey())->withoutTrashed(),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],

            // Minor units, like every other amount in this API.
            'price_amount' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'billing_interval' => ['sometimes', Rule::in(['monthly', 'yearly'])],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],

            // Null is unlimited and must be accepted as such; zero means none
            // allowed, which is a different and occasionally useful setting.
            'max_properties' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_units' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_listings' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_users' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_reservations_per_month' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'features' => ['sometimes', 'nullable', 'array'],
            'features.*' => ['string', Rule::in(PlanFeature::keys())],

            'is_public' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }
}
