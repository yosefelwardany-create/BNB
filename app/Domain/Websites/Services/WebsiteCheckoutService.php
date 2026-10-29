<?php

declare(strict_types=1);

namespace App\Domain\Websites\Services;

use App\Domain\Availability\Exceptions\DatesUnavailableException;
use App\Domain\Listings\Models\Listing;
use App\Domain\Payments\Services\PaymentScheduleService;
use App\Domain\Pricing\Models\Quote;
use App\Domain\Pricing\Services\QuoteService;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Websites\Exceptions\CheckoutRefused;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Holding a stay for a guest on a website while they pay, and giving it back
 * when they do not.
 *
 * A hold is a real booking — tentative, so the nights close here and on every
 * channel within seconds — that lapses unless it is paid for (instant book)
 * or answered (request to book) in time.
 */
class WebsiteCheckoutService
{
    /** How many unpaid holds one visitor may have on a site at once. */
    public const MAX_ACTIVE_HOLDS = 3;

    public function __construct(
        private readonly ReservationService $reservations,
        private readonly PaymentScheduleService $schedules,
        private readonly QuoteService $quotes,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Hold the quoted stay for the guest.
     *
     * The guests and the price come from the quote: the price depends on
     * them, and the guest is held at the price they were shown or not at all.
     *
     * @param  array{first_name: string, last_name: string, email: string, phone: string, message?: string|null}  $guest
     *
     * @throws CheckoutRefused
     */
    public function hold(Website $site, Listing $listing, Quote $quote, array $guest, string $fingerprint): WebsiteCheckout
    {
        $active = WebsiteCheckout::query()
            ->where('website_id', $site->getKey())
            ->where('client_fingerprint', $fingerprint)
            ->active()
            ->count();

        // Each hold closes real nights on every channel; a visitor who could
        // hold without limit could take a home off the market for free.
        if ($active >= self::MAX_ACTIVE_HOLDS) {
            throw CheckoutRefused::tooManyHolds(self::MAX_ACTIVE_HOLDS);
        }

        if ($quote->converted_reservation_id !== null) {
            throw CheckoutRefused::spent();
        }

        if ($quote->expires_at === null || $quote->expires_at->isPast()) {
            throw CheckoutRefused::expired();
        }

        return $this->tenancy->runAs($listing->organization, function () use ($site, $listing, $quote, $guest, $fingerprint): WebsiteCheckout {
            $fresh = Listing::query()
                ->with(['property', 'cancellationPolicy', 'property.cancellationPolicy'])
                ->findOrFail($listing->getKey());

            $mode = $fresh->property?->instant_book ? WebsiteCheckout::MODE_INSTANT : WebsiteCheckout::MODE_REQUEST;
            $holdUntil = $mode === WebsiteCheckout::MODE_INSTANT
                ? CarbonImmutable::now()->addMinutes($site->hold_minutes)
                : CarbonImmutable::now()->addHours($site->request_hours);

            $request = new ReservationRequest(
                listing: $fresh,
                checkIn: $quote->check_in_date,
                checkOut: $quote->check_out_date,
                adults: $quote->adults,
                children: $quote->children,
                status: ReservationStatus::Tentative,
                source: 'direct',
                guestAttributes: [
                    'first_name' => $guest['first_name'],
                    'last_name' => $guest['last_name'],
                    'email' => $guest['email'],
                    'phone' => $guest['phone'],
                ],
                guestNotes: $guest['message'] ?? null,
                bookedAt: CarbonImmutable::now(),
            );

            // Priced exactly as the booking will be, before anything is
            // written: a rate edited since the quote is a new price to show,
            // not one to charge in silence.
            $price = $this->reservations->quote($request);

            if ($price->grandTotal()->minorUnits !== $quote->grand_total || $price->currency !== $quote->currency) {
                throw CheckoutRefused::priceChanged();
            }

            try {
                return DB::transaction(function () use ($site, $fresh, $quote, $guest, $fingerprint, $mode, $holdUntil, $request): WebsiteCheckout {
                    $reservation = $this->reservations->create($request);

                    $reservation->forceFill([
                        'website_id' => $site->getKey(),
                        'hold_expires_at' => $holdUntil,
                    ])->save();

                    // What the guest pays now: every instalment due by today.
                    $today = CarbonImmutable::today();
                    $dueNow = (int) $this->schedules->standardPlan($reservation)
                        ->filter(fn ($instalment): bool => CarbonImmutable::parse($instalment->due_on)->lessThanOrEqualTo($today))
                        ->sum('amount');
                    $this->quotes->markConverted($quote, $reservation->getKey());

                    return WebsiteCheckout::query()->create([
                        'access_token' => Str::random(48),
                        'website_id' => $site->getKey(),
                        'organization_id' => $fresh->organization_id,
                        'listing_id' => $fresh->getKey(),
                        'reservation_id' => $reservation->getKey(),
                        'quote_id' => $quote->getKey(),
                        'mode' => $mode,
                        'guest' => [
                            'first_name' => $guest['first_name'],
                            'last_name' => $guest['last_name'],
                            'email' => $guest['email'],
                            'phone' => $guest['phone'],
                            'message' => $guest['message'] ?? null,
                            'adults' => $quote->adults,
                            'children' => $quote->children,
                            'accepted_policies_at' => CarbonImmutable::now()->toIso8601String(),
                        ],
                        'currency' => $reservation->currency,
                        'total_amount' => (int) $reservation->grand_total,
                        'due_now_amount' => $dueNow,
                        'status' => WebsiteCheckout::STATUS_HOLDING,
                        'hold_expires_at' => $holdUntil,
                        'client_fingerprint' => $fingerprint,
                    ]);
                });
            } catch (DatesUnavailableException) {
                throw CheckoutRefused::taken();
            }
        });
    }

    /**
     * Give back the nights of a hold nobody paid for in time.
     *
     * Only the site's own tentative booking is cancelled: one that has since
     * been confirmed, or cancelled by staff, is left as it is.
     */
    public function release(WebsiteCheckout $checkout): void
    {
        $this->tenancy->runAs($checkout->organization, function () use ($checkout): void {
            DB::transaction(function () use ($checkout): void {
                $reservation = $checkout->reservation_id === null
                    ? null
                    : Reservation::query()->find($checkout->reservation_id);

                if ($reservation !== null && $reservation->status === ReservationStatus::Tentative) {
                    $this->reservations->cancel($reservation, 'Not paid for in time on the website.', 'system', waiveFees: true);
                }

                $checkout->forceFill(['status' => WebsiteCheckout::STATUS_EXPIRED])->save();
            });
        });
    }
}
