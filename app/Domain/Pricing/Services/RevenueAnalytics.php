<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Properties\Enums\PropertyStatus;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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

        $propertyCount = $this->propertyCount($propertyIds);
        $days = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $row = $rows->get($date);

            $nights = (int) ($row->nights ?? 0);
            $revenue = Money::of((int) ($row->revenue ?? 0), $currency);

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
        $currency = $this->tenancy->organizationOrFail()->base_currency;
        $nightsInPeriod = (int) $from->diffInDays($to) + 1;

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

        return $rows->map(function (object $row) use ($currency, $nightsInPeriod): array {
            $nights = (int) $row->nights;
            $revenue = (int) $row->revenue;

            return [
                'property_id' => $row->property_id,
                'property_name' => $row->name,
                'nights_sold' => $nights,
                'nights_available' => $nightsInPeriod,
                'occupancy_rate' => $nightsInPeriod > 0
                    ? round($nights / $nightsInPeriod * 100, 2)
                    : 0.0,
                'reservations' => (int) $row->reservations,
                'accommodation_revenue' => Money::of($revenue, $currency)->jsonSerialize(),
                'adr' => $nights > 0
                    ? Money::of(intdiv($revenue, $nights), $currency)->jsonSerialize()
                    : Money::zero($currency)->jsonSerialize(),
                'revpar' => $nightsInPeriod > 0
                    ? Money::of(intdiv($revenue, $nightsInPeriod), $currency)->jsonSerialize()
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
     * Derived from the property count rather than from the calendar, so an
     * operator cannot improve occupancy by blocking rooms.
     *
     * @param  list<string>  $propertyIds
     */
    private function availableNights(
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $propertyIds,
    ): int {
        $days = (int) $from->diffInDays($to) + 1;

        return $this->propertyCount($propertyIds) * $days;
    }

    /**
     * @param  list<string>  $propertyIds
     */
    private function propertyCount(array $propertyIds): int
    {
        if ($propertyIds !== []) {
            return count($propertyIds);
        }

        // Active properties only. A draft property has never been for sale and
        // an archived one no longer is; counting either as available inventory
        // would understate occupancy for reasons that have nothing to do with
        // how the business performed.
        return Property::query()->where('status', PropertyStatus::Active->value)->count();
    }
}
