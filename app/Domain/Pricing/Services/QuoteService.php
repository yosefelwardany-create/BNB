<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Availability\DataObjects\AvailabilityRequest;
use App\Domain\Availability\DataObjects\AvailabilityResult;
use App\Domain\Availability\Services\AvailabilityEngine;
use App\Domain\Platform\Services\SequenceGenerator;
use App\Domain\Pricing\DataObjects\PriceQuote;
use App\Domain\Pricing\DataObjects\PricingContext;
use App\Domain\Pricing\Models\Quote;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turning a price into a promise.
 *
 * The engine computes a price; this stores it, so the guest who was shown a
 * number and comes back to pay is charged that number. Re-pricing at the
 * moment of payment is the obvious implementation and the wrong one: rates
 * move, and the confirmation screen and the card charge would disagree.
 *
 * A quote expires because the promise cannot be open-ended — the night may be
 * sold to somebody else, and a price held indefinitely is an option the
 * business never agreed to write.
 */
class QuoteService
{
    public function __construct(
        private readonly PricingEngine $pricing,
        private readonly AvailabilityEngine $availability,
        private readonly SequenceGenerator $sequences,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Price a stay and record the quote.
     *
     * Availability is checked and reported rather than enforced: a quote for
     * dates that are taken is still a useful answer ("this is what it would
     * cost"), and refusing to price them would make the booking engine
     * unable to say why. Booking is where availability becomes a refusal.
     */
    public function create(PricingContext $context, ?int $validForMinutes = null): Quote
    {
        $organization = $this->tenancy->organizationOrFail();
        $priced = $this->pricing->quote($context);

        $validForMinutes ??= (int) config('pms.pricing.quote_ttl_minutes', 60);

        return DB::transaction(function () use ($context, $priced, $organization, $validForMinutes): Quote {
            return Quote::query()->create([
                'organization_id' => $organization->getKey(),
                'listing_id' => $context->listing->getKey(),
                'property_id' => $context->listing->property_id,
                'reference' => $this->sequences->next(
                    $organization->getKey(),
                    SequenceGenerator::QUOTE,
                    'QTE',
                    6,
                ),
                'check_in_date' => $context->checkIn->toDateString(),
                'check_out_date' => $context->checkOut->toDateString(),
                'nights' => $context->nights(),
                'adults' => $context->adults,
                'children' => $context->children,
                'infants' => $context->infants,
                'pets' => $context->pets,
                'currency' => $priced->currency,
                'accommodation_total' => $priced->accommodationTotal()->minorUnits,
                'fees_total' => $priced->feesTotal()->minorUnits,
                'taxes_total' => $priced->taxesTotal()->minorUnits,
                'discounts_total' => $priced->discountsTotal()->minorUnits,
                'grand_total' => $priced->grandTotal()->minorUnits,
                // The whole itemisation, frozen. Every rule behind these
                // figures can be edited tomorrow; the explanation must not
                // change with them.
                'breakdown' => $this->breakdown($priced, $context),
                'channel' => $context->channel,
                'promotion_id' => $priced->promotionId,
                'rate_plan_id' => $priced->ratePlanId,
                'expires_at' => CarbonImmutable::now()->addMinutes($validForMinutes),
            ]);
        });
    }

    /**
     * Whether the dates behind a quote can still be sold.
     *
     * Asked at booking time, not at quote time: a quote is a price, and
     * availability at the moment of quoting says nothing about availability
     * ten minutes later.
     */
    public function availability(Quote $quote): AvailabilityResult
    {
        $listing = $quote->listing;
        $property = $quote->property;

        if ($property === null) {
            return AvailabilityResult::unavailable(['The property behind this quote no longer exists.']);
        }

        return $this->availability->check(new AvailabilityRequest(
            property: $property,
            checkIn: CarbonImmutable::parse($quote->check_in_date),
            checkOut: CarbonImmutable::parse($quote->check_out_date),
            listing: $listing,
            guests: (int) $quote->adults + (int) $quote->children,
        ));
    }

    /**
     * Mark a quote as having become a booking.
     *
     * Kept rather than deleted, and linked: "what were they quoted" is the
     * first question asked when a guest disputes what they were charged.
     */
    public function markConverted(Quote $quote, string $reservationId): Quote
    {
        $quote->forceFill(['converted_reservation_id' => $reservationId])->save();

        return $quote;
    }

    /**
     * The stored explanation.
     *
     * The engine's own itemisation, plus the occupancy and channel it was
     * quoted for — without those the numbers cannot be checked, because the
     * same dates price differently for four guests than for two.
     *
     * @return array<string, mixed>
     */
    private function breakdown(PriceQuote $priced, PricingContext $context): array
    {
        return $priced->toArray() + [
            'quoted_for' => [
                'adults' => $context->adults,
                'children' => $context->children,
                'infants' => $context->infants,
                'pets' => $context->pets,
                'channel' => $context->channel,
                'booking_date' => $context->bookedOn()->toDateString(),
            ],
        ];
    }
}
