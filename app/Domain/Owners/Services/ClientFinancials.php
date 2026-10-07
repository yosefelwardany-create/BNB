<?php

declare(strict_types=1);

namespace App\Domain\Owners\Services;

use App\Domain\Owners\Models\ManagementAgreement;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Models\PropertyOwnership;
use App\Domain\Pricing\Services\RevenueAnalytics;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a client's properties earned, and what is left after the management
 * commission — per property, per period, in each property's own currency.
 *
 * Three rules hold everywhere here:
 *
 *  - **Earned by stay night.** Revenue is the accommodation amount of each
 *    night that fell in the period, for stays that actually earned (confirmed,
 *    checked in, checked out). Not booked-at, not collected. The night rows
 *    are the one place the revenue carries its own date and currency.
 *
 *  - **Never across currencies.** A CAD property and a USD property are two
 *    rows and two totals. Adding them would produce a number that is wrong in
 *    both currencies, so there is no portfolio total unless every property
 *    trades in the same one.
 *
 *  - **Uncertain is said, not rounded away.** A night with no verified amount,
 *    a stay flagged for accounting review, a refund line nobody has decided
 *    about, a cancellation inside the period: each raises a flag on the
 *    property and takes `is_final` to false. Nothing is presented as zero when
 *    it is unknown, and nothing is presented as settled when it is not.
 *
 * The commission is the agreement's own arithmetic ({@see ManagementAgreement::commissionFor()}),
 * applied to the aggregated base per property so that 1,000.00 at 10% is exactly
 * 100.00 and 900.00, rounded once, half up, to the currency's minor unit.
 */
class ClientFinancials
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly RevenueAnalytics $analytics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forOwner(Owner $owner, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $shares = $this->shares($owner, $from, $to);
        $propertyIds = $shares->keys()->values()->all();

        if ($propertyIds === []) {
            return [
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'commission' => $this->commissionSummary(null),
                'properties' => [],
                'totals_by_currency' => [],
                'has_incomplete_data' => false,
                'explanation' => $this->explanation(null),
            ];
        }

        $properties = Property::query()
            ->whereIn('id', $propertyIds)
            ->get(['id', 'name', 'internal_name', 'currency', 'timezone', 'status'])
            ->keyBy('id');

        $agreements = $owner->agreements()->where('status', 'active')->get();
        $available = $this->analytics->availableNightsByProperty($from, $to, array_map(strval(...), $propertyIds));

        $nights = DB::table('reservation_nights as rn')
            ->join('reservations as r', 'r.id', '=', 'rn.reservation_id')
            ->where('rn.organization_id', $this->tenancy->id())
            ->whereIn('r.property_id', $propertyIds)
            ->whereBetween('rn.stay_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('r.status', ReservationStatus::revenueValues())
            ->orderBy('rn.stay_date')
            ->get(['r.property_id', 'r.id as reservation_id', 'rn.stay_date', 'rn.rate_amount', 'rn.currency']);

        $flags = $this->flags($propertyIds, $from, $to);

        // property id => currency => accumulator
        $rows = [];

        foreach ($nights as $night) {
            $date = CarbonImmutable::parse($night->stay_date);
            $share = $this->shareOn($shares->get($night->property_id, collect()), $date);

            if ($share === null) {
                continue;
            }

            $property = $properties->get($night->property_id);
            $currency = $night->currency ?: ($property?->currency ?? $this->tenancy->organizationOrFail()->base_currency);

            $row = &$rows[$night->property_id][$currency];
            $row ??= $this->emptyRow($currency);

            $row['nights']++;
            $row['reservations'][$night->reservation_id] = true;

            if ($night->rate_amount === null) {
                $row['unpriced_nights']++;

                continue;
            }

            $earned = Money::of((int) $night->rate_amount, $currency)->percentage($share);
            $row['revenue'] = $row['revenue']->add($earned);

            $agreement = $this->agreementOn($agreements, $night->property_id, $date);

            if ($agreement === null) {
                $row['nights_before_agreement']++;
                $row['revenue_outside_agreement'] = $row['revenue_outside_agreement']->add($earned);

                continue;
            }

            $row['agreements'][$agreement->getKey()] ??= ['agreement' => $agreement, 'base' => Money::zero($currency)];
            $row['agreements'][$agreement->getKey()]['base'] = $row['agreements'][$agreement->getKey()]['base']->add($earned);

            unset($row);
        }

        $output = [];
        $totals = [];
        $anyIncomplete = false;

        foreach ($propertyIds as $propertyId) {
            $property = $properties->get($propertyId);

            if ($property === null) {
                continue;
            }

            $currencies = $rows[$propertyId] ?? [$property->currency => $this->emptyRow($property->currency)];

            foreach ($currencies as $currency => $row) {
                $commission = Money::zero($currency);
                $explanations = [];
                $rate = null;

                foreach ($row['agreements'] as $segment) {
                    /** @var ManagementAgreement $agreement */
                    $agreement = $segment['agreement'];
                    $result = $agreement->commissionFor(
                        accommodation: $segment['base'],
                        fees: Money::zero($currency),
                        taxes: Money::zero($currency),
                        channelCommission: Money::zero($currency),
                        paymentFees: Money::zero($currency),
                    );
                    $commission = $commission->add($result['fee']);
                    $explanations[] = $result['explanation'];
                    $rate ??= (float) $agreement->commission_rate;
                }

                $propertyFlags = $flags[$propertyId] ?? [];

                if ($row['unpriced_nights'] > 0) {
                    $propertyFlags[] = $this->flag('incomplete_rates', $row['unpriced_nights'],
                        'Some nights have no verified accommodation amount yet. They are counted as nights sold but not as revenue.');
                }

                if ($row['nights_before_agreement'] > 0) {
                    $propertyFlags[] = $this->flag('before_agreement', $row['nights_before_agreement'],
                        'Some nights fall before the management agreement took effect. Their revenue is shown and no commission is charged on it.');
                }

                if (count($row['agreements']) > 1) {
                    $propertyFlags[] = $this->flag('terms_changed_in_period', count($row['agreements']),
                        'The agreement changed during this period. Each part is charged at the terms in force at the time.');
                }

                if ($currency !== $property->currency) {
                    $propertyFlags[] = $this->flag('currency_mismatch', $row['nights'],
                        sprintf('These nights were sold in %s while the property is set up in %s. They are reported separately and never converted.', $currency, $property->currency));
                }

                $isFinal = $propertyFlags === [];
                $anyIncomplete = $anyIncomplete || ! $isFinal;

                $after = $row['revenue']->subtract($commission);

                $output[] = [
                    'property_id' => $propertyId,
                    'property_name' => $property->internal_name ?: $property->name,
                    'property_status' => $property->status->value,
                    'currency' => $currency,
                    'ownership_percentage' => $this->headlineShare($shares->get($propertyId, collect())),
                    'nights_sold' => $row['nights'],
                    'nights_available' => $available[$propertyId] ?? 0,
                    'reservations_count' => count($row['reservations']),
                    'revenue_before_commission' => $row['revenue']->jsonSerialize(),
                    'commission_rate' => $rate,
                    'commission' => $commission->jsonSerialize(),
                    'revenue_after_commission' => $after->jsonSerialize(),
                    'commission_explanation' => $explanations === []
                        ? 'No management agreement was in force for these nights, so no commission has been charged.'
                        : implode(' ', array_unique($explanations)),
                    'flags' => $propertyFlags,
                    'is_final' => $isFinal,
                ];

                $totals[$currency] ??= [
                    'currency' => $currency,
                    'properties' => 0,
                    'nights_sold' => 0,
                    'revenue_before_commission' => Money::zero($currency),
                    'commission' => Money::zero($currency),
                    'revenue_after_commission' => Money::zero($currency),
                    'is_final' => true,
                ];
                $totals[$currency]['properties']++;
                $totals[$currency]['nights_sold'] += $row['nights'];
                $totals[$currency]['revenue_before_commission'] = $totals[$currency]['revenue_before_commission']->add($row['revenue']);
                $totals[$currency]['commission'] = $totals[$currency]['commission']->add($commission);
                $totals[$currency]['revenue_after_commission'] = $totals[$currency]['revenue_after_commission']->add($after);
                $totals[$currency]['is_final'] = $totals[$currency]['is_final'] && $isFinal;
            }
        }

        $blanket = $this->agreementOn($agreements, null, $to);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'commission' => $this->commissionSummary($blanket),
            'properties' => $output,
            // Per currency, never summed across them.
            'totals_by_currency' => array_values(array_map(fn (array $t): array => [
                'currency' => $t['currency'],
                'properties' => $t['properties'],
                'nights_sold' => $t['nights_sold'],
                'revenue_before_commission' => $t['revenue_before_commission']->jsonSerialize(),
                'commission' => $t['commission']->jsonSerialize(),
                'revenue_after_commission' => $t['revenue_after_commission']->jsonSerialize(),
                'is_final' => $t['is_final'],
            ], $totals)),
            'has_incomplete_data' => $anyIncomplete,
            'explanation' => $this->explanation($blanket),
        ];
    }

    /**
     * The wording the portal shows beside every figure. Here rather than in
     * the interface so that every client-facing surface says the same thing.
     *
     * @return array<string, string>
     */
    private function explanation(?ManagementAgreement $agreement): array
    {
        // The rate is quoted from the blanket agreement when there is one;
        // a client whose terms are set per property reads each row's own
        // rate, and the sentence must not claim a figure that is not theirs.
        $rate = $agreement === null
            ? 'at the rate in your management agreement'
            : sprintf('of %s%% of commissionable revenue', rtrim(rtrim(number_format((float) $agreement->commission_rate, 2, '.', ''), '0'), '.'));

        return [
            'revenue_before_commission' => 'What your properties earned: the accommodation revenue of each night in the period, excluding cleaning fees, taxes and extra fees.',
            'commission' => sprintf('The management commission %s, deducted by the management company.', $rate),
            'revenue_after_commission' => 'Your revenue after the management commission has been deducted. This is a revenue figure, not a payment: it does not show money received or paid out.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commissionSummary(?ManagementAgreement $agreement): array
    {
        return [
            'rate' => $agreement === null ? null : (float) $agreement->commission_rate,
            'basis' => 'accommodation revenue per stay night, before channel commission, excluding cleaning fees and taxes',
            'effective_from' => $agreement?->starts_on?->toDateString(),
        ];
    }

    /**
     * Ownership rows for the owner, keyed by property, overlapping the period.
     *
     * @return Collection<string, Collection<int, PropertyOwnership>>
     */
    private function shares(Owner $owner, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return PropertyOwnership::query()
            ->where('owner_id', $owner->getKey())
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $to->toDateString()))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $from->toDateString()))
            ->get()
            ->groupBy('property_id');
    }

    /**
     * @param  Collection<int, PropertyOwnership>  $shares
     */
    private function shareOn(Collection $shares, CarbonImmutable $date): ?float
    {
        foreach ($shares as $share) {
            if ($share->isInForceOn($date)) {
                return (float) $share->ownership_percentage;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, PropertyOwnership>  $shares
     */
    private function headlineShare(Collection $shares): float
    {
        return (float) ($shares->max('ownership_percentage') ?? 0);
    }

    /**
     * The agreement in force for a property on a date: a property-specific
     * one wins over a blanket one, as {@see Owner::agreementFor()} decides.
     *
     * @param  Collection<int, ManagementAgreement>  $agreements
     */
    private function agreementOn(Collection $agreements, ?string $propertyId, CarbonImmutable $date): ?ManagementAgreement
    {
        $inForce = $agreements->filter(fn (ManagementAgreement $a): bool => $a->isInForceOn($date)
            && ($a->property_id === null || $a->property_id === $propertyId));

        return $inForce->first(fn (ManagementAgreement $a): bool => $a->property_id !== null)
            ?? $inForce->first(fn (ManagementAgreement $a): bool => $a->property_id === null);
    }

    /**
     * Things about a property's period that stop the figures being final.
     *
     * @param  list<string>  $propertyIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function flags(array $propertyIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $flags = [];

        $base = fn () => DB::table('reservations')
            ->where('organization_id', $this->tenancy->id())
            ->whereIn('property_id', $propertyIds)
            ->where('check_in_date', '<=', $to->toDateString())
            ->where('check_out_date', '>', $from->toDateString());

        // Stays that earned but carry no verified accommodation amount.
        foreach ($base()->whereIn('status', ReservationStatus::revenueValues())
            ->whereNull('accommodation_total')
            ->selectRaw('property_id, count(*) as n')->groupBy('property_id')->get() as $r) {
            $flags[$r->property_id][] = $this->flag('incomplete_amounts', (int) $r->n,
                'Some stays have no verified accommodation total from the booking channel. Their nights are shown without a revenue figure.');
        }

        // Stays the import flagged for accounting review.
        foreach ($base()->whereIn('status', ReservationStatus::revenueValues())
            ->where('source_metadata->hostex->requires_accounting_review', true)
            ->selectRaw('property_id, count(*) as n')->groupBy('property_id')->get() as $r) {
            $flags[$r->property_id][] = $this->flag('accounting_review', (int) $r->n,
                'Some stays are awaiting accounting review because the channel\'s figures changed after revenue was recorded.');
        }

        // Refund lines nobody has decided about. Shown, never netted off.
        foreach ($base()->whereIn('status', ReservationStatus::revenueValues())
            ->whereJsonContains('source_metadata->hostex->financials->details', [['type' => 'CANCELLATION_REFUND_FROM_HOST']])
            ->selectRaw('property_id, count(*) as n')->groupBy('property_id')->get() as $r) {
            $flags[$r->property_id][] = $this->flag('unresolved_refunds', (int) $r->n,
                'Some stays carry a refund from the host that has not been reconciled. Revenue is shown before any refund and is not final.');
        }

        // Cancelled stays inside the period: they earn nothing here, and any
        // cancellation revenue or fee is a decision, not a figure.
        foreach ($base()->where('status', ReservationStatus::Cancelled->value)
            ->selectRaw('property_id, count(*) as n')->groupBy('property_id')->get() as $r) {
            $flags[$r->property_id][] = $this->flag('cancellations_present', (int) $r->n,
                'Some stays in this period were cancelled. They contribute no revenue here; any cancellation charge is reported separately once agreed.');
        }

        return $flags;
    }

    /**
     * @return array{code: string, count: int, message: string}
     */
    private function flag(string $code, int $count, string $message): array
    {
        return ['code' => $code, 'count' => $count, 'message' => $message];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRow(string $currency): array
    {
        return [
            'nights' => 0,
            'unpriced_nights' => 0,
            'nights_before_agreement' => 0,
            'reservations' => [],
            'revenue' => Money::zero($currency),
            'revenue_outside_agreement' => Money::zero($currency),
            'agreements' => [],
        ];
    }
}
