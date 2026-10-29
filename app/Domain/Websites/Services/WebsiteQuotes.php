<?php

declare(strict_types=1);

namespace App\Domain\Websites\Services;

use App\Domain\Availability\DataObjects\DayAvailability;
use App\Domain\Availability\Services\AvailabilityEngine;
use App\Domain\Listings\Models\Listing;
use App\Domain\Payments\Services\PaymentScheduleService;
use App\Domain\Pricing\DataObjects\PricingContext;
use App\Domain\Pricing\Models\Quote;
use App\Domain\Pricing\Services\QuoteService;
use App\Domain\Websites\DataObjects\SearchCriteria;
use App\Domain\Websites\Support\SiteFormat;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * What a site's booking box asks for: which nights are open, and what a stay
 * costs.
 *
 * A quote is priced on the direct channel and stored, so the guest who goes
 * on to pay is charged the figure they were shown. What goes back to the page
 * is the guest's view of it: the prices, never the rules and traces behind
 * them.
 */
class WebsiteQuotes
{
    /** How far ahead of the month asked for the calendar reaches. */
    public const CALENDAR_MONTHS = 2;

    public function __construct(
        private readonly WebsiteCatalogue $catalogue,
        private readonly AvailabilityEngine $availability,
        private readonly QuoteService $quotes,
        private readonly PaymentScheduleService $schedules,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Two months of nights from the first of $month. A night already past
     * where the home is — its own date, not the server's — is never offered,
     * whatever the calendar holds for it.
     *
     * @return list<array{date: string, available: bool, min_nights: int|null, closed_to_arrival: bool}>
     */
    public function calendar(Listing $listing, CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $today = self::localToday($listing)->toDateString();

        $days = $this->tenancy->runAs($listing->organization, fn (): array => $this->availability->calendar(
            Listing::query()->with('property')->findOrFail($listing->getKey()),
            $from,
            $from->addMonthsNoOverflow(self::CALENDAR_MONTHS),
        ));

        return array_map(fn (DayAvailability $day): array => [
            'date' => $day->date,
            'available' => $day->isAvailable && $day->date >= $today,
            'min_nights' => $day->minimumNights,
            'closed_to_arrival' => $day->closedToArrival,
        ], $days);
    }

    /**
     * Price and store a stay, or say why it cannot be had.
     *
     * A refusal stores nothing: a quote is a promise of a price for nights
     * that can be sold.
     *
     * @return array{quote: ?Quote, reasons: list<string>}
     */
    public function quote(Listing $listing, CarbonImmutable $checkIn, CarbonImmutable $checkOut, int $adults, int $children): array
    {
        $outcome = $this->catalogue->whenBookable(
            $listing,
            new SearchCriteria(checkIn: $checkIn, checkOut: $checkOut, guests: $adults + $children),
            fn (Listing $fresh): Quote => $this->quotes->create(new PricingContext(
                listing: $fresh,
                checkIn: $checkIn,
                checkOut: $checkOut,
                adults: $adults,
                children: $children,
                channel: 'direct',
            ))->setRelation('listing', $fresh),
        );

        return ['quote' => $outcome['result'], 'reasons' => $outcome['reasons']];
    }

    /**
     * The guest's view of a quote.
     *
     * Its mode is how the stay would be booked: at once, or as a request the
     * host answers. Due now is what the standard plan asks for by today; later
     * is the rest of it, with the dates.
     *
     * @return array<string, mixed>
     */
    public function present(Quote $quote): array
    {
        $currency = $quote->currency;
        $breakdown = $quote->breakdown ?? [];
        // Amounts as written on the page; a discount is stored as a negative
        // line, and the page puts the minus sign in front of it itself.
        $lines = fn (string $key): array => array_map(fn (array $line): array => [
            'label' => (string) ($line['label'] ?? ''),
            'amount' => $this->money(Money::of((int) ($line['amount']['amount'] ?? 0), $currency)->absolute()),
        ], $breakdown[$key] ?? []);

        $total = Money::of($quote->grand_total, $currency);
        // Everything due by today is paid now: a stay inside the balance
        // window is paid in full, not a deposit and a balance the same day.
        $today = CarbonImmutable::today();
        $plan = $this->schedules->standardPreview($total, $quote->check_in_date);
        $now = array_filter($plan, fn (array $instalment): bool => $instalment['due_on']->lessThanOrEqualTo($today));
        $later = array_values(array_filter($plan, fn (array $instalment): bool => $instalment['due_on']->greaterThan($today)));
        $dueNow = array_reduce($now, fn (Money $sum, array $instalment): Money => $sum->add($instalment['amount']), Money::zero($currency));

        return [
            'quote_id' => $quote->getKey(),
            'currency' => $currency,
            'check_in' => $quote->check_in_date->toDateString(),
            'check_out' => $quote->check_out_date->toDateString(),
            'adults' => $quote->adults,
            'children' => $quote->children,
            'nights' => array_map(fn (array $night): array => [
                'date' => (string) $night['date'],
                'amount' => $this->money(Money::of((int) ($night['rate']['amount'] ?? 0), $currency)),
            ], $breakdown['nights'] ?? []),
            'fees' => $lines('fees'),
            'discounts' => $lines('discounts'),
            'taxes' => $lines('taxes'),
            'totals' => [
                'accommodation' => $this->money(Money::of($quote->accommodation_total, $currency)),
                'fees' => $this->money(Money::of($quote->fees_total, $currency)),
                'discounts' => $this->money(Money::of($quote->discounts_total, $currency)->absolute()),
                'taxes' => $this->money(Money::of($quote->taxes_total, $currency)),
                'grand_total' => $this->money($total),
            ],
            'due_now' => $this->money($plan === [] ? $total : $dueNow),
            // What is left, and when it falls due.
            'later' => array_map(fn (array $instalment): array => [
                'amount' => $this->money($instalment['amount']),
                'due_on' => $instalment['due_on']->toDateString(),
            ], $later),
            'mode' => $quote->listing?->property?->instant_book ? 'instant' : 'request',
            'expires_at' => $quote->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Today where the home is.
     *
     * A guest in Bogotá can still book tonight at 21:00, when the server's
     * clock has moved on to tomorrow; one in Tokyo at 05:00 cannot book a
     * night the server still calls today.
     */
    public static function localToday(Listing $listing): CarbonImmutable
    {
        return ($listing->property?->localNow() ?? CarbonImmutable::now())->startOfDay();
    }

    /**
     * @return array{amount: int, currency: string, display: string}
     */
    private function money(Money $money): array
    {
        return [
            'amount' => $money->minorUnits,
            'currency' => $money->currency,
            'display' => SiteFormat::money($money),
        ];
    }
}
