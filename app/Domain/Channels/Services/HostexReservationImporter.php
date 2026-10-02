<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Guests\Models\Guest;
use App\Domain\Guests\Services\GuestDirectory;
use App\Domain\Integrations\DataObjects\ChannelReservationPayload;
use App\Domain\Integrations\Support\HostexData;
use App\Domain\Platform\Services\SequenceGenerator;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Models\ReservationNight;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Source snapshots are evidence, not payments or accounting transactions. */
class HostexReservationImporter
{
    public function import(ChannelAccount $account, ChannelReservationPayload $payload): array
    {
        return DB::transaction(function () use ($account, $payload): array {
            // Lock the account before the lookup, including the first insert. This
            // serializes pulls and webhook imports even when no reservation exists.
            ChannelAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $mapping = ChannelListing::query()->with('listing.property')
                ->where('organization_id', $account->organization_id)
                ->where('channel_account_id', $account->id)
                ->where('external_listing_id', $payload->externalListingId)->first();
            if ($mapping?->listing === null || $mapping->property_id !== $mapping->listing->property_id) {
                throw new RuntimeException('Reservation has no unambiguous mapped property. Map the Hostex property and pull again.');
            }
            $code = HostexData::text($payload->raw['reservation_code'] ?? null) ?? $payload->externalReservationId;
            $reservation = Reservation::query()->where('channel_account_id', $account->id)
                ->where('external_reservation_id', $payload->externalReservationId)->first();
            // Old imports keyed every stay by the order code. Adopt that row only
            // when its property agrees; never guess which other stay it belongs to.
            if ($reservation === null && $code !== $payload->externalReservationId) {
                $legacy = Reservation::query()->where('channel_account_id', $account->id)
                    ->where('external_reservation_id', $code)->whereNull('hostex_reservation_code')->first();
                if ($legacy !== null && ($legacy->property_id !== $mapping->property_id
                    || $legacy->check_in_date->toDateString() !== $payload->checkIn->format('Y-m-d')
                    || $legacy->check_out_date->toDateString() !== $payload->checkOut->format('Y-m-d'))) {
                    throw new RuntimeException('Ambiguous legacy multi-stay reservation; review its property and stay dates before retrying.');
                }
                $reservation = $legacy;
            }
            if ($reservation !== null && $reservation->property_id !== $mapping->property_id) {
                throw new RuntimeException('This imported stay is already linked to another property. Review the mapping before moving its history.');
            }
            $created = $reservation === null;
            $reservation ??= new Reservation;
            $previousStatus = $created ? null : $reservation->status->value;
            $guest = $this->guest($account, $reservation, $payload, $code);
            $previous = $reservation->source_metadata['hostex'] ?? [];
            $incoming = HostexData::financials($payload->raw);
            $financials = array_replace($previous['financials'] ?? [], $incoming);
            if (isset($incoming['payment'])) {
                $financials['payment'] = array_replace($previous['financials']['payment'] ?? [], $incoming['payment']);
            }
            $source = array_replace($previous, array_filter([
                'reservation_code' => $code,
                'stay_code' => $payload->externalReservationId,
                'channel_id' => $payload->confirmationCode,
                'channel_type' => HostexData::text($payload->raw['channel_type'] ?? null),
                'listing_id' => HostexData::text($payload->raw['listing_id'] ?? null),
                'number_of_guests' => $payload->raw['number_of_guests'] ?? null,
                'remarks' => HostexData::text($payload->raw['remarks'] ?? null),
                'channel_remarks' => $payload->notes,
            ], fn ($value) => $value !== null));
            $source['financials'] = $financials;
            if (array_key_exists('hostex_guest_details', $payload->raw)) {
                $source['guest_details'] = $payload->raw['hostex_guest_details'];
            }
            foreach (['adults', 'children', 'infants', 'pets'] as $field) {
                if (isset($payload->raw['number_of_'.$field])) {
                    $source['guest_counts'][$field] = max(0, (int) $payload->raw['number_of_'.$field]);
                }
            }
            $source['synced_at'] = now()->toIso8601String();
            $source['limitations'] = [
                'Fields omitted by a partial response retain their last known source value; they are not inferred or recalculated.',
                'Hostex payment values describe recorded order-level collections, not proof of Airbnb payment or host payout.',
                'Guest total, host payout and payout status are unavailable unless separately verified; no accounting entries are inferred.',
            ];
            if (! $created && $reservation->payments()->exists()) {
                $source['limitations'][] = 'Existing local payment records were preserved. Review legacy channel-collected entries; earlier imports inferred these from reservation totals.';
            }
            $currency = $financials['rate']['currency'] ?? HostexData::detailTotal($financials, 'ACCOMMODATION')['currency'] ?? 'XXX';
            $status = match ($payload->status) {
                'cancelled' => ReservationStatus::Cancelled,
                'confirmed', 'modified' => ReservationStatus::Confirmed,
                default => ReservationStatus::Tentative,
            };
            if (! $created && ! isset($payload->raw['status'])) {
                $status = $reservation->status;
            }
            // Local check-in/out is operational state, not a channel's "accepted".
            if (! $created && $status === ReservationStatus::Confirmed
                && in_array($reservation->status, [ReservationStatus::CheckedIn, ReservationStatus::CheckedOut], true)) {
                $status = $reservation->status;
            }
            $checkIn = CarbonImmutable::parse($payload->checkIn)->toDateString();
            $checkOut = CarbonImmutable::parse($payload->checkOut)->toDateString();
            $conflicted = $status !== ReservationStatus::Cancelled && Reservation::query()
                ->where('property_id', $mapping->property_id)->when(! $created, fn ($q) => $q->whereKeyNot($reservation->id))
                ->blocking()->overlapping($checkIn, $checkOut)->exists();
            $values = [
                'organization_id' => $account->organization_id,
                'property_id' => $mapping->property_id, 'listing_id' => $mapping->listing_id,
                'guest_id' => $guest->id, 'source' => 'hostex', 'channel_account_id' => $account->id,
                'external_reservation_id' => $payload->externalReservationId,
                'hostex_reservation_code' => $code,
                // Clear a legacy Hostex order code previously mislabeled as Airbnb.
                'external_confirmation_code' => $source['channel_id'] ?? null,
                'status' => $status, 'check_in_date' => $checkIn, 'check_out_date' => $checkOut,
                'nights' => $payload->nights(), 'currency' => $currency,
                // No FX rate is known. Do not mirror CAD and USD at a fictitious 1:1.
                'base_currency' => $currency, 'exchange_rate' => 1,
                'source_metadata' => array_replace($reservation->source_metadata ?? [], ['hostex' => $source]),
                'metadata' => array_replace($reservation->metadata ?? [], ['import_conflict' => $conflicted ? ['detected_at' => now()->toIso8601String()] : null]),
            ];
            foreach (['adults', 'children', 'infants', 'pets'] as $field) {
                if (isset($payload->raw['number_of_'.$field])) {
                    $values[$field] = max(0, (int) $payload->raw['number_of_'.$field]);
                }
            }
            if ($payload->notes !== null) {
                $values['guest_notes'] = $payload->notes;
            }
            if ($payload->bookedAt !== null) {
                $values['booked_at'] = $payload->bookedAt;
            }
            if ($status === ReservationStatus::Cancelled) {
                $values['cancelled_at'] = $payload->cancelledAt ?? $reservation->cancelled_at ?? now();
                $values['cancelled_by'] = 'channel';
            } elseif ($previousStatus === ReservationStatus::Cancelled->value) {
                $values['cancelled_at'] = null;
                $values['cancelled_by'] = null;
            }
            if ($created) {
                $values['confirmation_code'] = app(SequenceGenerator::class)->next($account->organization_id, SequenceGenerator::RESERVATION, 'HB', 6);
            }
            $reservation->forceFill($values)->save();
            $this->totals($reservation, $financials);
            $this->nights($reservation, $financials);
            app(GuestDirectory::class)->recomputeStatistics($guest);
            if ($previousStatus !== $status->value) {
                $reservation->statusChanges()->create([
                    'organization_id' => $account->organization_id, 'from_status' => $previousStatus,
                    'to_status' => $status->value, 'actor_type' => 'system',
                    'reason' => 'Source status synchronized from Hostex.',
                ]);
            }

            return ['result' => $status === ReservationStatus::Cancelled ? 'cancelled' : ($created ? 'imported' : 'updated'), 'reservation' => $reservation, 'conflicted' => $conflicted];
        });
    }

    private function guest(ChannelAccount $account, Reservation $reservation, ChannelReservationPayload $payload, string $code): Guest
    {
        $external = $payload->raw['hostex_guest_id'] ?? 'order:'.$code;
        $link = DB::table('channel_guest_links')->where('organization_id', $account->organization_id)
            ->where('channel_account_id', $account->id)->where('external_guest_id', $external)->first();
        $guest = $link ? Guest::query()->find($link->guest_id) : null;
        $guest ??= $reservation->exists ? Guest::query()->find($reservation->guest_id) : null;
        $guest ??= new Guest(['organization_id' => $account->organization_id, 'first_name' => 'Guest', 'source' => 'hostex']);
        $fields = array_filter([
            'first_name' => $payload->guestFirstName, 'last_name' => $payload->guestLastName,
            'email' => $payload->guestEmail, 'phone' => $payload->guestPhone,
            'country_code' => $payload->guestCountry !== null && strlen($payload->guestCountry) === 2 ? strtoupper($payload->guestCountry) : null,
        ], fn ($value) => $value !== null && $value !== '');
        if (($fields['first_name'] ?? null) === 'Guest' && $guest->first_name !== 'Guest') {
            unset($fields['first_name']);
        }
        $guest->fill($fields);
        if ($payload->guestFirstName !== null && $payload->guestFirstName !== 'Guest') {
            $guest->last_name = $payload->guestLastName;
        }
        if (isset($fields['first_name'])) {
            $guest->display_name = trim($guest->first_name.' '.$guest->last_name);
        }
        $guest->save();
        DB::table('channel_guest_links')->updateOrInsert([
            'organization_id' => $account->organization_id, 'channel_account_id' => $account->id, 'external_guest_id' => $external,
        ], ['guest_id' => $guest->id]);
        DB::table('channel_guest_links')->updateOrInsert([
            'organization_id' => $account->organization_id, 'channel_account_id' => $account->id, 'external_guest_id' => 'order:'.$code,
        ], ['guest_id' => $guest->id]);

        return $guest;
    }

    public function totals(Reservation $reservation, array $financials): void
    {
        $accommodation = HostexData::detailTotal($financials, 'ACCOMMODATION');
        $sameCurrency = fn (?array $money) => ($money['currency'] ?? null) === $reservation->currency ? ($money['amount'] ?? null) : null;
        $reservation->forceFill([
            'accommodation_total' => $sameCurrency($accommodation),
            'fees_total' => null, // Cleaning alone is not the sum of all fees.
            'taxes_total' => $sameCurrency($financials['tax'] ?? null), 'discounts_total' => null,
            'grand_total' => $sameCurrency($financials['rate'] ?? null),
            'base_grand_total' => $sameCurrency($financials['rate'] ?? null),
            'channel_commission' => $sameCurrency($financials['commission'] ?? null),
            'expected_payout' => null, 'paid_total' => null, 'refunded_total' => null, 'balance_due' => null,
        ])->save();
    }

    private function nights(Reservation $reservation, array $financials): void
    {
        $accommodation = HostexData::detailTotal($financials, 'ACCOMMODATION');
        $allocation = isset($accommodation['amount'], $accommodation['currency']) && $accommodation['currency'] === $reservation->currency
            ? Money::of($accommodation['amount'], $accommodation['currency'])->allocateEvenly($reservation->nights)
            : [];
        if ($reservation->stayNights()->whereNotNull('recognised_at')->exists()) {
            // Never rewrite already-posted accounting. Source values remain visible.
            $existing = $reservation->stayNights()->orderBy('stay_date')->get();
            $matches = count($allocation) === $existing->count();
            foreach ($allocation as $offset => $money) {
                $night = $existing->get($offset);
                $matches = $matches && $night !== null
                    && $night->stay_date->toDateString() === $reservation->check_in_date->addDays($offset)->toDateString()
                    && (int) $night->rate_amount === $money->minorUnits && $night->currency === $money->currency;
            }
            $metadata = $reservation->source_metadata;
            if (! $matches || ($metadata['hostex']['requires_accounting_review'] ?? false)) {
                $metadata['hostex']['limitations'][] = 'Previously recognized nightly revenue needs an accounting review; this pull did not rewrite posted amounts.';
                $metadata['hostex']['requires_accounting_review'] = true;
                $reservation->forceFill(['source_metadata' => $metadata])->save();
            }

            return;
        }
        if ($allocation === []) {
            // Remove unposted estimates made by the legacy importer from totals.
            $reservation->stayNights()->delete();

            return;
        }
        $dates = [];
        foreach ($allocation as $offset => $money) {
            $date = $reservation->check_in_date->addDays($offset)->toDateString();
            $dates[] = $date;
            ReservationNight::query()->updateOrCreate(['reservation_id' => $reservation->id, 'stay_date' => $date], [
                'organization_id' => $reservation->organization_id, 'rate_amount' => $money->minorUnits,
                'currency' => $money->currency,
                'pricing_trace' => [['source' => 'hostex_accommodation', 'label' => 'Derived average allocation of Hostex ACCOMMODATION; not the calendar price.']],
            ]);
        }
        $reservation->stayNights()->whereNotIn('stay_date', $dates)->delete();
    }
}
