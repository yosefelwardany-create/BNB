<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Properties\Enums\PropertyStatus;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The four numbers a revenue manager actually runs the business on.
 *
 * **Occupancy** is nights sold over nights available. **ADR** — average daily
 * rate — is accommodation revenue over nights *sold*. **RevPAR** is
 * accommodation revenue over nights *available*. The last two differ only in
 * the denominator, and that difference is the entire point: ADR says what the
 * nights you sold went for, RevPAR says what your whole estate earned. A
 * property can raise ADR and lose money by selling fewer nights, and only
 * RevPAR shows it.
 *
 * Three decisions here are worth stating, because they are where these figures
 * usually go wrong:
 *
 * **Accommodation only.** Cleaning fees, pet fees and tax are not room
 * revenue. Including them inflates ADR by a fixed amount per stay, which makes
 * short stays look more valuable than they are and makes the number
 * incomparable with anybody else's.
 *
 * **Revenue-bearing bookings only.** An inquiry holds no inventory and a
 * cancellation earned nothing. Counting either would overstate both occupancy
 * and revenue.
 *
 * **Available nights come from the property count, not from the calendar.** A
 * night blocked for maintenance is still a night the business owned and failed
 * to sell; excluding it would let an operator improve occupancy by blocking
 * rooms.
 *
 * **But the portfolio is counted as it was, not as it is.** Each property
 * contributes only the nights between the day it went on the market and the
 * day it came off. Multiplying today's property count by every night in the
 * period is right for an estate that did not change and wrong in both
 * directions as soon as it did: a property onboarded on the 20th would be
 * charged with nineteen nights it did not own, and one archived last week
 * would disappear from last year's denominator altogether — silently moving a
 * number somebody has already read.
 *
 * **And a night that sold is a night that was owned.** The day a property was
 * activated is when Habitat learned about it, which is not the same as when the
 * business started trading it — an operator moving in brings years of history
 * with them, and the first thing they do is record it. Counting only from
 * activation charged a flat activated on Monday with three nights of inventory
 * and thirty nights of recorded stays, and reported 1,000% occupancy. So each
 * property's window also stretches to cover every stay recorded against it.
 * That is not a fudge to keep the number under 100: a recorded stay is direct
 * evidence the property was inventory that night, and better evidence than the
 * date somebody got round to typing it in.
 */
class RevenueAnalytics
{
    public function __construct(private readonly TenantContext $tenancy) {}

    /**
     * Headline performance for a period.
     *
     * @param  list<string>  $propertyIds  Empty means the whole portfolio.
     * @return array<string, mixed>
     */
    public function summary(
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $propertyIds = [],
    ): array {
        $this->assertComparableRevenue($from, $to, $propertyIds);
        $currency = $this->tenancy->organizationOrFail()->base_currency;

        $sold = $this->soldNights($from, $to, $propertyIds);
        $available = $this->availableNights($from, $to, $propertyIds);

        $revenue = Money::of((int) $sold->revenue, $currency);
        $nightsSold = (int) $sold->nights;

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'currency' => $currency,

            'nights_sold' => $nightsSold,
            'nights_available' => $available,

            // Guarded everywhere: a portfolio with no properties, or a period
            // with no nights, must return zero rather than divide by it.
            'occupancy_rate' => $available > 0
                ? round($nightsSold / $available * 100, 2)
                : 0.0,

            'accommodation_revenue' => $revenue->jsonSerialize(),

            // Over nights *sold*.
            'adr' => $nightsSold > 0
                ? Money::of(intdiv($revenue->minorUnits, $nightsSold), $currency)->jsonSerialize()
                : Money::zero($currency)->jsonSerialize(),

            // Over nights *available*. The one that tells you whether the
            // estate is earning.
            'revpar' => $available > 0
                ? Money::of(intdiv($revenue->minorUnits, $available), $currency)->jsonSerialize()
                : Money::zero($currency)->jsonSerialize(),

            'reservations' => (int) $sold->reservations,
            'average_stay_nights' => (int) $sold->reservations > 0
                ? round($nightsSold / (int) $sold->reservations, 2)
                : 0.0,
        ];
    }

    /**
     * The same figures, one row per day.
     *
     * What a pace chart is drawn from. Each day's available count is the
     * property count for that day, so a property acquired mid-period does not
     * retrospectively depress the days before it existed.
     *
     * @param  list<string>  $propertyIds
     * @return list<array<string, mixed>>
     */
    public function daily(
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $propertyIds = [],
    ): array {
        $this->assertComparableRevenue($from, $to, $propertyIds);
        $currency = $this->tenancy->organizationOrFail()->base_currency;

        $rows = DB::table('reservation_nights as rn')
            ->join('reservations as r', 'r.id', '=', 'rn.reservation_id')
            ->where('rn.organization_id', $this->tenancy->id())
            ->whereBetween('rn.stay_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('r.status', ReservationStatus::revenueValues())
            ->when($propertyIds !== [], fn ($q) => $q->whereIn('r.property_id', $propertyIds))
            ->groupBy('rn.stay_date')
            ->selectRaw('rn.stay_date, count(*) as nights, sum(rn.rate_amount) as revenue')
            ->get()
            ->keyBy(fn (object $row): string => (string) $row->stay_date);

        $windows = $this->inventoryWindows($propertyIds);
        $days = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $row = $rows->get($date);

            $nights = (int) ($row->nights ?? 0);
            $revenue = Money::of((int) ($row->revenue ?? 0), $currency);
            $propertyCount = $this->countOn($windows, $day);

            $days[] = [
                'date' => $date,
                'nights_sold' => $nights,
                'nights_available' => $propertyCount,
                'occupancy_rate' => $propertyCount > 0
                    ? round($nights / $propertyCount * 100, 2)
                    : 0.0,
                'accommodation_revenue' => $revenue->jsonSerialize(),
                'adr' => $nights > 0
                    ? Money::of(intdiv($revenue->minorUnits, $nights), $currency)->jsonSerialize()
                    : Money::zero($currency)->jsonSerialize(),
            ];
        }

        return $days;
    }

    /**
     * Where the business came from.
     *
     * Grouped by source rather than by channel account, because "how much of
     * our business is Airbnb" is a question about the channel, not about which
     * of three Airbnb logins it arrived through.
     *
     * @param  list<string>  $propertyIds
     * @return list<array<string, mixed>>
     */
    public function bySource(
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $propertyIds = [],
    ): array {
        $this->assertComparableRevenue($from, $to, $propertyIds);
        $currency = $this->tenancy->organizationOrFail()->base_currency;

        $rows = DB::table('reservation_nights as rn')
            ->join('reservations as r', 'r.id', '=', 'rn.reservation_id')
            ->where('rn.organization_id', $this->tenancy->id())
            ->whereBetween('rn.stay_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('r.status', ReservationStatus::revenueValues())
            ->when($propertyIds !== [], fn ($q) => $q->whereIn('r.property_id', $propertyIds))
            ->groupBy('r.source')
            ->selectRaw(
                'r.source, count(*) as nights, sum(rn.rate_amount) as revenue, '
                .'count(distinct r.id) as reservations'
            )
            ->orderByDesc('revenue')
            ->get();

        $totalRevenue = (int) $rows->sum('revenue');

        return $rows->map(fn (object $row): array => [
            'source' => $row->source,
            'nights_sold' => (int) $row->nights,
            'reservations' => (int) $row->reservations,
            'accommodation_revenue' => Money::of((int) $row->revenue, $currency)->jsonSerialize(),
            'adr' => (int) $row->nights > 0
                ? Money::of(intdiv((int) $row->revenue, (int) $row->nights), $currency)->jsonSerialize()
                : Money::zero($currency)->jsonSerialize(),
            'share_of_revenue' => $totalRevenue > 0
                ? round((int) $row->revenue / $totalRevenue * 100, 2)
                : 0.0,
        ])->all();
    }

    /**
     * Per-property performance, ranked.
     *
     * @param  list<string>  $propertyIds
     * @return list<array<string, mixed>>
     */
    public function byProperty(
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $propertyIds = [],
    ): array {
        $this->assertComparableRevenue($from, $to, $propertyIds);
        $currency = $this->tenancy->organizationOrFail()->base_currency;

        // Per property, because the whole point of this table is comparing
        // them: charging a flat period length to a property onboarded halfway
        // through ranks it below one that traded all year on the strength of
        // nights it never had.
        $availableByProperty = $this->availableNightsByProperty($from, $to, $propertyIds);

        $rows = DB::table('reservation_nights as rn')
            ->join('reservations as r', 'r.id', '=', 'rn.reservation_id')
            ->join('properties as p', 'p.id', '=', 'r.property_id')
            ->where('rn.organization_id', $this->tenancy->id())
            ->whereBetween('rn.stay_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('r.status', ReservationStatus::revenueValues())
            ->when($propertyIds !== [], fn ($q) => $q->whereIn('r.property_id', $propertyIds))
            ->groupBy('r.property_id', 'p.name')
            ->selectRaw(
                'r.property_id, p.name, count(*) as nights, sum(rn.rate_amount) as revenue, '
                .'count(distinct r.id) as reservations'
            )
            ->orderByDesc('revenue')
            ->get();

        return $rows->map(function (object $row) use ($currency, $availableByProperty): array {
            $nights = (int) $row->nights;
            $revenue = (int) $row->revenue;
            $available = $availableByProperty[(string) $row->property_id] ?? 0;

            return [
                'property_id' => $row->property_id,
                'property_name' => $row->name,
                'nights_sold' => $nights,
                'nights_available' => $available,
                'occupancy_rate' => $available > 0
                    ? round($nights / $available * 100, 2)
                    : 0.0,
                'reservations' => (int) $row->reservations,
                'accommodation_revenue' => Money::of($revenue, $currency)->jsonSerialize(),
                'adr' => $nights > 0
                    ? Money::of(intdiv($revenue, $nights), $currency)->jsonSerialize()
                    : Money::zero($currency)->jsonSerialize(),
                'revpar' => $available > 0
                    ? Money::of(intdiv($revenue, $available), $currency)->jsonSerialize()
                    : Money::zero($currency)->jsonSerialize(),
            ];
        })->all();
    }

    /**
     * Booking pace: what has been sold so far for a future period, and how
     * far ahead it was booked.
     *
     * Pace is the difference between noticing in January that August is soft
     * and noticing it in August. It counts by the date the booking was *made*,
     * not by the night stayed, which is what makes it a leading indicator.
     *
     * @param  list<string>  $propertyIds
     * @return array<string, mixed>
     */
    public function pace(
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $propertyIds = [],
    ): array {
        $this->assertComparableRevenue($from, $to, $propertyIds);
        $currency = $this->tenancy->organizationOrFail()->base_currency;

        $rows = DB::table('reservations as r')
            ->where('r.organization_id', $this->tenancy->id())
            ->whereIn('r.status', ReservationStatus::revenueValues())
            ->where('r.check_in_date', '>=', $from->toDateString())
            ->where('r.check_in_date', '<=', $to->toDateString())
            ->when($propertyIds !== [], fn ($q) => $q->whereIn('r.property_id', $propertyIds))
            ->selectRaw(
                'count(*) as reservations, sum(r.nights) as nights, '
                .'sum(r.accommodation_total) as revenue, '
                // Lead time in days, averaged. Null booked_at rows are
                // excluded by AVG rather than counted as zero, which would
                // drag the average toward "booked on arrival".
                .'avg(extract(epoch from (r.check_in_date::timestamp - r.booked_at)) / 86400) as avg_lead_days'
            )
            ->first();

        $available = $this->availableNights($from, $to, $propertyIds);
        $nights = (int) ($rows->nights ?? 0);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'as_at' => CarbonImmutable::today()->toDateString(),
            'currency' => $currency,
            'reservations_on_the_books' => (int) ($rows->reservations ?? 0),
            'nights_on_the_books' => $nights,
            'nights_available' => $available,
            'occupancy_on_the_books' => $available > 0
                ? round($nights / $available * 100, 2)
                : 0.0,
            'revenue_on_the_books' => Money::of((int) ($rows->revenue ?? 0), $currency)->jsonSerialize(),
            'average_lead_time_days' => $rows->avg_lead_days === null
                ? null
                : round((float) $rows->avg_lead_days, 1),
        ];
    }

    /** Legacy aggregate reports require one verified denomination; source details remain available. */
    public function assertComparableRevenue(CarbonImmutable $from, CarbonImmutable $to, array $propertyIds = [], ?string $currency = null): void
    {
        $currency ??= $this->tenancy->organizationOrFail()->base_currency;
        $incomplete = Reservation::query()
            ->whereIn('status', ReservationStatus::revenueValues())
            ->overlapping($from->toDateString(), $to->addDay()->toDateString())
            ->when($propertyIds !== [], fn ($q) => $q->whereIn('property_id', $propertyIds))
            ->where(fn ($q) => $q->where('currency', '!=', $currency)->orWhereNull('accommodation_total')
                ->orWhere('source_metadata->hostex->requires_accounting_review', true))
            ->exists();
        if ($incomplete) {
            throw ValidationException::withMessages([
                'revenue' => 'Revenue totals are unavailable for this selection: some stays have missing accommodation amounts, a different currency without a verified exchange rate, or legacy posted revenue awaiting review. View each reservation and Hostex records for source amounts.',
            ]);
        }
    }

    /**
     * Nights sold and what they earned.
     *
     * @param  list<string>  $propertyIds
     */
    private function soldNights(
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $propertyIds,
    ): object {
        return DB::table('reservation_nights as rn')
            ->join('reservations as r', 'r.id', '=', 'rn.reservation_id')
            ->where('rn.organization_id', $this->tenancy->id())
            ->whereBetween('rn.stay_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('r.status', ReservationStatus::revenueValues())
            ->when($propertyIds !== [], fn ($q) => $q->whereIn('r.property_id', $propertyIds))
            ->selectRaw(
                'count(*) as nights, coalesce(sum(rn.rate_amount), 0) as revenue, '
                .'count(distinct r.id) as reservations'
            )
            ->first() ?? (object) ['nights' => 0, 'revenue' => 0, 'reservations' => 0];
    }

    /**
     * Nights the business owned, whether or not they sold.
     *
     * Derived from the properties and the days each of them was inventory,
     * rather than from the calendar, so an operator cannot improve occupancy
     * by blocking rooms — and cannot be charged with nights they did not yet
     * own, or lose nights they did.
     *
     * @param  list<string>  $propertyIds
     */
    private function availableNights(
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $propertyIds,
    ): int {
        return array_sum($this->availableNightsByProperty($from, $to, $propertyIds));
    }

    /**
     * Nights available in the period, per property.
     *
     * Public because the owner portal computes the same occupancy against the
     * same estate. Two implementations of "nights available" would drift, and
     * an owner reading a different occupancy from their manager's is the
     * conversation nobody wants to have.
     *
     * @param  list<string>  $propertyIds
     * @return array<string, int>
     */
    public function availableNightsByProperty(
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $propertyIds,
    ): array {
        $nights = [];

        foreach ($this->inventoryWindows($propertyIds) as $id => [$start, $end]) {
            $first = $start->greaterThan($from) ? $start : $from;
            $last = $end !== null && $end->lessThan($to) ? $end : $to;

            $nights[$id] = $first->greaterThan($last)
                ? 0
                : (int) $first->diffInDays($last) + 1;
        }

        return $nights;
    }

    /**
     * How many properties were inventory on a given day.
     *
     * @param  array<string, array{0: CarbonImmutable, 1: ?CarbonImmutable}>  $windows
     */
    private function countOn(array $windows, CarbonImmutable $day): int
    {
        $count = 0;

        foreach ($windows as [$start, $end]) {
            if ($start->lessThanOrEqualTo($day) && ($end === null || $end->greaterThanOrEqualTo($day))) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * When each property was on the market, as whole days.
     *
     * A property contributes nights from the day it was activated through the
     * day before it was retired. A draft property has never been for sale and
     * contributes nothing.
     *
     * A deactivated property still counts. Taking something off the market is
     * the same act as blocking every night on it, and the rule above is that
     * an operator cannot improve occupancy by blocking. Only archiving — the
     * decision that it is no longer part of the business — closes the window.
     *
     * An archived property whose `retired_at` is null was archived before that
     * column existed. Its window is unknown, and rather than guess a date —
     * which would move historical occupancy to a number nobody can check — it
     * is taken from the stays recorded against it, and contributes nothing when
     * there are none.
     *
     * Every window is then widened to cover the stays recorded against the
     * property, for the reason in the class docblock: a night that was sold was
     * a night that was owned, whenever the record of it was typed in.
     *
     * @param  list<string>  $propertyIds
     * @return array<string, array{0: CarbonImmutable, 1: ?CarbonImmutable}>
     */
    private function inventoryWindows(array $propertyIds): array
    {
        $properties = Property::query()
            ->when($propertyIds !== [], fn ($q) => $q->whereIn('id', $propertyIds))
            ->get(['id', 'status', 'activated_at', 'retired_at']);

        $traded = $this->tradedWindows($propertyIds);

        $windows = [];

        foreach ($properties as $property) {
            $id = (string) $property->getKey();
            [$soldFrom, $soldTo] = $traded[$id] ?? [null, null];

            $archivedWithoutDate = $property->status === PropertyStatus::Archived
                && $property->retired_at === null;

            if ($archivedWithoutDate) {
                // Nothing on the record says when it came off the market, so
                // what it sold is all there is to go on.
                $start = $soldFrom;
                $end = $soldTo;
            } else {
                $start = $property->activated_at === null
                    ? null
                    : CarbonImmutable::parse($property->activated_at)->startOfDay();

                // The last night it owned is the one before it came off the
                // market: a property archived on the 15th did not own the
                // night of the 15th.
                $end = $property->retired_at === null
                    ? null
                    : CarbonImmutable::parse($property->retired_at)->startOfDay()->subDay();

                if ($soldFrom !== null && ($start === null || $soldFrom->lessThan($start))) {
                    $start = $soldFrom;
                }

                // A stay recorded after the retirement date is contradictory
                // data, and the booking is the harder fact of the two.
                if ($end !== null && $soldTo !== null && $soldTo->greaterThan($end)) {
                    $end = $soldTo;
                }
            }

            // Never activated and never sold a night: it has not been inventory.
            if ($start === null) {
                continue;
            }

            $windows[$id] = [$start, $end];
        }

        return $windows;
    }

    /**
     * The first and last night each property has a recorded stay on.
     *
     * Over every revenue-bearing stay on the record, not only the ones inside
     * the period being reported: the question this answers is "was this property
     * trading then", and a booking either side of the period answers it.
     *
     * @param  list<string>  $propertyIds
     * @return array<string, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function tradedWindows(array $propertyIds): array
    {
        $rows = DB::table('reservation_nights as rn')
            ->join('reservations as r', 'r.id', '=', 'rn.reservation_id')
            ->where('rn.organization_id', $this->tenancy->id())
            ->whereIn('r.status', ReservationStatus::revenueValues())
            ->when($propertyIds !== [], fn ($q) => $q->whereIn('r.property_id', $propertyIds))
            ->groupBy('r.property_id')
            ->get([
                'r.property_id',
                DB::raw('min(rn.stay_date) as first_night'),
                DB::raw('max(rn.stay_date) as last_night'),
            ]);

        $windows = [];

        foreach ($rows as $row) {
            if ($row->first_night === null || $row->last_night === null) {
                continue;
            }

            $windows[(string) $row->property_id] = [
                CarbonImmutable::parse($row->first_night)->startOfDay(),
                CarbonImmutable::parse($row->last_night)->startOfDay(),
            ];
        }

        return $windows;
    }
}
