<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Models\SyncJob;
use App\Domain\Guests\Services\GuestDirectory;
use App\Domain\Integrations\DataObjects\ChannelReservationPayload;
use App\Domain\Integrations\Registries\ChannelAdapterRegistry;
use App\Domain\Payments\Enums\PaymentKind;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Platform\Services\SequenceGenerator;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Models\ReservationNight;
use App\Domain\Reservations\Services\ReservationService;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Brings bookings in from the channels.
 *
 * The inbound direction is harder than the outbound one, because a channel
 * sends bookings for dates we may already have closed and there is nothing to
 * be done about it: the guest has paid, the OTA has confirmed, and refusing
 * the import would simply mean nobody knows about a guest who is arriving.
 *
 * So the rule here is **accept everything, and surface what conflicts**. An
 * imported booking that overlaps an existing one is created anyway and flagged,
 * because an overbooking somebody can see is a problem they can solve by moving
 * a guest, and an overbooking nobody imported is a guest standing outside a
 * locked door.
 *
 * That is the opposite of the direct booking path, which refuses unavailable
 * dates outright — and deliberately so. We control that one.
 *
 * Imports are idempotent on the channel's own reservation id. Every OTA
 * redelivers, and a redelivery must update the booking rather than create a
 * second one.
 */
class ReservationImporter
{
    public function __construct(
        private readonly ChannelAdapterRegistry $adapters,
        private readonly GuestDirectory $guests,
        private readonly ReservationService $reservations,
        private readonly PaymentService $payments,
        private readonly SequenceGenerator $sequences,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Pull everything changed since the last import.
     *
     * @return array{imported: int, updated: int, cancelled: int, conflicts: int, failed: int}
     */
    public function importFor(ChannelAccount $account, ?CarbonImmutable $since = null): array
    {
        $adapter = $this->adapters->make($account->channel);

        $job = SyncJob::query()->create([
            'organization_id' => $account->organization_id,
            'channel_account_id' => $account->getKey(),
            'kind' => 'reservations',
            'direction' => 'pull',
            'status' => SyncJob::RUNNING,
            'started_at' => now(),
            'is_simulated' => ! $adapter->isLive(),
        ]);

        $counts = ['imported' => 0, 'updated' => 0, 'cancelled' => 0, 'conflicts' => 0, 'failed' => 0];

        try {
            $payloads = $adapter->importReservations(
                $account,
                ($since ?? $account->last_imported_at)?->toDateTimeImmutable(),
            );
        } catch (\Throwable $exception) {
            $job->forceFill([
                'status' => SyncJob::FAILED,
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
                'is_retryable' => true,
                'attempts' => 1,
            ])->save();

            return $counts;
        }

        foreach ($payloads as $payload) {
            try {
                $outcome = $this->importOne($account, $payload);

                $counts[$outcome['result']]++;

                if ($outcome['conflicted']) {
                    $counts['conflicts']++;
                }
            } catch (\Throwable $exception) {
                $counts['failed']++;

                // One malformed booking must not abandon the rest of the
                // import: the other twenty guests are still arriving.
                Log::error('Failed to import a channel reservation.', [
                    'channel' => $account->channel,
                    'external_id' => $payload->externalReservationId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $job->forceFill([
            'status' => SyncJob::SUCCEEDED,
            'finished_at' => now(),
            'records_received' => count($payloads),
            'records_failed' => $counts['failed'],
            'result' => $counts,
        ])->save();

        $account->forceFill(['last_imported_at' => now()])->save();

        return $counts;
    }

    /**
     * Import or update one booking.
     *
     * @return array{result: string, reservation: Reservation|null, conflicted: bool}
     */
    public function importOne(ChannelAccount $account, ChannelReservationPayload $payload): array
    {
        $mapping = ChannelListing::query()
            ->with('listing.property')
            ->where('channel_account_id', $account->getKey())
            ->where('external_listing_id', $payload->externalListingId)
            ->first();

        if ($mapping?->listing === null) {
            // A booking for a listing nobody mapped. Recorded rather than
            // silently dropped: somebody needs to map it, and until they do a
            // guest is arriving at a property we have no record for.
            Log::warning('A channel sent a booking for an unmapped listing.', [
                'channel' => $account->channel,
                'external_listing_id' => $payload->externalListingId,
                'external_reservation_id' => $payload->externalReservationId,
            ]);

            return ['result' => 'failed', 'reservation' => null, 'conflicted' => false];
        }

        $existing = Reservation::query()
            ->where('channel_account_id', $account->getKey())
            ->where('external_reservation_id', $payload->externalReservationId)
            ->first();

        if ($existing !== null) {
            return $this->update($existing, $payload);
        }

        return $this->create($account, $mapping, $payload);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array{result: string, reservation: Reservation|null, conflicted: bool}
     */
    private function create(
        ChannelAccount $account,
        ChannelListing $mapping,
        ChannelReservationPayload $payload,
    ): array {
        $listing = $mapping->listing;
        $property = $listing->property;

        $checkIn = CarbonImmutable::parse($payload->checkIn);
        $checkOut = CarbonImmutable::parse($payload->checkOut);

        // Asked, but not obeyed. The answer decides whether this booking is
        // flagged, never whether it is created.
        $conflict = $this->wouldConflict($property->getKey(), $checkIn, $checkOut);

        $guest = $this->guests->findOrCreate(array_filter([
            'first_name' => $payload->guestFirstName ?: 'Guest',
            'last_name' => $payload->guestLastName,
            'email' => $payload->guestEmail,
            'phone' => $payload->guestPhone,
            'country_code' => $payload->guestCountry,
            'language' => $payload->guestLanguage,
        ], static fn (mixed $v): bool => $v !== null && $v !== ''));

        $reservation = DB::transaction(function () use (
            $account, $listing, $property, $payload, $checkIn, $checkOut, $guest, $conflict
        ): Reservation {
            $organizationId = $account->organization_id;

            $reservation = new Reservation;

            $reservation->forceFill([
                'organization_id' => $organizationId,
                'confirmation_code' => $this->sequences->next(
                    $organizationId,
                    SequenceGenerator::RESERVATION,
                    (string) config('pms.reservations.confirmation_code_prefix', 'HB'),
                    6,
                ),
                'property_id' => $property->getKey(),
                'listing_id' => $listing->getKey(),
                'guest_id' => $guest->getKey(),
                'status' => $this->statusFrom($payload)->value,
                'source' => $account->channel,
                'channel_account_id' => $account->getKey(),
                'external_reservation_id' => $payload->externalReservationId,
                'external_confirmation_code' => $payload->confirmationCode,
                'check_in_date' => $checkIn->toDateString(),
                'check_out_date' => $checkOut->toDateString(),
                'nights' => $payload->nights(),
                'adults' => max(1, $payload->adults),
                'children' => $payload->children,
                'infants' => $payload->infants,
                'pets' => $payload->pets,
                'currency' => $payload->currency,
                'base_currency' => $property->organization?->base_currency ?? $payload->currency,
                'guest_notes' => $payload->notes,
                'booked_at' => $payload->bookedAt ?? now(),
                // The channel's own numbers, kept verbatim. Ours are derived;
                // theirs are what the guest actually agreed to pay, and where
                // the two differ the difference is the interesting fact.
                'source_metadata' => [
                    'total_amount' => $payload->totalAmount,
                    'payout_amount' => $payload->payoutAmount,
                    'commission_amount' => $payload->commissionAmount,
                    'tax_amount' => $payload->taxAmount,
                ],
                'metadata' => $conflict === null ? null : [
                    'import_conflict' => $conflict,
                ],
            ])->save();

            $this->writeNights($reservation, $payload);

            $reservation->forceFill([
                'channel_commission' => $payload->commissionAmount,
            ]);

            $reservation->recalculateTotals();

            return $reservation;
        });

        // A channel that collects from the guest has already been paid. The
        // payment is recorded so revenue is recognised, marked as not
        // collected by us so the bank balance is not inflated by money the
        // OTA is holding.
        if ($account->collects_payment && $payload->totalAmount > 0) {
            $this->recordChannelPayment($reservation, $payload, $account);
        }

        return [
            'result' => 'imported',
            'reservation' => $reservation,
            'conflicted' => $conflict !== null,
        ];
    }

    /**
     * @return array{result: string, reservation: Reservation|null, conflicted: bool}
     */
    private function update(Reservation $reservation, ChannelReservationPayload $payload): array
    {
        $status = $this->statusFrom($payload);

        if ($status === ReservationStatus::Cancelled) {
            if (! $reservation->status->isCancelled()) {
                $this->reservations->cancel(
                    $reservation,
                    $payload->cancellationReason ?? 'Cancelled on the channel',
                );
            }

            return ['result' => 'cancelled', 'reservation' => $reservation->fresh(), 'conflicted' => false];
        }

        $checkIn = CarbonImmutable::parse($payload->checkIn);
        $checkOut = CarbonImmutable::parse($payload->checkOut);

        $datesChanged = ! $reservation->check_in_date->isSameDay($checkIn)
            || ! $reservation->check_out_date->isSameDay($checkOut);

        if (! $datesChanged) {
            // A redelivery of something we already have. Every OTA does this,
            // and treating it as a change would rewrite the booking for no
            // reason.
            return ['result' => 'updated', 'reservation' => $reservation, 'conflicted' => false];
        }

        $conflict = $this->wouldConflict(
            $reservation->property_id,
            $checkIn,
            $checkOut,
            $reservation->getKey(),
        );

        DB::transaction(function () use ($reservation, $payload, $checkIn, $checkOut, $conflict): void {
            $reservation->forceFill([
                'check_in_date' => $checkIn->toDateString(),
                'check_out_date' => $checkOut->toDateString(),
                'nights' => $payload->nights(),
                'adults' => max(1, $payload->adults),
                'children' => $payload->children,
                'metadata' => array_merge($reservation->metadata ?? [], array_filter([
                    'import_conflict' => $conflict,
                ])),
            ])->save();

            // The nights are rewritten rather than patched: a moved booking
            // has different dates, and a night row for a date the guest is no
            // longer staying would keep earning revenue.
            $reservation->stayNights()->delete();
            $this->writeNights($reservation, $payload);

            $reservation->recalculateTotals();
        });

        return [
            'result' => 'updated',
            'reservation' => $reservation->fresh(),
            'conflicted' => $conflict !== null,
        ];
    }

    /**
     * Write one row per night from the channel's total.
     *
     * The channel gives a total, not a breakdown, so it is spread evenly with
     * the remainder distributed rather than lost. Every occupancy, ADR and
     * revenue figure in the product reads these rows, so a booking without
     * them is invisible to reporting.
     */
    private function writeNights(Reservation $reservation, ChannelReservationPayload $payload): void
    {
        $nights = max(1, $payload->nights());

        // Accommodation is the total less the tax the channel reported: tax
        // is collected on somebody else's behalf and was never revenue.
        $accommodation = Money::of(
            max(0, $payload->totalAmount - $payload->taxAmount),
            $payload->currency,
        );

        // Allocated rather than divided: three nights of 100.00 must come to
        // exactly 100.00, not 99.99 with a cent unaccounted for.
        $perNight = $accommodation->allocateEvenly($nights);

        $date = CarbonImmutable::parse($payload->checkIn);

        foreach ($perNight as $amount) {
            ReservationNight::query()->create([
                'organization_id' => $reservation->organization_id,
                'reservation_id' => $reservation->getKey(),
                'stay_date' => $date->toDateString(),
                'rate_amount' => $amount->minorUnits,
                'currency' => $amount->currency,
                'pricing_trace' => [
                    [
                        'source' => 'channel_import',
                        'label' => 'Imported from the channel; the nightly split is derived from the total.',
                    ],
                ],
            ]);

            $date = $date->addDay();
        }
    }

    /**
     * Record that the channel already took the guest's money.
     */
    private function recordChannelPayment(
        Reservation $reservation,
        ChannelReservationPayload $payload,
        ChannelAccount $account,
    ): void {
        try {
            // Recorded, not processed. The money has already moved and there
            // is no processor to ask — a channel is a distribution partner,
            // not a payment provider.
            $this->payments->recordExternalPayment(
                Money::of($payload->totalAmount, $payload->currency),
                $reservation,
                [
                    'kind' => PaymentKind::Booking,
                    'method' => 'channel_collected',
                    // The guest has paid, but the money is with the OTA. Any
                    // other treatment inflates the bank balance by every
                    // channel booking.
                    'is_collected_by_us' => false,
                    'description' => sprintf('Collected by %s', $account->name),
                    'fee_amount' => $payload->commissionAmount,
                ],
            );
        } catch (\Throwable $exception) {
            // A payment that could not be recorded must not lose the booking.
            // The guest is still arriving.
            Log::error('Could not record a channel-collected payment.', [
                'reservation' => $reservation->confirmation_code,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Whether these dates are already taken.
     *
     * Asked so the booking can be *flagged*, never so it can be refused. The
     * guest has paid and the OTA has confirmed; an overbooking somebody can
     * see is a problem they can solve by moving a guest, and one nobody
     * imported is a guest standing outside a locked door.
     *
     * @return array<string, mixed>|null
     */
    private function wouldConflict(
        string $propertyId,
        CarbonImmutable $checkIn,
        CarbonImmutable $checkOut,
        ?string $excluding = null,
    ): ?array {
        $clashes = Reservation::query()
            ->where('property_id', $propertyId)
            ->when($excluding !== null, fn ($q) => $q->whereKeyNot($excluding))
            ->blocking()
            ->overlapping($checkIn->toDateString(), $checkOut->toDateString())
            ->get(['id', 'confirmation_code', 'check_in_date', 'check_out_date']);

        if ($clashes->isEmpty()) {
            return null;
        }

        return [
            'detected_at' => now()->toIso8601String(),
            'overlaps' => $clashes->map(fn (Reservation $clash): array => [
                'reservation_id' => $clash->getKey(),
                'confirmation_code' => $clash->confirmation_code,
                'check_in' => $clash->check_in_date->toDateString(),
                'check_out' => $clash->check_out_date->toDateString(),
            ])->all(),
        ];
    }

    private function statusFrom(ChannelReservationPayload $payload): ReservationStatus
    {
        return match (strtolower($payload->status)) {
            'cancelled', 'canceled' => ReservationStatus::Cancelled,
            'pending', 'request' => ReservationStatus::Tentative,
            default => ReservationStatus::Confirmed,
        };
    }
}
