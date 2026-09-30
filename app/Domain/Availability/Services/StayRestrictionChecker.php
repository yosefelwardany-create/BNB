<?php

declare(strict_types=1);

namespace App\Domain\Availability\Services;

use App\Domain\Availability\Models\CalendarDay;
use App\Domain\Listings\Models\Listing;
use Carbon\CarbonImmutable;

/**
 * Stay restrictions: minimum and maximum nights, arrival and departure rules,
 * advance notice and how far ahead bookings are accepted.
 *
 * Kept apart from the availability engine because these rules are about
 * *shape* rather than inventory — a night can be entirely free and still not
 * bookable as a one-night stay on a Saturday — and because channels model them
 * as their own class of data.
 *
 * Every check returns human-readable reasons, which go straight to the guest
 * or agent rather than being flattened into "unavailable".
 */
class StayRestrictionChecker
{
    /**
     * @param  bool  $forSale  False when an existing stay is being written down
     *                         rather than sold; see {@see checkBookingWindow()}.
     * @return list<string> reasons the stay is not permitted; empty means it is
     */
    public function check(
        Listing $listing,
        CarbonImmutable $checkIn,
        CarbonImmutable $checkOut,
        bool $forSale = true,
    ): array {
        $reasons = [];
        $nights = (int) $checkIn->startOfDay()->diffInDays($checkOut->startOfDay());

        if ($nights < 1) {
            return ['The stay must include at least one night.'];
        }

        $overrides = $this->overridesFor($listing, $checkIn, $checkOut);

        // --- Minimum stay -----------------------------------------------
        // The arrival date's minimum is what applies: that is the convention
        // every channel uses, and it is what a guest sees on a search result.
        $arrivalKey = $checkIn->toDateString();
        $minimum = $overrides[$arrivalKey]->minimum_nights ?? $listing->minimumNights();

        if ($minimum !== null && $nights < $minimum) {
            $reasons[] = sprintf(
                'Arrivals on %s require a minimum stay of %d night%s; %d requested.',
                $checkIn->format('j M Y'),
                $minimum,
                $minimum === 1 ? '' : 's',
                $nights,
            );
        }

        $maximum = $overrides[$arrivalKey]->maximum_nights ?? $listing->maximumNights();

        if ($maximum !== null && $nights > $maximum) {
            $reasons[] = sprintf(
                'The maximum stay is %d night%s; %d requested.',
                $maximum,
                $maximum === 1 ? '' : 's',
                $nights,
            );
        }

        // --- Arrival and departure restrictions -------------------------
        if (($overrides[$arrivalKey]->closed_to_arrival ?? false) === true) {
            $reasons[] = sprintf('Arrivals are not accepted on %s.', $checkIn->format('j M Y'));
        }

        $departureKey = $checkOut->toDateString();

        if (($overrides[$departureKey]->closed_to_departure ?? false) === true) {
            $reasons[] = sprintf('Departures are not accepted on %s.', $checkOut->format('j M Y'));
        }

        // A manually blocked night inside the stay stops it, even if the
        // inventory itself is free.
        foreach ($this->nightsIn($checkIn, $checkOut) as $date) {
            if (($overrides[$date]->is_blocked ?? false) === true) {
                $reasons[] = sprintf('%s is not available.', CarbonImmutable::parse($date)->format('j M Y'));
                break;
            }
        }

        // --- Booking window ---------------------------------------------
        $reasons = array_merge($reasons, $this->checkBookingWindow($listing, $checkIn, $forSale));

        return array_values(array_unique($reasons));
    }

    /**
     * Advance notice and how far ahead the listing accepts bookings.
     *
     * Both are evaluated in the *property's* timezone: "at least 24 hours'
     * notice" means 24 hours before the guest's arrival where the property is,
     * not where the server is.
     *
     * @return list<string>
     */
    private function checkBookingWindow(
        Listing $listing,
        CarbonImmutable $checkIn,
        bool $forSale = true,
    ): array {
        $property = $listing->property;

        if ($property === null) {
            return [];
        }

        $reasons = [];
        $now = $property->localNow();

        /*
         * Lead time, which only means something for a stay still being sold.
         *
         * Both of these — "not in the past" and "at least N hours' notice" —
         * answer the question *may this be sold now*. An operator writing down a
         * booking that already started, or finished last month, is answering a
         * different question: it happened, and the platform has to be able to
         * hold it. Refusing that is how a system tells somebody it cannot be
         * used for their actual business, and every operator arriving from
         * another tool has history and guests already in the building.
         *
         * Inventory is checked regardless and is where double-selling is caught.
         * These two rules protect nothing that check does.
         */
        if ($forSale && $checkIn->startOfDay() < $now->startOfDay()) {
            $reasons[] = 'That arrival date has passed. Tick "this stay has already '
                .'started" to record a booking that is under way or finished.';

            return $reasons;
        }

        if (! $forSale && $checkIn->startOfDay() < $now->startOfDay()) {
            // Notice is lead time before a future arrival; there is none to
            // measure once the guest is already in.
            return $reasons;
        }

        $notice = $listing->advance_notice_hours;

        if ($notice !== null && $notice > 0) {
            $arrival = $property->localDateTime($checkIn->toDateString(), $this->checkInTime($listing, $property));

            if ($arrival->lt($now->addHours((int) $notice))) {
                $reasons[] = sprintf(
                    'This listing requires at least %d hour%s notice before arrival.',
                    $notice,
                    $notice === 1 ? '' : 's',
                );
            }
        }

        $window = $listing->booking_window_days;

        if ($window !== null && $window > 0) {
            $furthest = $now->startOfDay()->addDays((int) $window);

            if ($checkIn->startOfDay() > $furthest) {
                $reasons[] = sprintf(
                    'Bookings are accepted up to %d days ahead; %s is beyond that.',
                    $window,
                    $checkIn->format('j M Y'),
                );
            }
        }

        return $reasons;
    }

    /**
     * Calendar overrides in range, keyed by date.
     *
     * The departure date is included because departure restrictions apply to
     * it even though no night is sold.
     *
     * @return array<string, CalendarDay>
     */
    private function overridesFor(Listing $listing, CarbonImmutable $checkIn, CarbonImmutable $checkOut): array
    {
        return CalendarDay::query()
            ->where('listing_id', $listing->getKey())
            ->where('calendar_date', '>=', $checkIn->toDateString())
            ->where('calendar_date', '<=', $checkOut->toDateString())
            ->get()
            ->keyBy(fn (CalendarDay $day): string => $day->calendar_date->toDateString())
            ->all();
    }

    private function checkInTime(Listing $listing, $property): string
    {
        $time = $listing->check_in_time ?? $property->check_in_time;

        if ($time === null) {
            return '15:00';
        }

        return $time instanceof \DateTimeInterface ? $time->format('H:i:s') : (string) $time;
    }

    /**
     * @return list<string>
     */
    private function nightsIn(CarbonImmutable $checkIn, CarbonImmutable $checkOut): array
    {
        $dates = [];
        $cursor = $checkIn->startOfDay();

        while ($cursor < $checkOut->startOfDay()) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $dates;
    }
}
