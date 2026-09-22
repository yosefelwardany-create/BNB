<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Revenue;

use App\Domain\Listings\Models\Listing;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\RatePlan;
use App\Domain\Pricing\Services\PricingEngine;
use App\Http\Controllers\Controller;
use App\Http\Resources\PricingRuleResource;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The rules that move a nightly rate away from its base.
 *
 * Each rule is one deterministic adjustment, applied in priority order, each
 * operating on the result of the last. Conditions are evaluated against a
 * fixed vocabulary — there is no expression language here and nothing is ever
 * eval'd, because a pricing rule is written by an operator and executed on a
 * server.
 *
 * The `preview` endpoint exists because a revenue manager needs to see what a
 * rule *would* do before it starts doing it to real bookings. It prices a real
 * date range through the real engine rather than approximating, so what the
 * preview shows and what a guest is charged cannot drift apart.
 */
class PricingRuleController extends Controller
{
    public function __construct(private readonly PricingEngine $pricing) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PricingRule::class);

        $query = PricingRule::query();

        if ($request->filled('property_id')) {
            $property = $request->string('property_id')->toString();

            // A rule with no property applies to everything, so it belongs in
            // the answer to "what prices this property".
            $query->where(fn ($q) => $q->where('property_id', $property)->orWhereNull('property_id'));
        }

        if ($request->filled('listing_id')) {
            $listing = $request->string('listing_id')->toString();

            $query->where(fn ($q) => $q->where('listing_id', $listing)->orWhereNull('listing_id'));
        }

        if ($request->filled('rate_plan_id')) {
            $query->where('rate_plan_id', $request->string('rate_plan_id')->toString());
        }

        if ($request->filled('kind')) {
            $query->whereIn('kind', (array) $request->input('kind'));
        }

        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }

        // Which rules are live for a given night: the question a revenue
        // manager actually asks when a price looks wrong.
        if ($request->filled('on')) {
            $on = $request->date('on')->toDateString();

            $query->where(fn ($q) => $q->whereNull('stay_from')->orWhere('stay_from', '<=', $on))
                ->where(fn ($q) => $q->whereNull('stay_to')->orWhere('stay_to', '>=', $on));
        }

        return PricingRuleResource::collection(
            $query->orderBy('priority')->orderBy('id')->paginate($this->perPage()),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', PricingRule::class);

        $rule = new PricingRule;
        $rule->fill($request->validate($this->rules()));
        $rule->organization_id = $this->organization()->getKey();
        $rule->created_by_id = auth()->id();
        $rule->save();

        return (new PricingRuleResource($rule))->response()->setStatusCode(201);
    }

    public function show(PricingRule $pricingRule): PricingRuleResource
    {
        $this->authorize('view', $pricingRule);

        return new PricingRuleResource($pricingRule);
    }

    public function update(Request $request, PricingRule $pricingRule): PricingRuleResource
    {
        $this->authorize('update', $pricingRule);

        $pricingRule->fill($request->validate($this->rules($pricingRule)))->save();

        return new PricingRuleResource($pricingRule->fresh());
    }

    /**
     * Switch a rule off.
     *
     * Deactivated rather than deleted: this rule is the explanation for what
     * every booking it priced was charged, and deleting it does not un-charge
     * anybody — it only makes the charge unexplainable.
     */
    public function destroy(PricingRule $pricingRule): JsonResponse
    {
        $this->authorize('delete', $pricingRule);

        $pricingRule->forceFill(['is_active' => false])->save();

        return response()->json([
            'message' => 'The rule is switched off. Bookings it already priced are unchanged.',
            'data' => new PricingRuleResource($pricingRule->fresh()),
        ]);
    }

    /**
     * What the rates would be, with and without a rule.
     *
     * Prices the window twice through the real engine — once as things stand,
     * once with the rule suppressed — and reports both. Approximating the
     * difference arithmetically would be easier and would be wrong the moment
     * a floor, a ceiling or an exclusive rule interacts with it.
     */
    public function preview(Request $request, PricingRule $pricingRule): JsonResponse
    {
        $this->authorize('view', $pricingRule);

        $data = $request->validate([
            'listing_id' => ['required', 'string', 'size:26'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'rate_plan_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        $listing = Listing::query()->findOrFail($data['listing_id']);
        $ratePlan = isset($data['rate_plan_id'])
            ? RatePlan::query()->find($data['rate_plan_id'])
            : null;

        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->startOfDay();

        abort_if(
            $from->diffInDays($to) > 400,
            422,
            'A preview covers at most 400 nights.',
        );

        // Priced twice: once as things stand, once with this rule excluded.
        // The exclusion is a parameter rather than a temporary deactivation —
        // switching the rule off in the database, even briefly, would quote
        // every concurrent booking the wrong price for as long as the preview
        // took, and would leave it off altogether if the request died.
        $withRule = $this->pricing->rateCalendar($listing, $from, $to, $ratePlan);
        $withoutRule = $this->pricing->rateCalendar(
            $listing,
            $from,
            $to,
            $ratePlan,
            [(string) $pricingRule->getKey()],
        );

        $nights = [];
        $affected = 0;

        foreach ($withRule as $date => $rate) {
            $base = $withoutRule[$date] ?? $rate;
            $changed = ! $rate->equals($base);

            if ($changed) {
                $affected++;
            }

            $nights[] = [
                'date' => $date,
                'rate_without_rule' => $base->jsonSerialize(),
                'rate_with_rule' => $rate->jsonSerialize(),
                'difference' => $rate->subtract($base)->jsonSerialize(),
                'changed' => $changed,
            ];
        }

        return response()->json([
            'data' => [
                'rule' => new PricingRuleResource($pricingRule),
                'listing_id' => $listing->getKey(),
                'nights_previewed' => count($nights),
                'nights_affected' => $affected,
                'total_difference' => $this->totalDifference($withRule, $withoutRule, $listing->currency),
                'nights' => $nights,
            ],
        ]);
    }

    /**
     * @param  array<string, Money>  $with
     * @param  array<string, Money>  $without
     * @return array<string, mixed>
     */
    private function totalDifference(array $with, array $without, string $currency): array
    {
        $total = Money::zero($currency);

        foreach ($with as $date => $rate) {
            $total = $total->add($rate->subtract($without[$date] ?? $rate));
        }

        return $total->jsonSerialize();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?PricingRule $rule = null): array
    {
        $creating = $rule === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'kind' => [$creating ? 'required' : 'sometimes', 'string', Rule::in([
                PricingRule::KIND_BASE,
                PricingRule::KIND_SEASONAL,
                PricingRule::KIND_DAY_OF_WEEK,
                PricingRule::KIND_LENGTH_OF_STAY,
                PricingRule::KIND_OCCUPANCY,
                PricingRule::KIND_EARLY_BOOKING,
                PricingRule::KIND_LAST_MINUTE,
                PricingRule::KIND_GAP_NIGHT,
                PricingRule::KIND_ORPHAN_NIGHT,
                PricingRule::KIND_CUSTOM,
            ])],

            'property_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'listing_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'unit_type_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'portfolio_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'rate_plan_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'channels' => ['sometimes', 'nullable', 'array'],
            'channels.*' => ['string', 'max:48'],

            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],
            'stay_from' => ['sometimes', 'nullable', 'date'],
            'stay_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:stay_from'],

            // 0 = Sunday through 6 = Saturday.
            'days_of_week' => ['sometimes', 'nullable', 'array'],
            'days_of_week.*' => ['integer', 'min:0', 'max:6'],

            'conditions' => ['sometimes', 'nullable', 'array'],

            'adjustment_type' => [$creating ? 'required' : 'sometimes', Rule::in([
                'set', 'increase_percent', 'decrease_percent', 'increase_fixed', 'decrease_fixed',
            ])],
            // Minor units for the fixed kinds, basis points for the percentage
            // ones. Always an integer, so a percentage can never drift.
            'adjustment_value' => [$creating ? 'required' : 'sometimes', 'integer'],

            'floor_rate' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'ceiling_rate' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'priority' => ['sometimes', 'integer'],
            'is_exclusive' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
