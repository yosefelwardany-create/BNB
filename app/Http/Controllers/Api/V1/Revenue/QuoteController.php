<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Revenue;

use App\Domain\Listings\Models\Listing;
use App\Domain\Pricing\DataObjects\PricingContext;
use App\Domain\Pricing\Models\Quote;
use App\Domain\Pricing\Models\RatePlan;
use App\Domain\Pricing\Services\QuoteService;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Prices, quoted and held.
 *
 * A quote is stored rather than recomputed because a guest who was shown a
 * number and comes back to pay must be charged that number. Rates move — a
 * rule starts, a promotion ends, a neighbouring booking makes the night
 * scarce — and re-pricing at the moment of payment would make the
 * confirmation screen and the card charge disagree.
 *
 * Availability is reported alongside the price rather than enforced. "This is
 * what those dates would cost, and they are no longer free" is a more useful
 * answer than a refusal, and it is what lets a booking engine offer
 * alternatives. Availability becomes a refusal at the moment of booking, where
 * it is checked inside the same lock that writes the reservation.
 */
class QuoteController extends Controller
{
    public function __construct(private readonly QuoteService $quotes) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Quote::class);

        $query = Quote::query();

        if ($request->filled('listing_id')) {
            $query->where('listing_id', $request->string('listing_id')->toString());
        }

        if ($request->filled('guest_id')) {
            $query->where('guest_id', $request->string('guest_id')->toString());
        }

        if ($request->boolean('live_only')) {
            $query->live();
        }

        return QuoteResource::collection(
            $query->latest()->paginate($this->perPage()),
        );
    }

    /**
     * Price a stay and hold the price.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Quote::class);

        $data = $request->validate([
            'listing_id' => ['required', 'string', 'size:26'],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'infants' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'pets' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'channel' => ['sometimes', 'string', 'max:48'],
            'rate_plan_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'promotion_code' => ['sometimes', 'nullable', 'string', 'max:48'],
            'include_optional_fees' => ['sometimes', 'boolean'],
            'valid_for_minutes' => ['sometimes', 'integer', 'min:1', 'max:10080'],
        ]);

        $listing = Listing::query()->findOrFail($data['listing_id']);

        $checkIn = CarbonImmutable::parse($data['check_in'])->startOfDay();
        $checkOut = CarbonImmutable::parse($data['check_out'])->startOfDay();

        abort_if(
            $checkIn->diffInDays($checkOut) > 365,
            422,
            'A quote covers at most 365 nights.',
        );

        $quote = $this->quotes->create(
            new PricingContext(
                listing: $listing,
                checkIn: $checkIn,
                checkOut: $checkOut,
                adults: (int) ($data['adults'] ?? 2),
                children: (int) ($data['children'] ?? 0),
                infants: (int) ($data['infants'] ?? 0),
                pets: (int) ($data['pets'] ?? 0),
                channel: $data['channel'] ?? 'direct',
                ratePlan: isset($data['rate_plan_id'])
                    ? RatePlan::query()->find($data['rate_plan_id'])
                    : null,
                promotionCode: isset($data['promotion_code'])
                    ? strtoupper($data['promotion_code'])
                    : null,
                includeOptionalFees: (bool) ($data['include_optional_fees'] ?? false),
            ),
            $data['valid_for_minutes'] ?? null,
        );

        return (new QuoteResource($quote))
            ->additional(['meta' => ['availability' => $this->quotes->availability($quote)->toArray()]])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * A quote, with whether it can still be acted on.
     *
     * Both facts are reported: whether the *price* still stands (has it
     * expired) and whether the *dates* still do (is anything left to sell).
     * They fail independently, and a booking engine needs to tell the guest
     * which one went wrong.
     */
    public function show(Quote $quote): JsonResponse
    {
        $this->authorize('view', $quote);

        return (new QuoteResource($quote->load(['listing', 'property'])))
            ->additional(['meta' => ['availability' => $this->quotes->availability($quote)->toArray()]])
            ->response();
    }
}
