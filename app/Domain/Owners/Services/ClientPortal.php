<?php

declare(strict_types=1);

namespace App\Domain\Owners\Services;

use App\Domain\Availability\DataObjects\DayAvailability;
use App\Domain\Availability\Models\CalendarBlock;
use App\Domain\Availability\Services\AvailabilityEngine;
use App\Domain\Listings\Models\Listing;
use App\Domain\Owners\Models\Owner;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The read-only views a client gets of their own properties and calendars.
 *
 * Everything starts from the properties the client holds a share in today.
 * Two things are deliberately absent, as in the rest of the portal: guest
 * identities (a reservation is a bar with dates, a count and a source), and
 * anything operational — Hostex configuration, agent settings, access codes,
 * internal notes, pricing rules. Those are the management company's.
 */
class ClientPortal
{
    /** A calendar request is capped so one call cannot pull years of data. */
    private const MAX_DAYS = 400;

    public function __construct(private readonly AvailabilityEngine $availability) {}

    /**
     * Property ids the client holds a share in today.
     *
     * @return list<string>
     */
    public function propertyIds(Owner $owner): array
    {
        return $owner->ownerships()
            ->inForceOn(CarbonImmutable::today())
            ->pluck('property_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Property>
     */
    public function properties(Owner $owner): Collection
    {
        $ids = $this->propertyIds($owner);

        if ($ids === []) {
            return collect();
        }

        return Property::query()
            ->with(['photos', 'listings'])
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get();
    }

    public function property(Owner $owner, string $propertyId): ?Property
    {
        if (! in_array($propertyId, $this->propertyIds($owner), true)) {
            return null;
        }

        return Property::query()->with(['photos', 'listings', 'amenities'])->find($propertyId);
    }

    /**
     * The client's calendar: one row per listing with a day-by-day state, the
     * occupying stays as anonymous bars, and the blocks.
     *
     * The same availability engine the booking path uses, so what the client
     * sees as sold is what the system would refuse to sell again. Archived
     * listings stay visible while a real stay occupies the window, exactly as
     * on the management calendar.
     *
     * @return array<string, mixed>
     */
    public function calendar(Owner $owner, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $to = $to > $from->addDays(self::MAX_DAYS) ? $from->addDays(self::MAX_DAYS) : $to;
        $ids = $this->propertyIds($owner);

        if ($ids === []) {
            return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'listings' => [], 'reservations' => [], 'blocks' => []];
        }

        $listings = Listing::query()
            ->with(['property', 'unitType', 'unit'])
            ->whereIn('property_id', $ids)
            ->where(fn ($q) => $q->where('status', '!=', 'archived')
                ->orWhereIn('id', Reservation::query()->blocking()
                    ->overlapping($from->toDateString(), $to->toDateString())->select('listing_id')))
            ->orderBy('name')
            ->limit(200)
            ->get();

        $rows = [];

        foreach ($listings as $listing) {
            $days = $this->availability->calendar($listing, $from, $to);

            $rows[] = [
                'listing_id' => $listing->getKey(),
                'listing_name' => $listing->name,
                'listing_status' => $listing->status->value,
                'property_id' => $listing->property_id,
                // The public name: the internal one is staff shorthand.
                'property_name' => $listing->property?->name,
                'timezone' => $listing->property?->timezone,
                'currency' => $listing->currency,
                'days' => array_map(fn (DayAvailability $d): array => $d->toArray(), $days),
            ];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'listings' => $rows,
            'reservations' => Reservation::query()
                ->whereIn('property_id', $ids)
                ->blocking()
                ->overlapping($from->toDateString(), $to->toDateString())
                ->get()
                ->map(fn (Reservation $r): array => [
                    // No reference, no guest, no balance: a stay is a span of
                    // nights the client's property was let, nothing more.
                    'id' => $r->getKey(),
                    'property_id' => $r->property_id,
                    'listing_id' => $r->listing_id,
                    'unit_id' => $r->unit_id,
                    'status' => $r->status->value,
                    'check_in_date' => $r->check_in_date->toDateString(),
                    'check_out_date' => $r->check_out_date->toDateString(),
                    'nights' => (int) $r->nights,
                    'guests' => $r->guestCounts()['total'],
                    'source' => $r->source,
                ])->all(),
            'blocks' => CalendarBlock::query()
                ->whereIn('property_id', $ids)
                ->overlapping($from->toDateString(), $to->toDateString())
                ->get()
                ->map(fn (CalendarBlock $b): array => [
                    'id' => $b->getKey(),
                    'property_id' => $b->property_id,
                    'unit_id' => $b->unit_id,
                    'kind' => $b->kind,
                    'label' => $b->label(),
                    'start_date' => $b->start_date->toDateString(),
                    'end_date' => $b->end_date->toDateString(),
                    'nights' => $b->nights(),
                ])->all(),
        ];
    }
}
