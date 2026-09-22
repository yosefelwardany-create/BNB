<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reservations;

use App\Domain\Availability\DataObjects\AvailabilityRequest;
use App\Domain\Availability\DataObjects\DayAvailability;
use App\Domain\Availability\Models\CalendarBlock;
use App\Domain\Availability\Models\CalendarDay;
use App\Domain\Availability\Services\AvailabilityEngine;
use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\Unit;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Services\AccessControl;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The multi-calendar and the availability it renders.
 *
 * Everything here reads from the availability engine rather than computing
 * occupancy itself, so the calendar can never disagree with what the booking
 * engine will accept.
 */
class CalendarController extends Controller
{
    /** A calendar request is capped so one call cannot pull years of data. */
    private const MAX_DAYS = 400;

    public function __construct(
        private readonly AvailabilityEngine $availability,
        private readonly AccessControl $access,
    ) {}

    /**
     * The multi-calendar: one row per listing, with a day-by-day state.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('calendar.view');

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'property_ids' => ['sometimes', 'array'],
            'property_ids.*' => ['string'],
            'listing_ids' => ['sometimes', 'array'],
            'listing_ids.*' => ['string'],
            'portfolio_id' => ['sometimes', 'nullable', 'string'],
        ]);

        [$from, $to] = $this->window($data['from'], $data['to']);

        $restricted = $this->access->restrictedPropertyIds($this->currentUser());

        $listings = Listing::query()
            // The whole property is loaded rather than a column subset: the
            // availability engine reads inventory mode, rental kind and
            // capacity from it, and a partial select would fail on the first
            // field somebody forgot to list.
            ->with(['property', 'unitType', 'unit'])
            ->whereIn('status', ['published', 'paused'])
            ->when($restricted !== null, fn ($q) => $q->whereIn('property_id', $restricted))
            ->when(! empty($data['property_ids']), fn ($q) => $q->whereIn('property_id', $data['property_ids']))
            ->when(! empty($data['listing_ids']), fn ($q) => $q->whereIn('id', $data['listing_ids']))
            ->when(! empty($data['portfolio_id']), fn ($q) => $q->whereHas(
                'property',
                fn ($p) => $p->where('portfolio_id', $data['portfolio_id']),
            ))
            ->orderBy('name')
            ->limit(200)
            ->get();

        $rows = [];

        foreach ($listings as $listing) {
            $days = $this->availability->calendar($listing, $from, $to);

            $rows[] = [
                'listing_id' => $listing->getKey(),
                'listing_name' => $listing->name,
                'property_id' => $listing->property_id,
                'property_name' => $listing->property?->displayName(),
                'timezone' => $listing->property?->timezone,
                'currency' => $listing->currency,
                'inventory_scope' => $listing->inventoryScope(),
                'days' => array_map(fn (DayAvailability $d): array => $d->toArray(), $days),
                'summary' => $this->summarise($days),
            ];
        }

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'listings' => $rows,
            // The bookings and blocks behind the cells, so the calendar can
            // draw bars without a second round trip per listing.
            'reservations' => $this->reservationsIn($listings->pluck('property_id')->unique()->all(), $from, $to),
            'blocks' => $this->blocksIn($listings->pluck('property_id')->unique()->all(), $from, $to),
        ]);
    }

    /**
     * Whether a specific stay can be sold, with the reasons when it cannot.
     */
    public function check(Request $request): JsonResponse
    {
        $this->authorize('calendar.view');

        $data = $request->validate([
            'listing_id' => ['required', 'string', 'exists:listings,id'],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'guests' => ['sometimes', 'integer', 'min:1'],
            'unit_id' => ['sometimes', 'nullable', 'string', 'exists:units,id'],
            'ignore_reservation_id' => ['sometimes', 'nullable', 'string'],
        ]);

        $listing = Listing::query()->with(['property', 'unit', 'unitType'])->findOrFail($data['listing_id']);

        $this->authorize('view', $listing->property);

        $result = $this->availability->check(new AvailabilityRequest(
            property: $listing->property,
            checkIn: CarbonImmutable::parse($data['check_in']),
            checkOut: CarbonImmutable::parse($data['check_out']),
            listing: $listing,
            unit: isset($data['unit_id'])
                ? Unit::query()->find($data['unit_id'])
                : null,
            unitTypeId: $listing->unit_type_id,
            guests: $data['guests'] ?? null,
            ignoreReservationId: $data['ignore_reservation_id'] ?? null,
        ));

        return response()->json($result->toArray());
    }

    /**
     * Set per-date rates and restrictions across a range.
     *
     * A single call covers the common operator action — "close arrivals every
     * Saturday in July and set a five-night minimum" — without the client
     * issuing one request per day.
     */
    public function updateDays(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('update', $listing);
        $this->authorize('calendar.update');

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            // 0 = Sunday. Omitted means every day in the range.
            'days_of_week' => ['sometimes', 'array'],
            'days_of_week.*' => ['integer', 'between:0,6'],

            'rate_override' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'minimum_nights' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'maximum_nights' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'closed_to_arrival' => ['sometimes', 'boolean'],
            'closed_to_departure' => ['sometimes', 'boolean'],
            'is_blocked' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        [$from, $to] = $this->window($data['from'], $data['to'], inclusive: true);

        $attributes = array_intersect_key($data, array_flip([
            'rate_override', 'minimum_nights', 'maximum_nights',
            'closed_to_arrival', 'closed_to_departure', 'is_blocked', 'note',
        ]));

        if ($attributes === []) {
            return response()->json(['message' => 'No changes were supplied.'], 422);
        }

        $daysOfWeek = $data['days_of_week'] ?? null;
        $updated = 0;

        DB::transaction(function () use ($listing, $from, $to, $attributes, $daysOfWeek, &$updated): void {
            $cursor = $from;

            while ($cursor <= $to) {
                if ($daysOfWeek === null || in_array((int) $cursor->dayOfWeek, $daysOfWeek, true)) {
                    $day = CalendarDay::query()->firstOrNew([
                        'listing_id' => $listing->getKey(),
                        'calendar_date' => $cursor->toDateString(),
                    ]);

                    $day->fill($attributes + [
                        'organization_id' => $listing->organization_id,
                        'updated_by_id' => auth()->id(),
                    ]);

                    // An override reset back to the defaults leaves no row
                    // behind, so the calendar stays free of inert records.
                    if ($day->isEmpty()) {
                        $day->exists ? $day->delete() : null;
                    } else {
                        $day->save();
                    }

                    $updated++;
                }

                $cursor = $cursor->addDay();
            }
        });

        return response()->json([
            'message' => sprintf('%d date(s) updated.', $updated),
            'updated' => $updated,
        ]);
    }

    /**
     * Take dates off the market for something other than a booking.
     */
    public function storeBlock(Request $request): JsonResponse
    {
        $this->authorize('calendar.update');

        $data = $request->validate([
            'property_id' => ['required', 'string', 'exists:properties,id'],
            'unit_id' => ['sometimes', 'nullable', 'string', 'exists:units,id'],
            'kind' => ['required', Rule::in([
                CalendarBlock::KIND_OWNER_STAY, CalendarBlock::KIND_MAINTENANCE,
                CalendarBlock::KIND_CLEANING, CalendarBlock::KIND_MANUAL,
                CalendarBlock::KIND_RENOVATION, CalendarBlock::KIND_HOLD,
            ])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'owner_id' => ['sometimes', 'nullable', 'string', 'exists:owners,id'],
        ]);

        $property = Property::query()->findOrFail($data['property_id']);
        $this->authorize('view', $property);

        // Blocking dates that are already sold would hide a real booking from
        // the calendar, so it is refused with the specific conflict named.
        $conflicts = Reservation::query()
            ->where('property_id', $property->getKey())
            ->blocking()
            ->overlapping($data['start_date'], $data['end_date'])
            ->when(! empty($data['unit_id']), fn ($q) => $q->where('unit_id', $data['unit_id']))
            ->get(['id', 'confirmation_code', 'check_in_date', 'check_out_date']);

        if ($conflicts->isNotEmpty()) {
            return response()->json([
                'message' => 'Those dates already have reservations.',
                'conflicts' => $conflicts->map(fn (Reservation $r): array => [
                    'id' => $r->getKey(),
                    'confirmation_code' => $r->confirmation_code,
                    'check_in_date' => $r->check_in_date->toDateString(),
                    'check_out_date' => $r->check_out_date->toDateString(),
                ])->all(),
            ], 409);
        }

        $block = CalendarBlock::query()->create($data + [
            'organization_id' => $property->organization_id,
            'created_by_id' => auth()->id(),
        ]);

        return response()->json(['data' => $this->presentBlock($block)], 201);
    }

    public function destroyBlock(CalendarBlock $block): JsonResponse
    {
        $this->authorize('calendar.update');
        $this->authorize('view', $block->property);

        $block->delete();

        return response()->json(['message' => 'Block removed.']);
    }

    // ------------------------------------------------------------------

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(string $from, string $to, bool $inclusive = false): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        $limit = $start->addDays(self::MAX_DAYS);

        if ($end > $limit) {
            $end = $limit;
        }

        return [$start, $inclusive ? $end : $end];
    }

    /**
     * @param  list<DayAvailability>  $days
     * @return array<string, mixed>
     */
    private function summarise(array $days): array
    {
        $total = count($days);

        if ($total === 0) {
            return ['nights' => 0, 'sold' => 0, 'occupancy_rate' => 0.0];
        }

        $sold = 0;
        $blocked = 0;

        foreach ($days as $day) {
            $sold += $day->soldUnits;
            $blocked += $day->blockedUnits;
        }

        $capacity = $days[0]->totalUnits * $total;

        return [
            'nights' => $total,
            'sold' => $sold,
            'blocked' => $blocked,
            'occupancy_rate' => $capacity > 0 ? round(($sold / $capacity) * 100, 2) : 0.0,
        ];
    }

    /**
     * @param  list<string>  $propertyIds
     * @return list<array<string, mixed>>
     */
    private function reservationsIn(array $propertyIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($propertyIds === []) {
            return [];
        }

        return Reservation::query()
            ->with('guest:id,display_name')
            ->whereIn('property_id', $propertyIds)
            ->blocking()
            ->overlapping($from->toDateString(), $to->toDateString())
            ->get()
            ->map(fn (Reservation $r): array => [
                'id' => $r->getKey(),
                'confirmation_code' => $r->confirmation_code,
                'property_id' => $r->property_id,
                'listing_id' => $r->listing_id,
                'unit_id' => $r->unit_id,
                'status' => $r->status->value,
                'status_colour' => $r->status->colour(),
                'guest_name' => $r->guest?->display_name,
                'check_in_date' => $r->check_in_date->toDateString(),
                'check_out_date' => $r->check_out_date->toDateString(),
                'nights' => (int) $r->nights,
                'guests' => $r->totalGuests(),
                'source' => $r->source,
                'balance_due' => (int) $r->balance_due,
                'currency' => $r->currency,
            ])->all();
    }

    /**
     * @param  list<string>  $propertyIds
     * @return list<array<string, mixed>>
     */
    private function blocksIn(array $propertyIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($propertyIds === []) {
            return [];
        }

        return CalendarBlock::query()
            ->whereIn('property_id', $propertyIds)
            ->overlapping($from->toDateString(), $to->toDateString())
            ->get()
            ->map(fn (CalendarBlock $b): array => $this->presentBlock($b))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBlock(CalendarBlock $block): array
    {
        return [
            'id' => $block->getKey(),
            'property_id' => $block->property_id,
            'unit_id' => $block->unit_id,
            'kind' => $block->kind,
            'label' => $block->label(),
            'start_date' => $block->start_date->toDateString(),
            'end_date' => $block->end_date->toDateString(),
            'nights' => $block->nights(),
            'notes' => $block->notes,
            'owner_id' => $block->owner_id,
            'source' => $block->source,
        ];
    }
}
