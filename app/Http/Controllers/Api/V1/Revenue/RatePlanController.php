<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Revenue;

use App\Domain\Pricing\Models\RatePlan;
use App\Http\Controllers\Controller;
use App\Http\Resources\RatePlanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The plans a stay can be sold under.
 *
 * A derived plan prices relative to its parent — "non-refundable is the
 * standard rate less 10%" — rather than carrying its own copy of every rate.
 * That is the whole reason the derivation lives here as a relationship instead
 * of being flattened at creation: an operator who raises the standard rate
 * expects the non-refundable rate to follow, and a copy would silently stop
 * following on the day somebody edited it.
 *
 * The one structural rule enforced here is that derivation cannot form a
 * cycle. A plan that is ultimately derived from itself has no base rate to
 * start from, and the engine would recurse until the process died.
 */
class RatePlanController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RatePlan::class);

        $query = RatePlan::query();

        if ($request->filled('property_id')) {
            $property = $request->string('property_id')->toString();

            // A plan with no property applies everywhere, so "plans for this
            // property" must include the organization-wide ones or the
            // property appears to have no rates at all.
            $query->where(fn ($q) => $q->where('property_id', $property)->orWhereNull('property_id'));
        }

        if ($request->filled('portfolio_id')) {
            $query->where('portfolio_id', $request->string('portfolio_id')->toString());
        }

        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }

        return RatePlanResource::collection(
            $query->orderByDesc('is_default')
                ->orderBy('priority')
                ->orderBy('name')
                ->paginate($this->perPage()),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', RatePlan::class);

        $data = $request->validate($this->rules());

        $plan = DB::transaction(function () use ($data): RatePlan {
            $plan = new RatePlan;
            $plan->fill($data);
            $plan->organization_id = $this->organization()->getKey();
            $plan->currency ??= $this->organization()->base_currency;
            $plan->slug = $this->uniqueSlug($data['slug'] ?? $data['name']);
            $plan->save();

            $this->settleDefault($plan);

            return $plan;
        });

        return (new RatePlanResource($plan))->response()->setStatusCode(201);
    }

    public function show(RatePlan $ratePlan): RatePlanResource
    {
        $this->authorize('view', $ratePlan);

        return new RatePlanResource($ratePlan->load(['parent', 'children']));
    }

    public function update(Request $request, RatePlan $ratePlan): RatePlanResource
    {
        $this->authorize('update', $ratePlan);

        $data = $request->validate($this->rules($ratePlan));

        if (isset($data['parent_rate_plan_id'])) {
            $this->assertNoCycle($ratePlan, $data['parent_rate_plan_id']);
        }

        DB::transaction(function () use ($ratePlan, $data): void {
            $ratePlan->fill($data);

            if (isset($data['slug'])) {
                $ratePlan->slug = $this->uniqueSlug($data['slug'], $ratePlan);
            }

            $ratePlan->save();

            $this->settleDefault($ratePlan);
        });

        return new RatePlanResource($ratePlan->fresh());
    }

    /**
     * Withdraw a plan from sale.
     *
     * Deactivated rather than deleted: every booking taken under this plan
     * points at it, and its cancellation terms are what those guests agreed
     * to. Plans with children are refused outright, because deactivating a
     * parent would leave its derived plans pricing against nothing.
     */
    public function destroy(RatePlan $ratePlan): JsonResponse
    {
        $this->authorize('delete', $ratePlan);

        abort_if(
            $ratePlan->is_default,
            422,
            'The default rate plan cannot be withdrawn. Make another plan the default first.',
        );

        $children = RatePlan::query()
            ->where('parent_rate_plan_id', $ratePlan->getKey())
            ->where('is_active', true)
            ->count();

        abort_if(
            $children > 0,
            422,
            sprintf(
                '%d plan(s) price relative to this one. Repoint or withdraw them first.',
                $children,
            ),
        );

        $ratePlan->forceFill(['is_active' => false])->save();

        return response()->json([
            'message' => 'The rate plan is no longer on sale. Bookings taken under it are unchanged.',
            'data' => new RatePlanResource($ratePlan->fresh()),
        ]);
    }

    /**
     * Exactly one default per organization.
     *
     * Done by demoting the others rather than by a unique index, because the
     * state "no default" is legitimate during a switch and a partial index
     * would make the switch impossible to perform in either order.
     */
    private function settleDefault(RatePlan $plan): void
    {
        if (! $plan->is_default) {
            return;
        }

        RatePlan::query()
            ->whereKeyNot($plan->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * Refuse a derivation that would ultimately point back at itself.
     *
     * Walks up the chain rather than checking only the immediate parent: A
     * derived from B derived from C derived from A is the same failure, and
     * only the walk catches it.
     */
    private function assertNoCycle(RatePlan $plan, ?string $parentId): void
    {
        abort_if(
            $parentId === $plan->getKey(),
            422,
            'A rate plan cannot be derived from itself.',
        );

        $seen = [$plan->getKey()];

        while ($parentId !== null) {
            if (in_array($parentId, $seen, true)) {
                abort(422, 'That parent would make the rate plans derive from each other in a loop.');
            }

            $seen[] = $parentId;
            $parentId = RatePlan::query()->whereKey($parentId)->value('parent_rate_plan_id');
        }
    }

    private function uniqueSlug(string $value, ?RatePlan $existing = null): string
    {
        $base = Str::slug($value) ?: 'rate-plan';
        $slug = $base;
        $suffix = 1;

        while (
            RatePlan::query()
                ->where('slug', $slug)
                ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->getKey()))
                ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?RatePlan $plan = null): array
    {
        $creating = $plan === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'property_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'portfolio_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'parent_rate_plan_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'derivation_type' => ['sometimes', 'nullable', Rule::in(['percent', 'fixed'])],
            // Signed: a derived plan can be dearer as well as cheaper.
            'derivation_value' => ['sometimes', 'nullable', 'numeric'],
            'cancellation_policy_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'minimum_nights' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'maximum_nights' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer'],
        ];
    }
}
