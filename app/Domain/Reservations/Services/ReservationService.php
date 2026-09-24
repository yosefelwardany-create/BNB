<?php

declare(strict_types=1);

namespace App\Domain\Reservations\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Availability\DataObjects\AvailabilityRequest;
use App\Domain\Availability\Services\AvailabilityEngine;
use App\Domain\Guests\Models\Guest;
use App\Domain\Guests\Services\GuestDirectory;
use App\Domain\Platform\Services\PlanEnforcement;
use App\Domain\Platform\Services\SequenceGenerator;
use App\Domain\Pricing\DataObjects\PriceQuote;
use App\Domain\Pricing\DataObjects\PricingContext;
use App\Domain\Pricing\Services\PricingEngine;
use App\Domain\Properties\Models\CancellationPolicy;
use App\Domain\Properties\Models\Unit;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Events\ReservationCancelled;
use App\Domain\Reservations\Events\ReservationConfirmed;
use App\Domain\Reservations\Events\ReservationCreated;
use App\Domain\Reservations\Events\ReservationModified;
use App\Domain\Reservations\Exceptions\InvalidReservationTransitionException;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Models\ReservationCharge;
use App\Domain\Reservations\Models\ReservationNight;
use App\Domain\Reservations\Models\ReservationStatusChange;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creating and changing reservations.
 *
 * This is the orchestration layer: availability decides whether nights can be
 * sold, pricing decides what they cost, and this service commits the result as
 * one atomic change and announces it.
 *
 * Everything that commits inventory runs inside
 * {@see AvailabilityEngine::reserve()}, which re-checks availability under a
 * row lock. That is the only thing standing between the product and a double
 * booking, so no path here is allowed to bypass it.
 */
class ReservationService
{
    public function __construct(
        private readonly AvailabilityEngine $availability,
        private readonly PricingEngine $pricing,
        private readonly GuestDirectory $guests,
        private readonly SequenceGenerator $sequences,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Create a reservation, holding or committing inventory according to the
     * status requested.
     */
    public function create(ReservationRequest $request): Reservation
    {
        // Checked before anything is locked or priced. A monthly cap that is
        // enforced after the availability lock would hold rows for a booking
        // that was always going to be refused.
        app(PlanEnforcement::class)->assertCanAdd('max_reservations_per_month');

        $listing = $request->listing;
        $property = $listing->property;

        $availabilityRequest = new AvailabilityRequest(
            property: $property,
            checkIn: $request->checkIn,
            checkOut: $request->checkOut,
            listing: $listing,
            unit: $request->unit,
            unitTypeId: $request->unitTypeId ?? $listing->unit_type_id,
            guests: $request->adults + $request->children,
            ignoreRestrictions: $request->overrideRestrictions,
        );

        // Inquiries and quotes hold nothing, so they skip the availability
        // gate entirely — a guest may legitimately ask about sold-out dates.
        if (! $request->status->blocksInventory()) {
            return DB::transaction(fn (): Reservation => $this->persist($request, null));
        }

        return $this->availability->reserve(
            $availabilityRequest,
            function ($result) use ($request): Reservation {
                return $this->persist($request, $result->preferredUnitId());
            },
        );
    }

    /**
     * Price a stay without committing anything.
     */
    public function quote(ReservationRequest $request): PriceQuote
    {
        return $this->pricing->quote($this->pricingContext($request));
    }

    /**
     * Move a reservation to a new status, enforcing the transition table.
     */
    public function transitionTo(
        Reservation $reservation,
        ReservationStatus $target,
        ?string $reason = null,
        string $actorType = 'user',
        array $context = [],
    ): Reservation {
        $from = $reservation->status;

        if ($from === $target) {
            return $reservation;
        }

        if (! $from->canTransitionTo($target)) {
            throw new InvalidReservationTransitionException($from, $target);
        }

        // Moving into a state that holds inventory has to re-check
        // availability: the dates may have been sold while this booking sat as
        // an inquiry.
        if (! $from->blocksInventory() && $target->blocksInventory()) {
            return $this->availability->reserve(
                new AvailabilityRequest(
                    property: $reservation->property,
                    checkIn: $reservation->check_in_date,
                    checkOut: $reservation->check_out_date,
                    listing: $reservation->listing,
                    unit: $reservation->unit,
                    unitTypeId: $reservation->unit_type_id,
                    ignoreReservationId: $reservation->getKey(),
                ),
                fn ($result) => $this->applyTransition($reservation, $target, $reason, $actorType, $context, $result->preferredUnitId()),
            );
        }

        return DB::transaction(
            fn (): Reservation => $this->applyTransition($reservation, $target, $reason, $actorType, $context)
        );
    }

    public function confirm(Reservation $reservation, ?string $reason = null): Reservation
    {
        return $this->transitionTo($reservation, ReservationStatus::Confirmed, $reason);
    }

    public function checkIn(Reservation $reservation): Reservation
    {
        return $this->transitionTo($reservation, ReservationStatus::CheckedIn, 'Guest arrived');
    }

    public function checkOut(Reservation $reservation): Reservation
    {
        return $this->transitionTo($reservation, ReservationStatus::CheckedOut, 'Guest departed');
    }

    /**
     * Change the dates, unit or guest count of an existing booking.
     *
     * The stay is re-priced and the nights rebuilt, because a date change that
     * kept the old rates would misstate both the guest's bill and the owner's
     * revenue.
     *
     * @param  array{check_in?: CarbonImmutable, check_out?: CarbonImmutable, unit_id?: ?string, adults?: int, children?: int, infants?: int, pets?: int}  $changes
     */
    public function modify(Reservation $reservation, array $changes, ?string $reason = null): Reservation
    {
        $original = [
            'check_in_date' => $reservation->check_in_date->toDateString(),
            'check_out_date' => $reservation->check_out_date->toDateString(),
            'unit_id' => $reservation->unit_id,
            'adults' => (int) $reservation->adults,
            'children' => (int) $reservation->children,
            'grand_total' => (int) $reservation->grand_total,
        ];

        $checkIn = $changes['check_in'] ?? $reservation->check_in_date;
        $checkOut = $changes['check_out'] ?? $reservation->check_out_date;
        $datesChanged = $checkIn->toDateString() !== $original['check_in_date']
            || $checkOut->toDateString() !== $original['check_out_date'];

        $request = new AvailabilityRequest(
            property: $reservation->property,
            checkIn: $checkIn,
            checkOut: $checkOut,
            listing: $reservation->listing,
            unit: isset($changes['unit_id'])
                ? Unit::query()->find($changes['unit_id'])
                : $reservation->unit,
            unitTypeId: $reservation->unit_type_id,
            guests: ($changes['adults'] ?? $reservation->adults) + ($changes['children'] ?? $reservation->children),
            // The booking being changed must not count itself as a conflict.
            ignoreReservationId: $reservation->getKey(),
        );

        return $this->availability->reserve($request, function ($result) use (
            $reservation, $changes, $checkIn, $checkOut, $datesChanged, $original, $reason
        ): Reservation {
            $reservation->fill(array_filter([
                'adults' => $changes['adults'] ?? null,
                'children' => $changes['children'] ?? null,
                'infants' => $changes['infants'] ?? null,
                'pets' => $changes['pets'] ?? null,
            ], fn ($v): bool => $v !== null));

            $reservation->check_in_date = $checkIn;
            $reservation->check_out_date = $checkOut;
            $reservation->nights = (int) $checkIn->startOfDay()->diffInDays($checkOut->startOfDay());

            if (array_key_exists('unit_id', $changes)) {
                $reservation->unit_id = $changes['unit_id'];
            } elseif ($datesChanged && $reservation->unit_id !== null) {
                // The previously assigned unit may not be free for the new
                // dates; take whichever unit the re-check found.
                $reservation->unit_id = $result->preferredUnitId() ?? $reservation->unit_id;
            }

            $reservation->save();

            // Re-price and rebuild the stay.
            $quote = $this->pricing->quote(new PricingContext(
                listing: $reservation->listing,
                checkIn: $checkIn,
                checkOut: $checkOut,
                adults: (int) $reservation->adults,
                children: (int) $reservation->children,
                infants: (int) $reservation->infants,
                pets: (int) $reservation->pets,
                channel: $reservation->source,
                bookingDate: $reservation->booked_at ?? now()->toImmutable(),
                unitId: $reservation->unit_id,
            ));

            $this->writeNights($reservation, $quote);
            $this->writeSystemCharges($reservation, $quote);

            $reservation->recalculateTotals();

            $this->audit->record(
                action: 'reservation.modified',
                subject: $reservation,
                oldValues: $original,
                newValues: [
                    'check_in_date' => $reservation->check_in_date->toDateString(),
                    'check_out_date' => $reservation->check_out_date->toDateString(),
                    'unit_id' => $reservation->unit_id,
                    'adults' => (int) $reservation->adults,
                    'children' => (int) $reservation->children,
                    'grand_total' => (int) $reservation->grand_total,
                ],
                description: $reason ?? 'Reservation modified',
            );

            ReservationModified::dispatch($reservation, $original, $reason);

            return $reservation;
        });
    }

    /**
     * Cancel a reservation and compute what the guest is owed.
     *
     * The refund is *calculated* here but not moved: actually returning the
     * money is the payment service's job, triggered by the event this raises.
     * Keeping the two apart means a payment provider outage cannot leave a
     * reservation stuck half-cancelled.
     */
    public function cancel(
        Reservation $reservation,
        ?string $reason = null,
        string $cancelledBy = 'host',
        bool $waiveFees = false,
    ): Reservation {
        if ($reservation->status->isCancelled()) {
            return $reservation;
        }

        if (! $reservation->status->canTransitionTo(ReservationStatus::Cancelled)) {
            throw new InvalidReservationTransitionException($reservation->status, ReservationStatus::Cancelled);
        }

        return DB::transaction(function () use ($reservation, $reason, $cancelledBy, $waiveFees): Reservation {
            $refund = $this->calculateCancellationRefund($reservation, $waiveFees);

            $from = $reservation->status;

            $reservation->status = ReservationStatus::Cancelled;
            $reservation->cancelled_at = now();
            $reservation->cancellation_reason = $reason;
            $reservation->cancelled_by = $cancelledBy;
            $reservation->cancellation_refund = $refund['refund']->minorUnits;
            $reservation->save();

            $this->recordStatusChange($reservation, $from, ReservationStatus::Cancelled, $reason, $cancelledBy === 'guest' ? 'guest' : 'user', [
                'refund' => $refund['refund']->minorUnits,
                'refund_percent' => $refund['refund_percent'],
                'explanation' => $refund['explanation'],
            ]);

            $this->guests->recomputeStatistics($reservation->guest);

            $this->audit->record(
                action: 'reservation.cancelled',
                subject: $reservation,
                newValues: [
                    'cancelled_by' => $cancelledBy,
                    'reason' => $reason,
                    'refund' => $refund['refund']->minorUnits,
                ],
                description: $refund['explanation'],
            );

            ReservationCancelled::dispatch(
                $reservation,
                $refund['refund'],
                $reason,
                $cancelledBy,
                $refund['explanation'],
            );

            return $reservation;
        });
    }

    /**
     * What a guest would get back if the booking were cancelled now.
     *
     * Exposed separately so an agent can tell a guest the number before
     * committing to anything, and so the figure shown and the figure applied
     * are produced by the same code.
     *
     * @return array{refund: Money, retained: Money, refund_percent: float, explanation: string, breakdown: list<array<string, string>>}
     */
    public function calculateCancellationRefund(Reservation $reservation, bool $waiveFees = false): array
    {
        $currency = $reservation->currency;

        if ($waiveFees) {
            // A goodwill cancellation returns everything actually collected.
            $refund = $reservation->netPaid();

            return [
                'refund' => $refund,
                'retained' => Money::zero($currency),
                'refund_percent' => 100.0,
                'explanation' => 'Cancellation fees waived; the full amount paid is refundable.',
                'breakdown' => [],
            ];
        }

        $policy = $reservation->cancellationPolicy;

        // The policy as it stood when the booking was taken is what the guest
        // agreed to; a later edit to the policy must not change it.
        $snapshot = $reservation->cancellation_policy_snapshot;

        if ($policy === null && $snapshot === null) {
            // With no policy on record, nothing is contractually retained.
            return [
                'refund' => $reservation->netPaid(),
                'retained' => Money::zero($currency),
                'refund_percent' => 100.0,
                'explanation' => 'No cancellation policy was attached to this booking, so the amount paid is refundable in full.',
                'breakdown' => [],
            ];
        }

        if ($policy === null) {
            $policy = new CancellationPolicy;
            $policy->forceFill($snapshot);
        }

        $refundableFees = Money::zero($currency);

        foreach ($reservation->charges()->ofKind(ReservationCharge::KIND_FEE)->refundable()->get() as $charge) {
            $refundableFees = $refundableFees->add($charge->amount());
        }

        $quote = $policy->quote(
            cancellationMoment: $reservation->property->localNow(),
            arrival: $reservation->check_in_date,
            accommodation: $reservation->accommodationTotal(),
            cleaningFee: $refundableFees,
            taxes: $reservation->taxesTotal(),
        );

        // A refund can never exceed what was actually collected.
        $collected = $reservation->netPaid();
        $refund = $quote['refund']->greaterThan($collected) ? $collected : $quote['refund'];

        return [
            'refund' => $refund,
            'retained' => $collected->subtract($refund),
            'refund_percent' => $quote['refund_percent'],
            'explanation' => $quote['explanation'],
            'breakdown' => $quote['breakdown'],
        ];
    }

    /**
     * Bring a cancelled reservation back.
     *
     * This has to re-acquire inventory, because the nights may have been sold
     * in the meantime — which is why it is not an ordinary status transition.
     */
    public function reinstate(Reservation $reservation, ?string $reason = null): Reservation
    {
        if (! $reservation->status->isCancelled()) {
            throw new InvalidReservationTransitionException($reservation->status, ReservationStatus::Confirmed);
        }

        return $this->availability->reserve(
            new AvailabilityRequest(
                property: $reservation->property,
                checkIn: $reservation->check_in_date,
                checkOut: $reservation->check_out_date,
                listing: $reservation->listing,
                unit: $reservation->unit,
                unitTypeId: $reservation->unit_type_id,
                ignoreReservationId: $reservation->getKey(),
            ),
            function ($result) use ($reservation, $reason): Reservation {
                $from = $reservation->status;

                $reservation->status = ReservationStatus::Confirmed;
                $reservation->cancelled_at = null;
                $reservation->cancellation_reason = null;
                $reservation->cancelled_by = null;
                $reservation->cancellation_refund = 0;
                $reservation->unit_id = $reservation->unit_id ?? $result->preferredUnitId();
                $reservation->save();

                $this->recordStatusChange(
                    $reservation,
                    $from,
                    ReservationStatus::Confirmed,
                    $reason ?? 'Reservation reinstated',
                );

                $this->guests->recomputeStatistics($reservation->guest);

                ReservationConfirmed::dispatch($reservation);

                return $reservation;
            },
        );
    }

    /**
     * Add a charge to a booking — a damage charge, a manual discount, an
     * agreed adjustment.
     */
    public function addCharge(
        Reservation $reservation,
        string $kind,
        string $label,
        Money $amount,
        array $options = [],
    ): ReservationCharge {
        return DB::transaction(function () use ($reservation, $kind, $label, $amount, $options): ReservationCharge {
            // Discounts are stored negative so summing lines always gives the
            // total; a caller passing a positive discount means a reduction.
            $signed = $kind === ReservationCharge::KIND_DISCOUNT
                ? $amount->absolute()->negate()
                : $amount;

            $charge = ReservationCharge::query()->create([
                'organization_id' => $reservation->organization_id,
                'reservation_id' => $reservation->getKey(),
                'kind' => $kind,
                'code' => $options['code'] ?? null,
                'label' => $label,
                'description' => $options['description'] ?? null,
                'quantity' => $options['quantity'] ?? 1,
                'unit_amount' => $signed->minorUnits,
                'amount' => $signed->minorUnits,
                'currency' => $signed->currency,
                'is_taxable' => $options['is_taxable'] ?? false,
                'is_refundable' => $options['is_refundable'] ?? true,
                'origin' => $options['origin'] ?? ReservationCharge::ORIGIN_MANUAL,
                'created_by_id' => auth()->id(),
                'metadata' => $options['metadata'] ?? null,
            ]);

            $reservation->recalculateTotals();

            $this->audit->record(
                action: 'reservation.charge_added',
                subject: $reservation,
                newValues: ['kind' => $kind, 'label' => $label, 'amount' => $signed->minorUnits],
                description: sprintf('Added %s of %s to the booking', $label, $signed->toDecimal()),
            );

            return $charge;
        });
    }

    /**
     * Take a charge off a booking.
     *
     * Only for a line that was added in error or withdrawn before it happened
     * — a cancelled extra, a duplicate. Something the guest actually received
     * and is no longer being charged for belongs as a discount or a refund,
     * where both facts survive.
     *
     * Deliberately narrow: it refuses to remove a charge the pricing engine
     * produced, because accommodation, fees and tax are derived from the stay
     * and deleting one would leave the booking's total disagreeing with its
     * own nights.
     */
    public function removeCharge(Reservation $reservation, string $chargeId): bool
    {
        return DB::transaction(function () use ($reservation, $chargeId): bool {
            $charge = ReservationCharge::query()
                ->where('reservation_id', $reservation->getKey())
                ->whereKey($chargeId)
                ->first();

            if ($charge === null) {
                return false;
            }

            if ($charge->origin === ReservationCharge::ORIGIN_SYSTEM) {
                throw new \RuntimeException(
                    'A charge produced by the pricing engine cannot be removed. '
                    .'Change the booking, or add an adjustment.',
                );
            }

            $charge->delete();

            $reservation->recalculateTotals();

            $this->audit->record(
                action: 'reservation.charge_removed',
                subject: $reservation,
                oldValues: ['label' => $charge->label, 'amount' => (int) $charge->amount],
                description: sprintf('Removed %s from the booking', $charge->label),
            );

            return true;
        });
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Write the reservation and everything that hangs off it.
     */
    private function persist(ReservationRequest $request, ?string $allocatedUnitId): Reservation
    {
        $listing = $request->listing;
        $property = $listing->property;
        $organization = $this->tenancy->organizationOrFail();

        $guest = $request->guest ?? ($request->guestAttributes !== null
            ? $this->guests->findOrCreate($request->guestAttributes)
            : null);

        $quote = $this->pricing->quote($this->pricingContext($request));

        $policy = $listing->cancellationPolicy ?? $property->cancellationPolicy;

        $reservation = new Reservation;

        $reservation->fill([
            'organization_id' => $organization->getKey(),
            'confirmation_code' => $this->sequences->next(
                $organization->getKey(),
                SequenceGenerator::RESERVATION,
                (string) config('pms.reservations.confirmation_code_prefix', 'HB'),
            ),
            'property_id' => $property->getKey(),
            'listing_id' => $listing->getKey(),
            'unit_type_id' => $request->unitTypeId ?? $listing->unit_type_id,
            'unit_id' => $request->unit?->getKey() ?? $allocatedUnitId ?? $listing->unit_id,
            'guest_id' => $guest?->getKey(),
            'status' => $request->status,
            'source' => $request->source,
            'channel_account_id' => $request->channelAccountId,
            'external_reservation_id' => $request->externalReservationId,
            'external_confirmation_code' => $request->externalConfirmationCode,
            'check_in_date' => $request->checkIn,
            'check_out_date' => $request->checkOut,
            'nights' => $request->nights(),
            'adults' => $request->adults,
            'children' => $request->children,
            'infants' => $request->infants,
            'pets' => $request->pets,
            'currency' => $listing->currency,
            'base_currency' => $organization->base_currency,
            'exchange_rate' => $request->exchangeRate,
            'rate_plan_id' => $request->ratePlan?->getKey(),
            'cancellation_policy_id' => $policy?->getKey(),
            // The policy is snapshotted so a later edit cannot change what the
            // guest agreed to.
            'cancellation_policy_snapshot' => $policy === null ? null : [
                'name' => $policy->name,
                'free_cancellation_days' => (int) $policy->free_cancellation_days,
                'tiers' => $policy->tiers,
                'refund_cleaning_fee' => $policy->refund_cleaning_fee,
                'refund_taxes' => $policy->refund_taxes,
            ],
            'guest_notes' => $request->guestNotes,
            'internal_notes' => $request->internalNotes,
            'booked_at' => $request->bookedAt ?? now(),
            'hold_expires_at' => $request->status === ReservationStatus::Tentative
                ? now()->addMinutes((int) config('pms.reservations.hold_minutes', 30))
                : null,
            'source_metadata' => $request->sourceMetadata,
            'created_by_id' => auth()->id(),
        ]);

        $reservation->save();

        $this->writeNights($reservation, $quote);
        $this->writeSystemCharges($reservation, $quote);

        if ($request->channelCommission !== null && $request->channelCommission->isPositive()) {
            ReservationCharge::query()->create([
                'organization_id' => $reservation->organization_id,
                'reservation_id' => $reservation->getKey(),
                'kind' => ReservationCharge::KIND_COMMISSION,
                'code' => 'channel_commission',
                'label' => sprintf('%s commission', $request->source),
                'quantity' => 1,
                'unit_amount' => $request->channelCommission->minorUnits,
                'amount' => $request->channelCommission->minorUnits,
                'currency' => $request->channelCommission->currency,
                'is_refundable' => false,
                'origin' => ReservationCharge::ORIGIN_CHANNEL,
            ]);
        }

        $reservation->recalculateTotals();

        $this->recordStatusChange($reservation, null, $request->status, 'Reservation created', $request->actorType);

        if ($guest !== null) {
            $this->guests->recomputeStatistics($guest);
        }

        $this->audit->created($reservation, sprintf(
            'Reservation %s created for %s, %s to %s',
            $reservation->confirmation_code,
            $guest?->fullName() ?? 'an unnamed guest',
            $reservation->check_in_date->toDateString(),
            $reservation->check_out_date->toDateString(),
        ));

        ReservationCreated::dispatch($reservation);

        if ($reservation->status === ReservationStatus::Confirmed) {
            $reservation->confirmed_at = now();
            $reservation->saveQuietly();

            ReservationConfirmed::dispatch($reservation);
        }

        return $reservation->refresh();
    }

    private function pricingContext(ReservationRequest $request): PricingContext
    {
        return new PricingContext(
            listing: $request->listing,
            checkIn: $request->checkIn,
            checkOut: $request->checkOut,
            adults: $request->adults,
            children: $request->children,
            infants: $request->infants,
            pets: $request->pets,
            channel: $request->source,
            ratePlan: $request->ratePlan,
            promotionCode: $request->promotionCode,
            bookingDate: $request->bookedAt,
            unitId: $request->unit?->getKey(),
        );
    }

    /**
     * Replace the per-night rows from a quote.
     */
    private function writeNights(Reservation $reservation, PriceQuote $quote): void
    {
        // Nights already posted to the ledger are not rewritten; the
        // adjustment goes through a reversing entry instead.
        $reservation->stayNights()->whereNull('recognised_at')->delete();

        $existing = $reservation->stayNights()
            ->pluck('stay_date')
            ->map(fn ($d): string => CarbonImmutable::parse($d)->toDateString())
            ->all();

        foreach ($quote->nights as $night) {
            if (in_array($night->date, $existing, true)) {
                continue;
            }

            ReservationNight::query()->create([
                'organization_id' => $reservation->organization_id,
                'reservation_id' => $reservation->getKey(),
                'stay_date' => $night->date,
                'rate_amount' => $night->rate->minorUnits,
                'currency' => $night->rate->currency,
                'unit_id' => $night->unitId ?? $reservation->unit_id,
                'pricing_trace' => $night->toArray()['steps'],
            ]);
        }
    }

    /**
     * Replace the system-generated charges from a quote.
     *
     * Manually added lines are left alone: a damage charge an operator entered
     * must survive a re-price.
     */
    private function writeSystemCharges(Reservation $reservation, PriceQuote $quote): void
    {
        $reservation->charges()
            ->where('origin', ReservationCharge::ORIGIN_SYSTEM)
            ->delete();

        foreach ([
            ReservationCharge::KIND_FEE => $quote->fees,
            ReservationCharge::KIND_DISCOUNT => $quote->discounts,
            ReservationCharge::KIND_TAX => $quote->taxes,
        ] as $kind => $lines) {
            foreach ($lines as $line) {
                ReservationCharge::query()->create([
                    'organization_id' => $reservation->organization_id,
                    'reservation_id' => $reservation->getKey(),
                    'kind' => $kind,
                    'code' => $line->code,
                    'label' => $line->label,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_amount' => ($line->unitAmount ?? $line->amount)->minorUnits,
                    'amount' => $line->amount->minorUnits,
                    'currency' => $line->amount->currency,
                    'is_taxable' => $line->isTaxable,
                    'is_refundable' => $line->isRefundable,
                    'tax_rule_id' => $kind === ReservationCharge::KIND_TAX ? $line->ruleId : null,
                    'fee_rule_id' => $kind === ReservationCharge::KIND_FEE ? $line->ruleId : null,
                    'promotion_id' => $kind === ReservationCharge::KIND_DISCOUNT ? $line->ruleId : null,
                    'origin' => ReservationCharge::ORIGIN_SYSTEM,
                    'calculation_trace' => $line->trace,
                ]);
            }
        }
    }

    private function applyTransition(
        Reservation $reservation,
        ReservationStatus $target,
        ?string $reason,
        string $actorType,
        array $context = [],
        ?string $allocatedUnitId = null,
    ): Reservation {
        $from = $reservation->status;

        $reservation->status = $target;

        match ($target) {
            ReservationStatus::Confirmed => $reservation->forceFill([
                'confirmed_at' => $reservation->confirmed_at ?? now(),
                // A confirmed booking is no longer a hold.
                'hold_expires_at' => null,
                'unit_id' => $reservation->unit_id ?? $allocatedUnitId,
            ]),
            ReservationStatus::CheckedIn => $reservation->forceFill(['checked_in_at' => now()]),
            ReservationStatus::CheckedOut => $reservation->forceFill(['checked_out_at' => now()]),
            ReservationStatus::NoShow => $reservation->forceFill(['cancelled_at' => now(), 'cancelled_by' => 'system']),
            default => null,
        };

        $reservation->save();

        $this->recordStatusChange($reservation, $from, $target, $reason, $actorType, $context);

        if ($reservation->guest !== null) {
            $this->guests->recomputeStatistics($reservation->guest);
        }

        if ($target === ReservationStatus::Confirmed) {
            ReservationConfirmed::dispatch($reservation);
        }

        return $reservation;
    }

    private function recordStatusChange(
        Reservation $reservation,
        ?ReservationStatus $from,
        ReservationStatus $to,
        ?string $reason = null,
        string $actorType = 'user',
        array $context = [],
    ): void {
        ReservationStatusChange::query()->create([
            'organization_id' => $reservation->organization_id,
            'reservation_id' => $reservation->getKey(),
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'reason' => $reason,
            'actor_type' => $actorType,
            'user_id' => auth()->id(),
            'context' => $context === [] ? null : $context,
        ]);
    }
}
