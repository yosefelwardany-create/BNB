<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Revenue;

use App\Domain\Listings\Models\Listing;
use App\Domain\Pricing\DataObjects\PricingContext;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Pricing\Services\PricingEngine;
use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Guest-facing discounts.
 *
 * A promotion with a code is handed out in a campaign; one without applies
 * automatically to every eligible booking. The distinction is the presence of
 * the code, not a separate flag, so the two can never disagree.
 *
 * The `check` endpoint is what a booking engine calls when a guest types a
 * code. It returns the *reasons* a code does not apply rather than a bare
 * refusal, because "this code needs three nights" is something the guest can
 * act on and "invalid code" is not.
 */
class PromotionController extends Controller
{
    public function __construct(private readonly PricingEngine $pricing) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Promotion::class);

        $query = Promotion::query();

        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }

        if ($request->boolean('automatic_only')) {
            $query->whereNull('code');
        }

        if ($request->filled('code')) {
            $query->where('code', strtoupper($request->string('code')->toString()));
        }

        if ($request->filled('property_id')) {
            $property = $request->string('property_id')->toString();

            // An empty property list means every property, so those must be
            // included or a property-filtered view looks emptier than it is.
            $query->where(function ($q) use ($property): void {
                $q->whereNull('property_ids')
                    ->orWhereJsonContains('property_ids', $property);
            });
        }

        return PromotionResource::collection(
            $query->orderByDesc('created_at')->paginate($this->perPage()),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Promotion::class);

        $data = $request->validate($this->rules());

        $promotion = new Promotion;
        $promotion->fill($data);
        $promotion->organization_id = $this->organization()->getKey();
        $promotion->currency ??= $this->organization()->base_currency;
        // Codes are matched case-insensitively by storing them uppercase; a
        // guest typing "summer24" should not be told their code is unknown.
        $promotion->code = isset($data['code']) ? strtoupper($data['code']) : null;
        $promotion->created_by_id = auth()->id();
        $promotion->save();

        return (new PromotionResource($promotion))->response()->setStatusCode(201);
    }

    public function show(Promotion $promotion): PromotionResource
    {
        $this->authorize('view', $promotion);

        return new PromotionResource($promotion);
    }

    public function update(Request $request, Promotion $promotion): PromotionResource
    {
        $this->authorize('update', $promotion);

        $data = $request->validate($this->rules($promotion));

        $promotion->fill($data);

        if (array_key_exists('code', $data)) {
            $promotion->code = $data['code'] === null ? null : strtoupper($data['code']);
        }

        $promotion->save();

        return new PromotionResource($promotion->fresh());
    }

    /**
     * Withdraw a promotion.
     *
     * Deactivated, not deleted: bookings discounted under it point at it, and
     * the record is the answer to "why was this booking cheaper".
     */
    public function destroy(Promotion $promotion): JsonResponse
    {
        $this->authorize('delete', $promotion);

        $promotion->forceFill(['is_active' => false])->save();

        return response()->json([
            'message' => 'The promotion has been withdrawn. Bookings already discounted under it are unchanged.',
            'data' => new PromotionResource($promotion->fresh()),
        ]);
    }

    /**
     * Whether a code applies to a particular stay, and why not when it does
     * not.
     *
     * Reasons rather than a boolean: a guest told "this code needs three
     * nights" can book three nights, and one told "invalid code" abandons.
     */
    public function check(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Promotion::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:48'],
            'listing_id' => ['required', 'string', 'size:26'],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'channel' => ['sometimes', 'string', 'max:48'],
        ]);

        $promotion = Promotion::query()
            ->where('code', strtoupper($data['code']))
            ->first();

        if ($promotion === null) {
            return response()->json([
                'data' => [
                    'applies' => false,
                    // Deliberately the same shape as an ineligible code, so a
                    // caller cannot use this endpoint to enumerate which codes
                    // exist by comparing responses.
                    'reasons' => ['That code is not valid for this stay.'],
                ],
            ]);
        }

        $listing = Listing::query()->findOrFail($data['listing_id']);

        $context = new PricingContext(
            listing: $listing,
            checkIn: CarbonImmutable::parse($data['check_in'])->startOfDay(),
            checkOut: CarbonImmutable::parse($data['check_out'])->startOfDay(),
            adults: (int) ($data['adults'] ?? 2),
            children: (int) ($data['children'] ?? 0),
            channel: $data['channel'] ?? 'direct',
            promotionCode: strtoupper($data['code']),
        );

        // The stay has to be priced before eligibility can be judged: a
        // minimum-spend condition is a question about the accommodation total,
        // not about the dates.
        $priced = $this->pricing->quote($context);

        $errors = $promotion->eligibilityErrors(
            bookingDate: $context->bookedOn(),
            checkIn: $context->checkIn,
            checkOut: $context->checkOut,
            propertyId: (string) $listing->property_id,
            channel: $context->channel,
            accommodationTotal: $priced->accommodationTotal(),
        );

        return response()->json([
            'data' => [
                'applies' => $errors === [],
                'reasons' => $errors,
                'promotion' => $errors === [] ? new PromotionResource($promotion) : null,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Promotion $promotion = null): array
    {
        $creating = $promotion === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'code' => [
                'sometimes', 'nullable', 'string', 'max:48', 'alpha_dash',
                Rule::unique('promotions', 'code')
                    ->where('organization_id', $this->organization()->getKey())
                    ->ignore($promotion?->getKey()),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'discount_type' => [$creating ? 'required' : 'sometimes', Rule::in([
                Promotion::PERCENT, Promotion::FIXED, Promotion::FREE_NIGHTS,
            ])],
            // A percentage, an amount in minor units, or a number of nights,
            // depending on the type above.
            'discount_value' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],

            'bookable_from' => ['sometimes', 'nullable', 'date'],
            'bookable_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:bookable_from'],
            'stay_from' => ['sometimes', 'nullable', 'date'],
            'stay_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:stay_from'],

            'minimum_nights' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'minimum_spend' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'maximum_uses' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'maximum_uses_per_guest' => ['sometimes', 'nullable', 'integer', 'min:1'],

            'property_ids' => ['sometimes', 'nullable', 'array'],
            'property_ids.*' => ['string', 'size:26'],
            'channels' => ['sometimes', 'nullable', 'array'],
            'channels.*' => ['string', 'max:48'],

            'combinable' => ['sometimes', 'boolean'],
            'applies_to_fees' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
