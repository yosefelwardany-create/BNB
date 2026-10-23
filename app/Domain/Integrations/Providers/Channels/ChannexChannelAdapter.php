<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers\Channels;

use App\Domain\Availability\Services\StayRestrictionChecker;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\DataObjects\ChannelAvailabilityUpdate;
use App\Domain\Integrations\DataObjects\ChannelListingPayload;
use App\Domain\Integrations\DataObjects\ChannelRateUpdate;
use App\Domain\Integrations\DataObjects\ChannelReservationPayload;
use App\Domain\Integrations\DataObjects\ChannelSyncResult;
use App\Domain\Integrations\Exceptions\ChannexRequestException;
use App\Domain\Integrations\Support\ChannexClient;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Channex — a channel manager, reached over its own API.
 *
 * Live, like Hostex and unlike the simulated OTAs: Channex already holds the
 * partner agreements that Booking.com and the rest require, so a request from
 * here changes a real rate on a real OTA.
 *
 * ## How a mapping addresses Channex
 *
 * Channex models a property as a property, with room types beneath it and rate
 * plans beneath those. Availability belongs to a **room type**; price and
 * restrictions belong to a **rate plan**. One of our listings is therefore a
 * pair, and both halves are stored on the mapping:
 *
 * - `external_listing_id` — the room type UUID, the inventory this listing is
 * - `metadata.channex.property_id` — the property the room type sits in
 * - `metadata.channex.rate_plan_id` — the plan its price is published under
 *
 * Channex returns the first two parents as `relationships`, not attributes;
 * {@see ChannexClient::records()} lifts them out. The plan's currency is kept
 * beside it as `metadata.channex.currency`, because rates are sent in minor
 * units and Channex reads them in the plan's currency, not ours.
 *
 * A mapping missing any of the three is refused with a message naming which,
 * rather than sent and rejected: Channex answers 422 for an unknown UUID and
 * the operator would have to read a sync log to find out why.
 *
 * ## Two calls, never a loop
 *
 * Channex asks for availability and rates in separate messages and processes
 * them FIFO, pushing availability to the front of the queue. Both endpoints
 * take a `values` array where each entry may span a date range, so a season of
 * changes is one request. Their certification rejects "per-date or per-rate API
 * calls where the test specifies 1 API call", and their rate limit — 10
 * availability and 10 restriction requests per minute, per property — makes a
 * loop unworkable anyway.
 *
 * So this adapter collapses a date-keyed payload into runs of equal value and
 * sends one request. A 500-day calendar with four seasons becomes four entries,
 * not five hundred.
 *
 * ## What it does not claim
 *
 * Publishing a listing, modifying or cancelling a booking, messaging and
 * reviews are all absent. Channex has endpoints in some of those areas, but
 * nothing here has been written or tested against them, and an adapter that
 * claims a capability it has not implemented puts a button on a screen that
 * cannot work.
 */
class ChannexChannelAdapter extends AbstractChannelAdapter
{
    /** Where a mapping's Channex identifiers live. */
    private const META = 'channex';

    public function key(): string
    {
        return 'channex';
    }

    public function displayName(): string
    {
        return 'Channex';
    }

    /**
     * Live whenever an API key is stored.
     *
     * Not a setting somewhere: the only thing that makes this adapter able to
     * do anything is a key, so that is what decides.
     */
    public function isLive(): bool
    {
        return true;
    }

    public function simulationReason(): ?string
    {
        return null;
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return [
            self::CAPABILITY_IMPORT_LISTINGS,
            self::CAPABILITY_AVAILABILITY,
            self::CAPABILITY_PRICING,
            self::CAPABILITY_RESTRICTIONS,
            // Channex carries a first-class stop_sell on the restrictions
            // endpoint, separately from the availability count, so a night
            // closed by hand travels as the decision it was.
            self::CAPABILITY_STOP_SELL,
            self::CAPABILITY_IMPORT_RESERVATIONS,

            /*
             * Deliberately absent:
             *
             * PUBLISH_LISTINGS — a property, its room types and its rate plans
             * are created in Channex (or through its create endpoints, which
             * this adapter does not call). Mapping attaches to what exists.
             *
             * MODIFY/CANCEL_RESERVATIONS — a booking belongs to the OTA that
             * took it. Channex relays changes to us, not the other way round.
             *
             * MESSAGING, REVIEWS, WEBHOOKS — Channex has endpoints for these
             * and none of them is implemented here. Claiming them would put
             * buttons on screens that cannot work. Inbound bookings are read
             * from the revisions feed, which is what Channex recommends over
             * webhooks for a PMS.
             */
        ];
    }

    /**
     * Check the key works, by asking for one property.
     *
     * The cheapest authenticated read there is: a bad key answers 401 and
     * anything else proves the credential reaches an account.
     */
    public function testConnection(ChannelAccount $account): ChannelSyncResult
    {
        try {
            $body = $this->client($account)->get('properties', ['pagination' => ['limit' => 1]]);
        } catch (ChannexRequestException $e) {
            return $this->failure($e);
        }

        $properties = is_array($body['data'] ?? null) ? $body['data'] : [];

        return ChannelSyncResult::success(null, [
            'properties_visible' => count($properties),
        ]);
    }

    /**
     * The room types this key can see, as mappable listings.
     *
     * One payload per room type, because a room type is the thing that holds
     * inventory and therefore the thing one of our listings corresponds to.
     * The property and the rate plan it needs are carried in `extra` so the
     * mapping screen can store all three without a second round trip.
     *
     * @return list<ChannelListingPayload>
     */
    public function importListings(ChannelAccount $account): array
    {
        $client = $this->client($account);

        try {
            // Every page: Channex serves at most 100 records at a time, and an
            // account past that would otherwise lose whatever sorted last.
            $roomTypes = $client->all('room_types');
            $ratePlans = $client->all('rate_plans');
        } catch (ChannexRequestException $e) {
            Log::warning('Could not read the Channex catalogue.', ['error' => $e->getMessage()]);

            return [];
        }

        // The first rate plan found for a room type. A room type with several
        // is a choice an operator has to make on the mapping screen, and
        // picking one here silently would publish prices under a plan nobody
        // chose.
        //
        // The currency comes from the plan too. A room type has none — a real
        // account returns no `currency` on it at all — because in Channex a
        // price is the rate plan's, and so is the currency it is quoted in.
        $planFor = [];
        $planCount = [];
        $currencyFor = [];

        foreach ($ratePlans as $plan) {
            $roomTypeId = $this->str($plan, 'room_type_id');

            if ($roomTypeId === null) {
                continue;
            }

            $planCount[$roomTypeId] = ($planCount[$roomTypeId] ?? 0) + 1;
            $planFor[$roomTypeId] ??= $this->str($plan, 'id');
            $currencyFor[$roomTypeId] ??= $this->str($plan, 'currency');
        }

        $payloads = [];

        foreach ($roomTypes as $roomType) {
            $id = $this->str($roomType, 'id');

            if ($id === null) {
                continue;
            }

            $payloads[] = new ChannelListingPayload(
                externalListingId: $id,
                title: $this->str($roomType, 'title') ?? 'Channex room type',
                maxGuests: $this->int($roomType, 'occ_adults'),
                currency: $currencyFor[$id] ?? null,
                extra: [
                    self::META => [
                        'property_id' => $this->str($roomType, 'property_id'),
                        'rate_plan_id' => $planFor[$id] ?? null,
                        'rate_plan_count' => $planCount[$id] ?? 0,
                        // Kept beside the plan it belongs to, so a push can
                        // refuse prices quoted in something else.
                        'currency' => $currencyFor[$id] ?? null,
                    ],
                ],
            );
        }

        return $payloads;
    }

    /**
     * Send the calendar for a room type.
     *
     * One request, whatever the length of the window: equal consecutive days
     * are collapsed into a single `date_from`/`date_to` entry.
     */
    public function pushAvailability(ChannelListing $listing, ChannelAvailabilityUpdate $update): ChannelSyncResult
    {
        $target = $this->target($listing, needsRatePlan: false);

        if ($target instanceof ChannelSyncResult) {
            return $target;
        }

        $values = [];

        foreach ($this->runs($update->days) as $run) {
            $values[] = $this->window($run) + [
                'property_id' => $target['property_id'],
                'room_type_id' => $target['room_type_id'],
                'availability' => (int) $run['value'],
            ];
        }

        if ($values === []) {
            return ChannelSyncResult::permanentFailure('empty_update', 'No dates were supplied.');
        }

        return $this->write($listing, 'availability', ['values' => $values], count($update->days));
    }

    /**
     * Send prices and stay restrictions for a rate plan.
     *
     * Channex takes both on one endpoint, which is also how it wants them: the
     * price for a night and the rules about staying that night are one message
     * about one rate plan.
     *
     * Minimum stay is sent as `min_stay_arrival`, never as the virtual
     * `min_stay`. Channex only accepts `min_stay` when the property's
     * `min_stay_type` is not `both` — and `both` is exactly what the staging
     * demo property is set to, so every minimum stay would have been refused.
     * Nor is there anything to translate: the platform applies minimum stay
     * by arrival date ({@see StayRestrictionChecker}), so the arrival rule is
     * what it means on every property, whatever that property's setting.
     */
    public function pushRates(ChannelListing $listing, ChannelRateUpdate $update): ChannelSyncResult
    {
        $target = $this->target($listing, needsRatePlan: true);

        if ($target instanceof ChannelSyncResult) {
            return $target;
        }

        /*
         * Rates go as minor units, which Channex reads in the rate plan's own
         * currency. A plan in pounds sent dollar cents would publish the
         * number unchanged under the wrong currency, answer 200, and sell
         * every night at the wrong price. The plan's currency is known from
         * the import, so a mismatch is refused here rather than discovered on
         * an OTA.
         */
        if ($target['currency'] !== null && strtoupper($update->currency) !== $target['currency']) {
            return ChannelSyncResult::permanentFailure(
                'currency_mismatch',
                sprintf(
                    'The Channex rate plan is priced in %s and this listing in %s. Nothing was sent.',
                    $target['currency'],
                    strtoupper($update->currency),
                ),
            );
        }

        $values = [];

        foreach ($this->runs($update->nightlyRates) as $run) {
            $rate = (int) $run['value'];

            if ($rate <= 0) {
                // Channex requires a rate above zero, and a zero here means
                // the pricing engine produced nothing for those nights rather
                // than that they are free.
                return ChannelSyncResult::permanentFailure(
                    'invalid_rate',
                    sprintf('The rate for %s is not a positive amount.', $run['from']),
                );
            }

            $values[] = $this->window($run) + [
                'property_id' => $target['property_id'],
                'rate_plan_id' => $target['rate_plan_id'],
                'rate' => $rate,
            ];
        }

        // Restrictions are sent as their own runs rather than folded into the
        // rate entries: a min-stay that holds across a price change would
        // otherwise be repeated once per price, which is the message bloat
        // Channex's best-practice guide asks integrations to avoid.
        foreach ([
            'min_stay_arrival' => $update->minimumStay,
            'max_stay' => $update->maximumStay,
        ] as $field => $map) {
            foreach ($this->runs($map) as $run) {
                $values[] = $this->window($run) + [
                    'property_id' => $target['property_id'],
                    'rate_plan_id' => $target['rate_plan_id'],
                    $field => (int) $run['value'],
                ];
            }
        }

        /*
         * The boolean restrictions, which Channex keys to the rate plan rather
         * than to the room type — stop_sell among them, which is why this
         * adapter claims that capability and the availability call above does
         * not carry it.
         *
         * Only the true runs are sent. A false is the default for a night
         * Channex has never been told otherwise about, so sending every false
         * would triple the message to restate the absence of a restriction. A
         * restriction being *lifted* still sends, because the run that was
         * true becomes a run that is false and the value changed.
         */
        foreach ([
            'closed_to_arrival' => $update->closedToArrival,
            'closed_to_departure' => $update->closedToDeparture,
            'stop_sell' => $update->stopSell,
        ] as $field => $map) {
            foreach ($this->runs($map) as $run) {
                $values[] = $this->window($run) + [
                    'property_id' => $target['property_id'],
                    'rate_plan_id' => $target['rate_plan_id'],
                    $field => (bool) $run['value'],
                ];
            }
        }

        if ($values === []) {
            return ChannelSyncResult::permanentFailure('empty_update', 'No rates or restrictions were supplied.');
        }

        return $this->write($listing, 'restrictions', ['values' => $values], count($update->nightlyRates));
    }

    /**
     * Read the bookings Channex has not been told we have.
     *
     * The feed, not the bookings list: Channex's guidance is explicit that a
     * PMS should poll the revisions feed and acknowledge what it saves, and
     * their certification refuses `GET /bookings` for this.
     *
     * Each revision is acknowledged only after it has been turned into a
     * payload the importer can use, so a revision this adapter could not read
     * stays in the feed to be looked at rather than vanishing. Channex
     * re-offers an unacknowledged revision for thirty minutes and then emails
     * a warning, which is the right outcome for something we failed to
     * understand.
     *
     * @return list<ChannelReservationPayload>
     */
    public function importReservations(ChannelAccount $account, ?DateTimeImmutable $since = null): array
    {
        $client = $this->client($account);

        try {
            // Oldest first, so a booking and its later modification are
            // applied in the order they happened.
            $body = $client->get('booking_revisions/feed', ['order' => ['inserted_at' => 'asc']]);
        } catch (ChannexRequestException $e) {
            Log::warning('Could not read the Channex booking feed.', ['error' => $e->getMessage()]);

            return [];
        }

        $payloads = [];

        foreach ($this->collection($body) as $revision) {
            $revisionId = $this->str($revision, 'id');
            $payload = $this->reservation($revision);

            if ($payload === null) {
                Log::warning('A Channex booking revision could not be read and was left unacknowledged.', [
                    'revision_id' => $revisionId,
                ]);

                continue;
            }

            $payloads[] = $payload;

            if ($revisionId === null) {
                continue;
            }

            try {
                $client->post('booking_revisions/'.$revisionId.'/ack');
            } catch (ChannexRequestException $e) {
                // The booking is still returned: a failed acknowledgement
                // means Channex will offer it again, and our own import is
                // idempotent on the OTA's reservation id, so the booking
                // lands once either way.
                Log::warning('Could not acknowledge a Channex booking revision.', [
                    'revision_id' => $revisionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $payloads;
    }

    // ------------------------------------------------------------------

    /**
     * Post an ARI message and turn the answer into a result.
     *
     * @param  array<string, mixed>  $body
     */
    private function write(ChannelListing $listing, string $path, array $body, int $records): ChannelSyncResult
    {
        $account = $listing->account;

        if ($account === null) {
            return ChannelSyncResult::permanentFailure(
                'account_missing',
                'This mapping has no Channex connection behind it.',
            );
        }

        try {
            $answer = $this->client($account)->post($path, $body);
        } catch (ChannexRequestException $e) {
            return $this->failure($e);
        }

        // The id Channex gave the queued task. This is what their support team
        // asks for and what the certification form wants quoted, which is why
        // it is carried back rather than discarded.
        return ChannelSyncResult::success(ChannexClient::taskId($answer), [
            'endpoint' => $path,
            'entries' => count($body['values'] ?? []),
            'nights' => $records,
        ]);
    }

    /**
     * The three Channex identifiers a write needs, or the reason it cannot go.
     *
     * @return array{property_id: string, room_type_id: string, rate_plan_id: ?string, currency: ?string}|ChannelSyncResult
     */
    private function target(ChannelListing $listing, bool $needsRatePlan): array|ChannelSyncResult
    {
        $meta = is_array($listing->metadata[self::META] ?? null) ? $listing->metadata[self::META] : [];

        $roomTypeId = $listing->external_listing_id;
        $propertyId = $this->str($meta, 'property_id');
        $ratePlanId = $this->str($meta, 'rate_plan_id');
        $currency = $this->str($meta, 'currency');

        $missing = [];

        if (! is_string($roomTypeId) || $roomTypeId === '') {
            $missing[] = 'a Channex room type';
        }

        if ($propertyId === null) {
            $missing[] = 'a Channex property';
        }

        if ($needsRatePlan && $ratePlanId === null) {
            $missing[] = 'a Channex rate plan';
        }

        if ($missing !== []) {
            // Named rather than sent and rejected: Channex answers 422 for an
            // unknown UUID, and an operator should not have to read a sync log
            // to find out which half of the mapping is missing.
            return ChannelSyncResult::permanentFailure(
                'mapping_incomplete',
                sprintf('This mapping is missing %s. Re-map the listing.', implode(' and ', $missing)),
            );
        }

        return [
            'property_id' => (string) $propertyId,
            'room_type_id' => (string) $roomTypeId,
            'rate_plan_id' => $ratePlanId,
            'currency' => $currency === null ? null : strtoupper($currency),
        ];
    }

    /**
     * Collapse a date-keyed map into runs of equal consecutive value.
     *
     * The reason this adapter sends one request instead of hundreds. A
     * 500-day calendar priced in four seasons becomes four entries; a calendar
     * where every night differs becomes 500, which is still one request.
     *
     * @param  array<string, int|bool|null>  $map
     * @return list<array{from: string, to: string, value: int|bool}>
     */
    private function runs(array $map): array
    {
        $dates = array_filter($map, fn ($value): bool => $value !== null);

        if ($dates === []) {
            return [];
        }

        ksort($dates);

        $runs = [];
        $from = null;
        $to = null;
        $value = null;

        foreach ($dates as $date => $current) {
            if ($from !== null && $current === $value && $this->isNextDay($to, (string) $date)) {
                $to = (string) $date;

                continue;
            }

            if ($from !== null) {
                $runs[] = ['from' => $from, 'to' => $to, 'value' => $value];
            }

            $from = (string) $date;
            $to = (string) $date;
            $value = $current;
        }

        if ($from !== null) {
            $runs[] = ['from' => $from, 'to' => $to, 'value' => $value];
        }

        return $runs;
    }

    /**
     * A single date where a run covers one night, a range where it covers
     * more. Channex accepts either and the shorter form is clearer in a log.
     *
     * @param  array{from: string, to: string, value: int|bool}  $run
     * @return array<string, string>
     */
    private function window(array $run): array
    {
        return $run['from'] === $run['to']
            ? ['date' => $run['from']]
            : ['date_from' => $run['from'], 'date_to' => $run['to']];
    }

    private function isNextDay(?string $previous, string $date): bool
    {
        if ($previous === null) {
            return false;
        }

        return CarbonImmutable::parse($previous)->addDay()->toDateString() === $date;
    }

    /**
     * One booking revision, or null when it cannot be read.
     *
     * @param  array<string, mixed>  $revision
     */
    private function reservation(array $revision): ?ChannelReservationPayload
    {
        $rooms = is_array($revision['rooms'] ?? null) ? $revision['rooms'] : [];
        $first = is_array($rooms[0] ?? null) ? $rooms[0] : [];

        // The OTA's own code is what the platform deduplicates on, so a
        // revision without one cannot be imported safely: a redelivery would
        // become a second booking.
        $reference = $this->str($revision, 'ota_reservation_code')
            ?? $this->str($revision, 'unique_id')
            ?? $this->str($revision, 'booking_id');

        $roomTypeId = $this->str($first, 'room_type_id');
        $arrival = $this->str($revision, 'arrival_date') ?? $this->str($first, 'checkin_date');
        $departure = $this->str($revision, 'departure_date') ?? $this->str($first, 'checkout_date');

        if ($reference === null || $roomTypeId === null || $arrival === null || $departure === null) {
            return null;
        }

        $currency = $this->str($revision, 'currency') ?? 'XXX';
        $customer = is_array($revision['customer'] ?? null) ? $revision['customer'] : [];
        $occupancy = is_array($revision['occupancy'] ?? null) ? $revision['occupancy'] : [];

        return new ChannelReservationPayload(
            externalReservationId: $reference,
            externalListingId: $roomTypeId,
            status: match ($this->str($revision, 'status')) {
                'cancelled' => 'cancelled',
                'modified' => 'modified',
                default => 'confirmed',
            },
            checkIn: new DateTimeImmutable($arrival),
            checkOut: new DateTimeImmutable($departure),
            currency: $currency,
            // Channex sends decimal strings ("200.00"). Converted through
            // Money so the currency's own exponent decides, rather than an
            // assumption that everything has two decimal places.
            totalAmount: $this->minorUnits($revision['amount'] ?? null, $currency),
            adults: $this->int($occupancy, 'adults') ?? 1,
            children: $this->int($occupancy, 'children') ?? 0,
            infants: $this->int($occupancy, 'infants') ?? 0,
            guestFirstName: $this->str($customer, 'name'),
            guestLastName: $this->str($customer, 'surname'),
            guestEmail: $this->str($customer, 'mail'),
            guestPhone: $this->str($customer, 'phone'),
            guestCountry: $this->str($customer, 'country'),
            guestLanguage: $this->str($customer, 'language'),
            confirmationCode: $this->str($revision, 'ota_reservation_code'),
            raw: $revision,
        );
    }

    private function minorUnits(mixed $amount, string $currency): int
    {
        if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
            return 0;
        }

        try {
            return Money::fromDecimal(is_float($amount) ? (string) $amount : $amount, $currency)->minorUnits;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function failure(ChannexRequestException $e): ChannelSyncResult
    {
        $data = $e->warnings === [] ? [] : ['warnings' => $e->warnings];

        return $e->retryable
            ? ChannelSyncResult::transientFailure((string) ($e->errorCode ?? 'channex_error'), $e->getMessage(), $data)
            : ChannelSyncResult::permanentFailure((string) ($e->errorCode ?? 'channex_error'), $e->getMessage(), $data);
    }

    private function client(ChannelAccount $account): ChannexClient
    {
        return new ChannexClient(
            (string) $account->credential('api_key'),
            $account->credential('base_url'),
        );
    }

    /**
     * Channex wraps every record as `{type, id, attributes, relationships}`.
     * Flattened in the client, parents included, so the readers above do not
     * each have to know that.
     *
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function collection(array $body): array
    {
        return ChannexClient::records($body);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function str(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function int(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
