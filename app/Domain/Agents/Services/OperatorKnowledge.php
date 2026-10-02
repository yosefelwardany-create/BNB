<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Operations\Models\Task;
use App\Domain\Pricing\Services\RevenueAnalytics;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyHelper;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use Carbon\CarbonImmutable;

/**
 * What an agent may be told when the person asking owns the place.
 *
 * {@see PropertyKnowledge} answers a different question. It assembles what a
 * *guest* may be told — the address, the check-in window, the house rules, and a
 * door code only where the booking is entitled to one. It deliberately contains
 * no revenue, no occupancy and no bookings, because a guest has no business
 * seeing them. Which means an owner asking "how did Yellow do last month?" gets
 * an agent with nothing to answer from, and the honest fix is a different set of
 * facts rather than a looser guest one.
 *
 * The gate changes shape as well as content. For a guest it is the booking: are
 * they confirmed, paid and inside the window. Here there is no booking — the
 * asker is a signed-in member of the organization, and what they may know is
 * what their role already lets them read elsewhere in the product. So every
 * block below is behind the same permission as the screen it comes from. A
 * cleaner asking about a flat gets its turnover list and not its revenue,
 * exactly as they would by clicking around.
 *
 * That matters more here than on a screen. A screen withholds a figure by not
 * drawing it; a prompt withholds it by not containing it, and anything that
 * reaches the prompt can be read back out of the answer by whoever asked —
 * possibly from a bot on somebody else's infrastructure. Permissions are
 * therefore applied when the facts are assembled, before the question is read,
 * which is the same rule the guest side follows for the same reason.
 */
class OperatorKnowledge
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly RevenueAnalytics $analytics,
        private readonly PropertyKnowledge $knowledge,
    ) {}

    /**
     * Everything this person may be told about how this property is doing.
     *
     * @return array{facts: array<string, mixed>, withheld: list<string>}
     */
    public function about(Property $property, ?User $asker): array
    {
        $facts = ['property' => $this->identity($property)];
        $withheld = [];

        if ($this->may($asker, 'revenue.view')) {
            $facts['performance'] = $this->performance($property);
        } else {
            // Named rather than silently absent, so the agent can say "I can't
            // see the numbers" instead of inventing a plausible one — which is
            // what a model does with a question it has no facts for.
            $withheld[] = 'Revenue, occupancy and rate figures are not included, because this account cannot view revenue.';
        }

        if ($this->may($asker, 'reservations.view')) {
            $facts['bookings'] = $this->bookings($property);
        } else {
            $withheld[] = 'Booking counts and arrival dates are not included, because this account cannot view reservations.';
        }

        if ($this->may($asker, 'tasks.view')) {
            $facts['operations'] = $this->operations($property);
        }

        // Always, and for everybody who may see the property. Knowing who to
        // ring about a broken boiler is not privileged information — it is the
        // whole reason the list exists, and an agent that cannot name the
        // electrician at midnight is of no use to the person asking.
        $facts['helpers'] = $this->helpers($property);

        /*
         * Every document, guest-safe or not.
         *
         * The person asking is a signed-in member of the organization looking at
         * their own property. The guest-safe flag governs what reaches a guest;
         * it has nothing to say about what the manager may read, and withholding
         * the house manual from them would make this agent useless for the thing
         * it is most often asked.
         */
        $facts['knowledge'] = $this->knowledge->documents($property, guestSafeOnly: false);

        return ['facts' => $facts, 'withheld' => $withheld];
    }

    /**
     * What the property is, before any figures.
     *
     * @return array<string, mixed>
     */
    private function identity(Property $property): array
    {
        return array_filter([
            'name' => $property->display_name ?? $property->name,
            // The enum's label. `property_type_label` is composed by the API
            // resource and is not an attribute on the model.
            'type' => $property->property_type?->label(),
            'status' => $property->status->value,
            'city' => $property->city,
            'country' => $property->country_code,
            'timezone' => $property->timezone,
            'bedrooms' => $property->bedrooms,
            'sleeps' => $property->max_occupancy,
            'on_the_books_since' => $property->activated_at?->toDateString(),
            // The offers, not the asset. An operator asking why a flat is empty
            // is often looking at a property with every listing still in draft.
            'listings' => $property->listings()
                ->get(['name', 'status'])
                ->map(fn ($listing): string => sprintf(
                    '%s (%s)',
                    $listing->name ?? 'unnamed',
                    $listing->status instanceof \BackedEnum ? $listing->status->value : (string) $listing->status,
                ))
                ->values()
                ->all(),
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * The four numbers, over three windows.
     *
     * Last 30 days and last 90 days because "how did it do" means a trend, and a
     * single window invites an agent to call one slow month a decline. The next
     * 30 days are on the books rather than earned, and are labelled as such —
     * confusing booked-ahead revenue with banked revenue is the most expensive
     * mistake available on this screen.
     *
     * @return array<string, mixed>
     */
    private function performance(Property $property): array
    {
        $today = CarbonImmutable::today();
        $ids = [(string) $property->getKey()];

        return [
            'last_30_days' => $this->window($this->analytics->summary($today->subDays(29), $today, $ids)),
            'last_90_days' => $this->window($this->analytics->summary($today->subDays(89), $today, $ids)),
            'next_30_days_on_the_books' => $this->pace($this->analytics->pace($today, $today->addDays(29), $ids)),
            'definitions' => [
                'occupancy_percent' => 'Nights sold over nights the property was inventory. A blocked night still counts as available, so blocking rooms cannot improve it.',
                'adr' => 'Accommodation revenue over nights SOLD — what the nights that sold went for.',
                'revpar' => 'Accommodation revenue over nights AVAILABLE — what the property earned overall. ADR can rise while RevPAR falls.',
                'revenue' => 'Accommodation only. Cleaning fees, pet fees and tax are excluded, because they are not room revenue.',
            ],
        ];
    }

    /**
     * One window's figures, flattened to what a model can read.
     *
     * Money arrives as an object with minor units, a currency and a formatted
     * string. The formatted string is the one to send: a model given 145000 and
     * a currency code will sooner or later report it as a hundred and forty-five
     * thousand.
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function window(array $summary): array
    {
        return [
            'from' => $summary['period']['from'],
            'to' => $summary['period']['to'],
            'occupancy_percent' => $summary['occupancy_rate'],
            'nights_sold' => $summary['nights_sold'],
            'nights_available' => $summary['nights_available'],
            'accommodation_revenue' => $this->money($summary['accommodation_revenue'] ?? null),
            'adr' => $this->money($summary['adr'] ?? null),
            'revpar' => $this->money($summary['revpar'] ?? null),
            'bookings' => $summary['reservations'],
            'average_stay_nights' => $summary['average_stay_nights'],
        ];
    }

    /**
     * @param  array<string, mixed>  $pace
     * @return array<string, mixed>
     */
    private function pace(array $pace): array
    {
        return [
            'from' => $pace['period']['from'],
            'to' => $pace['period']['to'],
            'bookings_held' => $pace['reservations_on_the_books'],
            'nights_held' => $pace['nights_on_the_books'],
            'occupancy_percent_held' => $pace['occupancy_on_the_books'],
            'revenue_held' => $this->money($pace['revenue_on_the_books'] ?? null),
            'average_lead_time_days' => $pace['average_lead_time_days'],
            'note' => 'These are bookings held for future dates, not money earned. They can still cancel.',
        ];
    }

    /**
     * An amount with its currency attached.
     *
     * The serialised form carries the minor units, the currency and a decimal
     * string, and none of the three is safe to send alone. A model given 145000
     * will eventually report a hundred and forty-five thousand; given "1450.00"
     * it will pick a currency symbol out of the air, and for an owner reading
     * their own numbers the symbol is not a detail.
     *
     * @param  array<string, mixed>|null  $money
     */
    private function money(?array $money): ?string
    {
        if ($money === null || ! isset($money['formatted'], $money['currency'])) {
            return null;
        }

        return sprintf('%s %s', $money['formatted'], $money['currency']);
    }

    /**
     * Who is coming, who cancelled, and what is in the diary.
     *
     * @return array<string, mixed>
     */
    private function bookings(Property $property): array
    {
        $today = CarbonImmutable::today();

        $upcoming = Reservation::query()
            ->where('property_id', $property->getKey())
            ->whereIn('status', ReservationStatus::revenueValues())
            ->whereDate('check_in_date', '>=', $today->toDateString())
            ->orderBy('check_in_date')
            ->limit(5)
            ->get(['confirmation_code', 'check_in_date', 'check_out_date', 'nights', 'adults', 'source', 'status']);

        $cancelled = Reservation::query()
            ->where('property_id', $property->getKey())
            ->where('status', ReservationStatus::Cancelled->value)
            ->whereDate('check_in_date', '>=', $today->subDays(89)->toDateString())
            ->count();

        return [
            'next_arrivals' => $upcoming->map(fn (Reservation $reservation): array => array_filter([
                'confirmation_code' => $reservation->confirmation_code,
                'arrives' => $reservation->check_in_date?->toDateString(),
                'leaves' => $reservation->check_out_date?->toDateString(),
                'nights' => $reservation->nights,
                'guests' => $reservation->adults,
                'came_from' => $reservation->source,
            ], static fn (mixed $value): bool => $value !== null))->values()->all(),
            'cancelled_in_last_90_days' => $cancelled,
            // The question behind "why is it empty" is usually this one.
            'currently_occupied' => Reservation::query()
                ->where('property_id', $property->getKey())
                ->whereIn('status', ReservationStatus::revenueValues())
                ->whereDate('check_in_date', '<=', $today->toDateString())
                ->whereDate('check_out_date', '>', $today->toDateString())
                ->exists(),
        ];
    }

    /**
     * Who to call about this property.
     *
     * Resolved through the helper records rather than read off them, so a vendor
     * whose number changed yesterday is right here today.
     *
     * @return list<array<string, mixed>>
     */
    private function helpers(Property $property): array
    {
        return $property->helpers()
            ->with(['vendor', 'user'])
            ->orderByDesc('is_primary')
            ->orderBy('position')
            ->get()
            ->map(fn (PropertyHelper $helper): array => array_filter([
                'role' => $helper->roleLabel(),
                'name' => $helper->displayName(),
                'phone' => $helper->contactNumber(),
                'email' => $helper->contactEmail(),
                'call_first' => $helper->is_primary ?: null,
                'notes' => $helper->notes,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function operations(Property $property): array
    {
        return [
            'open_tasks' => Task::query()
                ->where('property_id', $property->getKey())
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->count(),
        ];
    }

    /**
     * Whether the person asking may be told this.
     *
     * No asker means no permissions, not all of them. The caller that passes
     * null is a job, a command or a test, and defaulting the other way would
     * make "nobody is signed in" the most privileged state in the system.
     */
    private function may(?User $asker, string $permission): bool
    {
        return $asker !== null && $this->access->allows($asker, $permission);
    }
}
