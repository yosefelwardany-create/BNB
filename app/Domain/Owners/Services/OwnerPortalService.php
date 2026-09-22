<?php

declare(strict_types=1);

namespace App\Domain\Owners\Services;

use App\Domain\OwnerAccounting\Models\OwnerPayout;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\Owners\Models\Owner;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What an owner sees about their own properties.
 *
 * The governing constraint is that an owner is a user of this system with
 * exactly one legitimate subject: themselves. Everything here starts from the
 * properties they hold a share in on the dates in question, and the share is
 * applied to every figure — an owner with 50% of a villa sees half its
 * revenue, because half of it is what they are owed.
 *
 * Three things are deliberately absent, and each was a decision rather than an
 * omission:
 *
 * **Guest identities.** An owner is entitled to know their property was let,
 * for how long and for how much. Who slept in it is the guest's business and
 * the manager's, and handing it over is a data-protection problem dressed as a
 * feature.
 *
 * **Draft statements.** A draft is the manager's working figure. Showing it
 * would have owners reconciling against numbers that are still moving.
 *
 * **Anything about other owners.** Including the fact that they exist.
 */
class OwnerPortalService
{
    public function __construct(private readonly TenantContext $tenancy) {}

    /**
     * The owner's dashboard.
     *
     * @return array<string, mixed>
     */
    public function summary(Owner $owner, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $currency = $owner->payout_currency
            ?: $this->tenancy->organizationOrFail()->base_currency;

        $properties = $this->propertyShares($owner, $from, $to);
        $performance = $this->performance($owner, $properties, $from, $to, $currency);

        return [
            'owner' => [
                'id' => $owner->getKey(),
                'display_name' => $owner->display_name,
                'payout_currency' => $currency,
            ],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'properties' => $performance['properties'],
            'totals' => $performance['totals'],
            'statements' => $this->statements($owner),
            'payouts' => $this->payouts($owner),
            'balance' => $this->balance($owner, $currency),
        ];
    }

    /**
     * Upcoming stays at the owner's properties.
     *
     * Dates, nights and what they earn. No guest names, no contact details, no
     * booking notes: an owner needs to know their flat is let next weekend,
     * not who is sleeping in it.
     *
     * @return list<array<string, mixed>>
     */
    public function upcomingStays(Owner $owner, int $days = 90): array
    {
        $propertyIds = array_keys($this->propertyShares(
            $owner,
            CarbonImmutable::today(),
            CarbonImmutable::today()->addDays($days),
        ));

        if ($propertyIds === []) {
            return [];
        }

        return Reservation::query()
            ->whereIn('property_id', $propertyIds)
            ->whereIn('status', ReservationStatus::revenueValues())
            ->where('check_out_date', '>=', CarbonImmutable::today()->toDateString())
            ->where('check_in_date', '<=', CarbonImmutable::today()->addDays($days)->toDateString())
            ->orderBy('check_in_date')
            ->limit(200)
            ->get()
            ->map(fn (Reservation $reservation): array => [
                'id' => $reservation->getKey(),
                'property_id' => $reservation->property_id,
                'check_in_date' => $reservation->check_in_date?->toDateString(),
                'check_out_date' => $reservation->check_out_date?->toDateString(),
                'nights' => (int) $reservation->nights,
                'guests' => (int) $reservation->adults + (int) $reservation->children,
                'source' => $reservation->source,
                'status' => $reservation->status->value,
                // What the stay earns, not what the guest paid: the
                // difference is the manager's fee and the channel's
                // commission, which the statement itemises.
                'accommodation_total' => $reservation->accommodationTotal()->jsonSerialize(),
            ])
            ->all();
    }

    /**
     * Statements the owner has actually been sent.
     *
     * @return list<array<string, mixed>>
     */
    public function statements(Owner $owner, int $limit = 12): array
    {
        return OwnerStatement::query()
            ->where('owner_id', $owner->getKey())
            ->whereIn('status', [OwnerStatement::STATUS_SENT, OwnerStatement::STATUS_PAID])
            ->orderByDesc('period_start')
            ->limit($limit)
            ->get()
            ->map(fn (OwnerStatement $statement): array => [
                'id' => $statement->getKey(),
                'reference' => $statement->reference,
                'period_start' => $statement->period_start?->toDateString(),
                'period_end' => $statement->period_end?->toDateString(),
                'currency' => $statement->currency,
                'net_due' => $statement->netDue()->jsonSerialize(),
                'payout_amount' => $statement->payoutAmount()->jsonSerialize(),
                'closing_balance' => $statement->closingBalance()->jsonSerialize(),
                'status' => $statement->status,
                'sent_at' => $statement->sent_at?->toIso8601String(),
                'paid_at' => $statement->paid_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payouts(Owner $owner, int $limit = 12): array
    {
        return OwnerPayout::query()
            ->where('owner_id', $owner->getKey())
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (OwnerPayout $payout): array => [
                'id' => $payout->getKey(),
                'reference' => $payout->reference,
                'amount' => $payout->amount()->jsonSerialize(),
                'status' => $payout->status,
                'method' => $payout->method,
                'scheduled_for' => $payout->scheduled_for?->toDateString(),
                'paid_at' => $payout->paid_at?->toIso8601String(),
                // Where it went, already masked on the way in.
                'destination' => $payout->destination_snapshot,
            ])
            ->all();
    }

    /**
     * What the owner is owed, or owes.
     *
     * Taken from the latest issued statement's closing balance rather than
     * recomputed, so the figure on this screen is the figure on the statement
     * they were sent. Two independent calculations of the same number
     * eventually disagree, and the owner believes whichever is larger.
     *
     * @return array<string, mixed>
     */
    public function balance(Owner $owner, string $currency): array
    {
        $latest = OwnerStatement::query()
            ->where('owner_id', $owner->getKey())
            ->whereIn('status', [OwnerStatement::STATUS_SENT, OwnerStatement::STATUS_PAID])
            ->orderByDesc('period_end')
            ->first();

        $pending = OwnerPayout::query()
            ->where('owner_id', $owner->getKey())
            ->outstanding()
            ->sum('amount');

        return [
            'currency' => $currency,
            'as_at' => $latest?->period_end?->toDateString(),
            'closing_balance' => $latest !== null
                ? $latest->closingBalance()->jsonSerialize()
                : Money::zero($currency)->jsonSerialize(),
            'awaiting_payout' => Money::of((int) $pending, $currency)->jsonSerialize(),
            // An owner whose period ended owing the manager money. Carried
            // forward rather than invoiced back, so it is reported rather than
            // shown as a negative payout.
            'is_in_deficit' => $latest?->isInDeficit() ?? false,
        ];
    }

    /**
     * The owner's share of each property over the period.
     *
     * Keyed by property id. Dated, because properties change hands mid-year
     * and an owner's view of March must not depend on who owns it in November.
     *
     * @return array<string, float>
     */
    public function propertyShares(Owner $owner, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('property_ownerships')
            ->where('organization_id', $this->tenancy->id())
            ->where('owner_id', $owner->getKey())
            // Overlap, not containment: a share held for part of the period
            // still applies to that part.
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $to->toDateString()))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $from->toDateString()))
            ->get();

        $shares = [];

        foreach ($rows as $row) {
            // A property held under two consecutive shares in one period
            // appears twice; the larger is the honest headline, and the
            // statement is where the dated arithmetic is done properly.
            $shares[$row->property_id] = max(
                $shares[$row->property_id] ?? 0.0,
                (float) $row->ownership_percentage,
            );
        }

        return $shares;
    }

    /**
     * Per-property performance, scaled to the owner's share.
     *
     * @param  array<string, float>  $shares
     * @return array{properties: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function performance(
        Owner $owner,
        array $shares,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $currency,
    ): array {
        if ($shares === []) {
            return [
                'properties' => [],
                'totals' => [
                    'nights_sold' => 0,
                    'occupancy_rate' => 0.0,
                    'accommodation_revenue' => Money::zero($currency)->jsonSerialize(),
                    'adr' => Money::zero($currency)->jsonSerialize(),
                ],
            ];
        }

        $rows = DB::table('reservation_nights as rn')
            ->join('reservations as r', 'r.id', '=', 'rn.reservation_id')
            ->join('properties as p', 'p.id', '=', 'r.property_id')
            ->where('rn.organization_id', $this->tenancy->id())
            ->whereIn('r.property_id', array_keys($shares))
            ->whereBetween('rn.stay_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('r.status', ReservationStatus::revenueValues())
            ->groupBy('r.property_id', 'p.name')
            ->selectRaw('r.property_id, p.name, count(*) as nights, sum(rn.rate_amount) as revenue')
            ->get();

        $nightsInPeriod = (int) $from->diffInDays($to) + 1;
        $byProperty = $rows->keyBy('property_id');

        $properties = [];
        $totalNights = 0;
        $totalRevenue = Money::zero($currency);

        foreach ($shares as $propertyId => $share) {
            $row = $byProperty->get($propertyId);
            $nights = (int) ($row->nights ?? 0);

            // Scaled to the share. An owner with half a villa is owed half of
            // what it earns, and showing them the whole figure invites a
            // conversation that starts "but you told me".
            $revenue = Money::of(
                (int) round((int) ($row->revenue ?? 0) * $share / 100),
                $currency,
            );

            $totalNights += $nights;
            $totalRevenue = $totalRevenue->add($revenue);

            $properties[] = [
                'property_id' => $propertyId,
                'property_name' => $row->name ?? null,
                'ownership_percentage' => $share,
                'nights_sold' => $nights,
                'nights_available' => $nightsInPeriod,
                'occupancy_rate' => $nightsInPeriod > 0
                    ? round($nights / $nightsInPeriod * 100, 2)
                    : 0.0,
                'accommodation_revenue' => $revenue->jsonSerialize(),
                'adr' => $nights > 0
                    ? Money::of(intdiv($revenue->minorUnits, $nights), $currency)->jsonSerialize()
                    : Money::zero($currency)->jsonSerialize(),
            ];
        }

        $available = $nightsInPeriod * count($shares);

        return [
            'properties' => $properties,
            'totals' => [
                'nights_sold' => $totalNights,
                'nights_available' => $available,
                'occupancy_rate' => $available > 0
                    ? round($totalNights / $available * 100, 2)
                    : 0.0,
                'accommodation_revenue' => $totalRevenue->jsonSerialize(),
                'adr' => $totalNights > 0
                    ? Money::of(intdiv($totalRevenue->minorUnits, $totalNights), $currency)->jsonSerialize()
                    : Money::zero($currency)->jsonSerialize(),
            ],
        ];
    }
}
