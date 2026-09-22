<?php

declare(strict_types=1);

namespace App\Domain\Availability\Services;

use App\Domain\Availability\DataObjects\AvailabilityRequest;
use App\Domain\Availability\DataObjects\AvailabilityResult;
use App\Domain\Availability\DataObjects\DayAvailability;
use App\Domain\Availability\Exceptions\DatesUnavailableException;
use App\Domain\Availability\Models\CalendarBlock;
use App\Domain\Availability\Models\CalendarDay;
use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\Unit;
use App\Domain\Reservations\Models\Reservation;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * The single authority on whether nights can be sold.
 *
 * Nothing else in the platform is allowed to decide availability: the booking
 * engine, the admin calendar, channel pushes and the public API all ask this
 * class. That is what keeps the answer the same everywhere, and it is why the
 * expensive-looking work here (querying reservations, blocks and calendar
 * overrides together) is worth doing in one place.
 *
 * Two conventions run through everything below:
 *
 *  - Date ranges are half-open: `[check_in, check_out)`. A stay ending on the
 *    5th and one starting on the 5th do not overlap, which is exactly how a
 *    same-day turnover works.
 *  - Availability is *derived*, never stored. A cached availability table
 *    would be a second source of truth and would eventually disagree with the
 *    reservations it was meant to summarise.
 */
class AvailabilityEngine
{
    public function __construct(private readonly StayRestrictionChecker $restrictions) {}

    /**
     * Whether a specific stay can be sold, and why not when it cannot.
     */
    public function check(AvailabilityRequest $request): AvailabilityResult
    {
        $property = $request->property;
        $nights = $this->nightsIn($request->checkIn, $request->checkOut);

        if ($nights === []) {
            return AvailabilityResult::unavailable(['The stay must include at least one night.']);
        }

        $reasons = [];

        // 1. The property itself must be sellable.
        if (! $property->isBookable() && ! $request->ignorePropertyStatus) {
            $reasons[] = sprintf('The property is %s rather than active.', $property->status->value);
        }

        // 2. Inventory: is anything left for every night of the stay?
        $inventory = $this->inventoryAvailability($request);

        if ($inventory['available_units'] < $request->quantity) {
            $reasons[] = $inventory['reason'] ?? 'The dates are not available.';
        }

        // 3. Stay restrictions: minimum stay, arrival and departure rules.
        if ($request->listing !== null && ! $request->ignoreRestrictions) {
            $restrictionErrors = $this->restrictions->check(
                $request->listing,
                $request->checkIn,
                $request->checkOut,
            );

            $reasons = array_merge($reasons, $restrictionErrors);
        }

        // 4. Occupancy.
        if ($request->guests !== null) {
            $capacity = $this->capacityFor($request);

            if ($request->guests > $capacity) {
                $reasons[] = sprintf(
                    'This accommodates %d guest(s); %d were requested.',
                    $capacity,
                    $request->guests,
                );
            }
        }

        return $reasons === []
            ? AvailabilityResult::available($inventory['available_units'], $inventory['candidate_unit_ids'])
            : AvailabilityResult::unavailable(array_values(array_unique($reasons)), $inventory['blocked_dates']);
    }

    /**
     * A day-by-day view of a listing's calendar, which is what the
     * multi-calendar renders and what channel pushes are built from.
     *
     * @return list<DayAvailability>
     */
    public function calendar(Listing $listing, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $property = $listing->property;
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $unitIds = $this->unitIdsForListing($listing);
        $capacity = $this->listingInventoryCount($listing);

        $occupancy = $this->occupancyByDate($property, $unitIds, $fromDate, $toDate, $listing);

        $overrides = CalendarDay::query()
            ->where('listing_id', $listing->getKey())
            ->between($fromDate, $toDate)
            ->get()
            ->keyBy(fn (CalendarDay $day): string => $day->calendar_date->toDateString());

        $days = [];

        foreach (CarbonPeriod::create($from, '1 day', $to->subDay()) as $date) {
            $key = $date->format('Y-m-d');
            $override = $overrides->get($key);

            $sold = $occupancy['reservations'][$key] ?? 0;
            $blocked = $occupancy['blocks'][$key] ?? 0;
            $remaining = max(0, $capacity - $sold - $blocked);

            $days[] = new DayAvailability(
                date: $key,
                isAvailable: $remaining > 0 && ! ($override?->is_blocked ?? false),
                totalUnits: $capacity,
                soldUnits: $sold,
                blockedUnits: $blocked,
                remainingUnits: $override?->is_blocked ? 0 : $remaining,
                isManuallyBlocked: (bool) ($override?->is_blocked ?? false),
                minimumNights: $override?->minimum_nights ?? $listing->minimumNights(),
                maximumNights: $override?->maximum_nights ?? $listing->maximumNights(),
                closedToArrival: (bool) ($override?->closed_to_arrival ?? false),
                closedToDeparture: (bool) ($override?->closed_to_departure ?? false),
                rateOverride: $override?->rate_override,
                note: $override?->note,
                reservationIds: $occupancy['reservation_ids'][$key] ?? [],
                blockIds: $occupancy['block_ids'][$key] ?? [],
            );
        }

        return $days;
    }

    /**
     * Reserve inventory for a stay under a lock.
     *
     * This is the only safe way to commit a booking. It takes a row lock over
     * the property's existing reservations for the requested dates, re-checks
     * availability *inside* the lock, and only then runs the caller's
     * callback. Two simultaneous bookings for the last unit therefore
     * serialise, and the loser is told the dates are gone rather than silently
     * creating an overlap.
     *
     * Must be called inside a transaction; the lock is released when it
     * commits.
     *
     * @template TReturn
     *
     * @param  \Closure(AvailabilityResult): TReturn  $callback
     * @return TReturn
     *
     * @throws DatesUnavailableException
     */
    public function reserve(AvailabilityRequest $request, \Closure $callback): mixed
    {
        return DB::transaction(function () use ($request, $callback) {
            // Lock the rows that decide the answer. Locking reservations for
            // the property across the requested window is coarse but correct:
            // it is the set another concurrent booking would also have to
            // touch, so contention is limited to genuinely competing stays.
            Reservation::query()
                ->where('property_id', $request->property->getKey())
                ->blocking()
                ->overlapping($request->checkIn->toDateString(), $request->checkOut->toDateString())
                ->lockForUpdate()
                ->pluck('id');

            $result = $this->check($request);

            if (! $result->isAvailable) {
                throw new DatesUnavailableException(
                    $result->reasons,
                    $result->blockedDates,
                );
            }

            return $callback($result);
        });
    }

    /**
     * Which specific units are free for the whole stay.
     *
     * @return list<string>
     */
    public function availableUnitIds(
        Property $property,
        CarbonImmutable $checkIn,
        CarbonImmutable $checkOut,
        ?string $unitTypeId = null,
        ?string $ignoreReservationId = null,
    ): array {
        $units = Unit::query()
            ->where('property_id', $property->getKey())
            ->sellable()
            ->when($unitTypeId !== null, fn ($q) => $q->where('unit_type_id', $unitTypeId))
            ->get();

        if ($units->isEmpty()) {
            return [];
        }

        $occupied = $this->occupiedUnitIds(
            $property,
            $checkIn->toDateString(),
            $checkOut->toDateString(),
            $ignoreReservationId,
        );

        $available = [];

        foreach ($units as $unit) {
            // A unit is unavailable if it, or anything it shares space with
            // (a parent flat or a child room), is occupied.
            $group = $unit->occupancyGroupIds();

            if (array_intersect($group, $occupied) === []) {
                $available[] = $unit->getKey();
            }
        }

        return $available;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * How much inventory is free for the requested stay.
     *
     * @return array{available_units: int, candidate_unit_ids: list<string>, blocked_dates: list<string>, reason: ?string}
     */
    private function inventoryAvailability(AvailabilityRequest $request): array
    {
        $property = $request->property;
        $from = $request->checkIn->toDateString();
        $to = $request->checkOut->toDateString();

        // --- A specific unit was asked for -----------------------------
        if ($request->unit !== null) {
            if (! $request->unit->isSellable()) {
                return [
                    'available_units' => 0,
                    'candidate_unit_ids' => [],
                    'blocked_dates' => [],
                    'reason' => sprintf('Unit %s is %s.', $request->unit->name, $request->unit->status->value),
                ];
            }

            $occupied = $this->occupiedUnitIds($property, $from, $to, $request->ignoreReservationId);
            $group = $request->unit->occupancyGroupIds();

            if (array_intersect($group, $occupied) !== []) {
                return [
                    'available_units' => 0,
                    'candidate_unit_ids' => [],
                    'blocked_dates' => $this->blockedDates($property, $request, [$request->unit->getKey()]),
                    'reason' => sprintf('Unit %s is already occupied on those dates.', $request->unit->name),
                ];
            }

            return [
                'available_units' => 1,
                'candidate_unit_ids' => [$request->unit->getKey()],
                'blocked_dates' => [],
                'reason' => null,
            ];
        }

        // --- Unit-level inventory (multi-unit property or a unit type) ---
        if ($property->tracksAvailabilityPerUnit() || $request->unitTypeId !== null) {
            $candidates = $this->availableUnitIds(
                $property,
                $request->checkIn,
                $request->checkOut,
                $request->unitTypeId,
                $request->ignoreReservationId,
            );

            return [
                'available_units' => count($candidates),
                'candidate_unit_ids' => $candidates,
                'blocked_dates' => $candidates === []
                    ? $this->blockedDates($property, $request, null)
                    : [],
                'reason' => $candidates === [] ? 'No units are free for the whole stay.' : null,
            ];
        }

        // --- Whole-property inventory: one sellable thing ---------------
        $conflicts = $this->propertyLevelConflicts($property, $from, $to, $request->ignoreReservationId);

        if ($conflicts > 0) {
            return [
                'available_units' => 0,
                'candidate_unit_ids' => [],
                'blocked_dates' => $this->blockedDates($property, $request, null),
                'reason' => 'The property is already booked or blocked on those dates.',
            ];
        }

        return [
            'available_units' => 1,
            'candidate_unit_ids' => [],
            'blocked_dates' => [],
            'reason' => null,
        ];
    }

    /**
     * Unit ids occupied by a reservation or a block during the range.
     *
     * @return list<string>
     */
    private function occupiedUnitIds(
        Property $property,
        string $from,
        string $to,
        ?string $ignoreReservationId = null,
    ): array {
        $fromReservations = Reservation::query()
            ->where('property_id', $property->getKey())
            ->blocking()
            ->overlapping($from, $to)
            ->when($ignoreReservationId !== null, fn ($q) => $q->whereKeyNot($ignoreReservationId))
            ->whereNotNull('unit_id')
            ->pluck('unit_id')
            ->all();

        $fromBlocks = CalendarBlock::query()
            ->where('property_id', $property->getKey())
            ->overlapping($from, $to)
            ->whereNotNull('unit_id')
            ->pluck('unit_id')
            ->all();

        return array_values(array_unique(array_merge($fromReservations, $fromBlocks)));
    }

    /**
     * Conflicts for a property sold as a single whole.
     *
     * Reservations and blocks with no unit apply to the whole property; on a
     * single-unit property, a unit-scoped conflict is still a conflict.
     */
    private function propertyLevelConflicts(
        Property $property,
        string $from,
        string $to,
        ?string $ignoreReservationId = null,
    ): int {
        $reservations = Reservation::query()
            ->where('property_id', $property->getKey())
            ->blocking()
            ->overlapping($from, $to)
            ->when($ignoreReservationId !== null, fn ($q) => $q->whereKeyNot($ignoreReservationId))
            ->count();

        $blocks = CalendarBlock::query()
            ->where('property_id', $property->getKey())
            ->overlapping($from, $to)
            ->count();

        return $reservations + $blocks;
    }

    /**
     * Which individual dates in the requested range are unavailable — the
     * detail a booking form needs to explain a refusal.
     *
     * @param  list<string>|null  $unitIds
     * @return list<string>
     */
    private function blockedDates(Property $property, AvailabilityRequest $request, ?array $unitIds): array
    {
        $from = $request->checkIn->toDateString();
        $to = $request->checkOut->toDateString();

        $occupancy = $this->occupancyByDate($property, $unitIds, $from, $to, $request->listing);

        $capacity = $request->listing !== null
            ? $this->listingInventoryCount($request->listing)
            : max(1, count($unitIds ?? []) ?: 1);

        $blocked = [];

        foreach ($this->nightsIn($request->checkIn, $request->checkOut) as $date) {
            $used = ($occupancy['reservations'][$date] ?? 0) + ($occupancy['blocks'][$date] ?? 0);

            if ($capacity - $used < $request->quantity) {
                $blocked[] = $date;
            }
        }

        return $blocked;
    }

    /**
     * Per-date counts of reservations and blocks over a range.
     *
     * Loaded in two queries and expanded in PHP rather than with a per-day
     * query, because a two-year calendar for a building of forty units would
     * otherwise be tens of thousands of round trips.
     *
     * @param  list<string>|null  $unitIds
     * @return array{reservations: array<string, int>, blocks: array<string, int>, reservation_ids: array<string, list<string>>, block_ids: array<string, list<string>>}
     */
    private function occupancyByDate(
        Property $property,
        ?array $unitIds,
        string $from,
        string $to,
        ?Listing $listing = null,
    ): array {
        $reservations = Reservation::query()
            ->select(['id', 'unit_id', 'check_in_date', 'check_out_date', 'listing_id'])
            ->where('property_id', $property->getKey())
            ->blocking()
            ->overlapping($from, $to)
            ->when($unitIds !== null, function ($q) use ($unitIds): void {
                // Reservations with no unit occupy the whole property, so they
                // count against every unit-scoped view as well.
                $q->where(function ($inner) use ($unitIds): void {
                    $inner->whereIn('unit_id', $unitIds)->orWhereNull('unit_id');
                });
            })
            ->get();

        $blocks = CalendarBlock::query()
            ->select(['id', 'unit_id', 'start_date', 'end_date'])
            ->where('property_id', $property->getKey())
            ->overlapping($from, $to)
            ->when($unitIds !== null, function ($q) use ($unitIds): void {
                $q->where(function ($inner) use ($unitIds): void {
                    $inner->whereIn('unit_id', $unitIds)->orWhereNull('unit_id');
                });
            })
            ->get();

        $reservationCounts = [];
        $blockCounts = [];
        $reservationIds = [];
        $blockIds = [];

        foreach ($reservations as $reservation) {
            foreach ($this->expand($reservation->check_in_date, $reservation->check_out_date, $from, $to) as $date) {
                $reservationCounts[$date] = ($reservationCounts[$date] ?? 0) + 1;
                $reservationIds[$date][] = $reservation->getKey();
            }
        }

        foreach ($blocks as $block) {
            foreach ($this->expand($block->start_date, $block->end_date, $from, $to) as $date) {
                $blockCounts[$date] = ($blockCounts[$date] ?? 0) + 1;
                $blockIds[$date][] = $block->getKey();
            }
        }

        return [
            'reservations' => $reservationCounts,
            'blocks' => $blockCounts,
            'reservation_ids' => $reservationIds,
            'block_ids' => $blockIds,
        ];
    }

    /**
     * Expand a half-open range into the dates it covers, clipped to a window.
     *
     * @return list<string>
     */
    private function expand(
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $windowFrom,
        string $windowTo,
    ): array {
        $from = max($start->toDateString(), $windowFrom);
        $to = min($end->toDateString(), $windowTo);

        if ($from >= $to) {
            return [];
        }

        $dates = [];
        $cursor = CarbonImmutable::parse($from);
        $limit = CarbonImmutable::parse($to);

        while ($cursor < $limit) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    /**
     * How many sellable things a listing represents.
     */
    private function listingInventoryCount(Listing $listing): int
    {
        return match ($listing->inventoryScope()) {
            'unit' => $listing->unit?->isSellable() ? 1 : 0,
            'unit_type' => $listing->unitType?->sellableUnitCount() ?? 0,
            default => $listing->property?->tracksAvailabilityPerUnit()
                ? Unit::query()->where('property_id', $listing->property_id)->sellable()->count()
                : 1,
        };
    }

    /**
     * @return list<string>|null
     */
    private function unitIdsForListing(Listing $listing): ?array
    {
        return match ($listing->inventoryScope()) {
            'unit' => [$listing->unit_id],
            'unit_type' => Unit::query()
                ->where('unit_type_id', $listing->unit_type_id)
                ->sellable()
                ->pluck('id')
                ->all(),
            default => null,
        };
    }

    private function capacityFor(AvailabilityRequest $request): int
    {
        if ($request->unit !== null) {
            return $request->unit->maxOccupancy();
        }

        if ($request->listing !== null) {
            return $request->listing->maxOccupancy();
        }

        return (int) $request->property->max_occupancy;
    }

    /**
     * @return list<string>
     */
    private function nightsIn(CarbonImmutable $checkIn, CarbonImmutable $checkOut): array
    {
        if ($checkOut <= $checkIn) {
            return [];
        }

        $dates = [];
        $cursor = $checkIn->startOfDay();

        while ($cursor < $checkOut->startOfDay()) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $dates;
    }
}
