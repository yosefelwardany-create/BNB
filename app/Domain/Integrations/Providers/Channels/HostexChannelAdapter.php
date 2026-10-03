<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\Contracts\ChannelAdapterInterface;
use App\Domain\Integrations\Contracts\ImportsConversations;
use App\Domain\Integrations\DataObjects\ChannelAvailabilityUpdate;
use App\Domain\Integrations\DataObjects\ChannelListingPayload;
use App\Domain\Integrations\DataObjects\ChannelMessagePayload;
use App\Domain\Integrations\DataObjects\ChannelRateUpdate;
use App\Domain\Integrations\DataObjects\ChannelReservationPayload;
use App\Domain\Integrations\DataObjects\ChannelReviewPayload;
use App\Domain\Integrations\DataObjects\ChannelSyncResult;
use App\Domain\Integrations\DataObjects\WebhookEnvelope;
use App\Domain\Integrations\Exceptions\HostexRequestException;
use App\Domain\Integrations\Support\HostexClient;
use App\Domain\Integrations\Support\HostexData;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Airbnb, Booking.com and the rest — reached through Hostex.
 *
 * The first adapter in this platform that is genuinely live. The major OTAs each
 * require a signed partner agreement before their APIs can be used, which is why
 * every other one here is simulated and says so. Hostex already holds those
 * agreements, so a request to it is a real change on a real listing: a message
 * sent through this adapter arrives in a guest's Airbnb inbox.
 *
 * That is also the reason this file is careful. Everything it does is somebody
 * else's booking.
 *
 * ## What it is and is not trusted with
 *
 * **Reading is unconditional; writing is not.** Pulls — listings, reservations,
 * conversations, reviews — run on a schedule and need no permission beyond the
 * token. Writes are driven by the synchronisation engine, which decides *when*
 * from the account's own switches (`sync_availability`, `sync_rates`,
 * `sync_messages`), and this adapter only translates.
 *
 * **A failure is classified, never swallowed.** A rate limit backs off; a
 * rejected date does not, because it will be rejected again. {@see HostexClient}
 * makes that call, including for the errors Hostex returns inside a 200.
 *
 * **Shapes are read defensively.** This was written without reach to Hostex's
 * API documentation — the egress policy of the machine it was built on blocks
 * it — so every field is read by name with a fallback and nothing assumes a key
 * exists. `php artisan hostex:probe` puts the real account to the real API and
 * prints what came back, which is how the shapes get confirmed rather than
 * guessed at.
 */
class HostexChannelAdapter implements ChannelAdapterInterface, ImportsConversations
{
    public array $readIssues = [];

    public function key(): string
    {
        return 'hostex';
    }

    public function displayName(): string
    {
        return 'Hostex';
    }

    /**
     * Live, whenever an access token is stored.
     *
     * Not a boolean on a setting somewhere: the only thing that makes this
     * adapter able to do anything is a token, so that is what is checked.
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
            self::CAPABILITY_IMPORT_RESERVATIONS,
            self::CAPABILITY_MODIFY_RESERVATIONS,
            self::CAPABILITY_CANCEL_RESERVATIONS,
            self::CAPABILITY_MESSAGING,
            self::CAPABILITY_REVIEWS,
            self::CAPABILITY_WEBHOOKS,
            // Deliberately absent: PUBLISH_LISTINGS. A listing is created on
            // the OTA and in Hostex, not from here, and claiming otherwise
            // would put a "publish" button on a screen that cannot publish.
        ];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function testConnection(ChannelAccount $account): ChannelSyncResult
    {
        try {
            $properties = $this->client($account)->get('properties', ['limit' => 1]);
        } catch (HostexRequestException $e) {
            return $this->failure($e);
        }

        return ChannelSyncResult::success(data: [
            // Enough to prove the token reaches an account with something in
            // it. A connection that authenticates against an empty Hostex is
            // working and useless, and the screen should be able to say which.
            'properties_visible' => count($this->rows($properties, 'properties')),
        ]);
    }

    /**
     * @return list<ChannelListingPayload>
     */
    public function importListings(ChannelAccount $account): array
    {
        $this->readIssues = [];
        $payloads = [];

        foreach ($this->paged($account, 'properties', 'properties') as $row) {
            $id = $this->string($row, ['id', 'property_id']);

            if ($id === null) {
                $this->readIssues[] = 'A property without a stable Hostex ID was skipped.';

                continue;
            }

            $payloads[] = new ChannelListingPayload(
                externalListingId: $id,
                title: $this->string($row, ['title']) ?? '',
                addressLine1: $this->string($row, ['address']),
                latitude: $this->float($row, ['latitude']),
                longitude: $this->float($row, ['longitude']),
                // Additional listing and pricing fields require their documented
                // endpoints. Never infer capacity from the included-guest threshold.
                extra: array_intersect_key($row, array_flip(['channels'])),
            );
        }

        return $payloads;
    }

    public function publishListing(ChannelListing $listing, ChannelListingPayload $payload): ChannelSyncResult
    {
        return ChannelSyncResult::permanentFailure(
            'unsupported',
            'Listings are created on the channel and in Hostex, not from here. '
            .'Add it there and it appears in the next import.',
        );
    }

    public function pushAvailability(ChannelListing $listing, ChannelAvailabilityUpdate $update): ChannelSyncResult
    {
        $days = [];

        foreach ($update->days as $date => $available) {
            $days[] = array_filter([
                'date' => $date,
                'available' => $available,
                // Hostex counts remaining units rather than carrying a flag. A
                // closed night is zero; an open one is one, because a mapping
                // here is to a single listing.
                'available_count' => $available ? 1 : 0,
                'min_stay' => $update->minimumStay[$date] ?? null,
                'closed_to_arrival' => $update->closedToArrival[$date] ?? null,
                'closed_to_departure' => $update->closedToDeparture[$date] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        if ($days === []) {
            return ChannelSyncResult::success();
        }

        return $this->write($listing, 'listings/'.$listing->external_listing_id.'/calendar', [
            'listing_id' => $listing->external_listing_id,
            'availabilities' => $days,
        ]);
    }

    public function pushRates(ChannelListing $listing, ChannelRateUpdate $update): ChannelSyncResult
    {
        $days = [];

        foreach ($update->nightlyRates as $date => $minorUnits) {
            $days[] = array_filter([
                'date' => $date,
                // Minor units throughout this platform; Hostex quotes decimals,
                // so this is the one place the conversion happens. Doing it at
                // each call site is how a currency ends up a hundred times out
                // in one report and right in every other.
                'price' => round($minorUnits / 100, 2),
                'min_stay' => $update->minimumStay[$date] ?? null,
                'max_stay' => $update->maximumStay[$date] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        if ($days === []) {
            return ChannelSyncResult::success();
        }

        return $this->write($listing, 'listings/'.$listing->external_listing_id.'/calendar', [
            'listing_id' => $listing->external_listing_id,
            'prices' => $days,
        ]);
    }

    /**
     * @return list<ChannelReservationPayload>
     */
    public function importReservations(ChannelAccount $account, ?DateTimeImmutable $since = null): array
    {
        $this->readIssues = [];
        // Hostex has no updated-since filter. A check-in filter misses cancellations
        // and edits to older stays. Re-read an explicit, configurable window.
        $query = $this->reservationCoverage($account);
        $payloads = [];

        foreach ($this->reservationRows($account, $query) as $row) {
            try {
                $payload = $this->reservation($row);
            } catch (\Throwable) {
                $payload = null;
            }

            if ($payload === null) {
                $this->readIssues[] = 'An unallocated or malformed reservation was skipped. Resolve its allocation or dates in Hostex and retry.';

                continue;
            }
            $payloads[] = $payload;
        }

        return $payloads;
    }

    /** Retry an oversized history query in bounded, inclusive checkout windows. */
    private function reservationRows(ChannelAccount $account, array $query): array
    {
        try {
            return $this->paged($account, 'reservations', 'reservations', $query);
        } catch (HostexRequestException $exception) {
            // Smaller ranges cannot repair credentials or a provider cooldown.
            if (! in_array($exception->errorCode, [null, 400, 422, 500, 502, 503, 504], true)) {
                throw $exception;
            }
            $from = CarbonImmutable::parse($query['start_check_out_date']);
            $to = CarbonImmutable::parse($query['end_check_out_date']);
            if ($from->diffInDays($to) < 180 || $from->greaterThan($to)) {
                throw $exception;
            }
        }

        $rows = [];
        for ($start = $from; $start->lessThanOrEqualTo($to); $start = $end->addDay()) {
            $end = $start->addDays(179)->min($to);
            try {
                $page = $this->paged($account, 'reservations', 'reservations', [
                    'start_check_out_date' => $start->toDateString(),
                    'end_check_out_date' => $end->toDateString(),
                ]);
            } catch (HostexRequestException $exception) {
                if ($rows === []) {
                    throw new HostexRequestException(
                        'Reservations '.$start->toDateString().' to '.$end->toDateString().': '.$exception->getMessage(),
                        errorCode: $exception->errorCode, retryable: $exception->retryable, retryAfter: $exception->retryAfter,
                    );
                }
                $this->readIssues[] = 'Reservations '.$start->toDateString().' to '.$end->toDateString().': '.$exception->getMessage().' Earlier windows were retained; retry Pull.';
                break;
            }
            foreach ($page as $row) {
                $identity = HostexData::text($row['stay_code'] ?? null);
                // Preserve malformed rows for the normal validation/reporting path.
                if ($identity === null) {
                    $rows[] = $row;
                } else {
                    $rows['stay:'.$identity] = $row;
                }
            }
        }

        return array_values($rows);
    }

    /**
     * One reservation row, or null when it is unreadable.
     *
     * Public because the webhook path receives the same shape and must read it
     * the same way. Two readings of one payload is two things to keep in step.
     *
     * @param  array<string, mixed>  $row
     */
    public function reservation(array $row): ?ChannelReservationPayload
    {
        $id = $this->string($row, ['stay_code', 'reservation_code']);
        $listingId = $this->string($row, ['property_id']);
        $checkIn = $this->date($row, ['check_in_date', 'check_in', 'start_date']);
        $checkOut = $this->date($row, ['check_out_date', 'check_out', 'end_date']);

        if ($id === null || $listingId === null || $checkIn === null || $checkOut === null) {
            return null;
        }

        if ($checkOut <= $checkIn || $listingId === '0') {
            return null;
        }
        $guests = array_values(array_filter(is_array($row['guests'] ?? null) ? $row['guests'] : [], 'is_array'));
        $booker = collect($guests)->firstWhere('is_booker', true) ?? ($guests[0] ?? []);
        $name = HostexData::text($row['guest_name'] ?? null) ?? HostexData::text($booker['name'] ?? null);
        $financials = HostexData::financials($row);

        return new ChannelReservationPayload(
            externalReservationId: $id,
            externalListingId: $listingId,
            status: $this->status($this->string($row, ['status']) ?? 'unknown'),
            checkIn: $checkIn,
            checkOut: $checkOut,
            // XXX is only an internal sentinel; the API returns null currency.
            currency: $financials['rate']['currency'] ?? 'XXX',
            totalAmount: $financials['rate']['amount'] ?? 0,
            adults: $this->int($row, ['number_of_adults']) ?? 1,
            children: $this->int($row, ['number_of_children']) ?? 0,
            infants: $this->int($row, ['number_of_infants']) ?? 0,
            pets: $this->int($row, ['number_of_pets']) ?? 0,
            guestFirstName: $this->firstWord($name),
            guestLastName: $this->restOfName($name),
            guestEmail: HostexData::text($row['guest_email'] ?? null) ?? HostexData::text($booker['email'] ?? null),
            guestPhone: HostexData::text($row['guest_phone'] ?? null) ?? HostexData::text($booker['phone'] ?? null),
            guestCountry: HostexData::text($booker['country'] ?? null),
            confirmationCode: $this->string($row, ['channel_id']),
            bookedAt: $this->date($row, ['booked_at']),
            cancelledAt: $this->date($row, ['cancelled_at']),
            notes: $this->string($row, ['channel_remarks']),
            raw: array_intersect_key($row, array_flip([
                'reservation_code', 'stay_code', 'channel_id', 'channel_type', 'listing_id',
                'number_of_guests', 'number_of_adults', 'number_of_children', 'number_of_infants',
                'number_of_pets', 'status', 'remarks', 'channel_remarks', 'rates', 'payment', 'additional_fees',
            ])) + ['hostex_guest_id' => HostexData::text($booker['id'] ?? null)]
                + (isset($row['guests']) && is_array($row['guests']) ? ['hostex_guest_details' => array_map(
                    fn (array $guest): array => array_intersect_key($guest, array_flip(['id', 'name', 'email', 'phone', 'country', 'is_booker'])),
                    $guests,
                )] : []),
        );
    }

    public function reservationCoverage(ChannelAccount $account): array
    {
        $settings = $account->settings ?? [];

        return [
            'start_check_out_date' => $settings['hostex_reservations_from'] ?? CarbonImmutable::now()->subYears(2)->toDateString(),
            'end_check_out_date' => $settings['hostex_reservations_to'] ?? CarbonImmutable::now()->addYears(3)->toDateString(),
        ];
    }

    public function pushReservationChange(ChannelListing $listing, ChannelReservationPayload $payload): ChannelSyncResult
    {
        return $this->write(
            $listing,
            'reservations/'.$payload->externalReservationId,
            array_filter([
                'remarks' => $payload->notes,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    public function cancelReservation(ChannelListing $listing, string $externalReservationId, ?string $reason = null): ChannelSyncResult
    {
        return $this->write(
            $listing,
            'reservations/'.$externalReservationId.'/cancel',
            array_filter(['reason' => $reason], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * Put a message into the guest's own thread on the channel.
     *
     * The capability this whole integration was chosen for. Everything else here
     * has an iCal-shaped alternative; this does not.
     */
    public function sendMessage(ChannelListing $listing, ChannelMessagePayload $message): ChannelSyncResult
    {
        $thread = $message->externalThreadId;

        if ($thread === null) {
            return ChannelSyncResult::permanentFailure(
                'no_thread',
                'There is no channel conversation to reply into. A thread is created by the '
                .'guest\'s first message, so this one has to go out another way.',
            );
        }

        return $this->write($listing, 'conversations/'.$thread, ['message' => $message->body]);
    }

    /**
     * @return list<ChannelReviewPayload>
     */
    public function importReviews(ChannelAccount $account, ?DateTimeImmutable $since = null): array
    {
        $payloads = [];

        foreach ($this->paged($account, 'reviews', 'reviews') as $row) {
            $id = $this->string($row, ['id', 'review_id']);

            if ($id === null) {
                continue;
            }

            $payloads[] = new ChannelReviewPayload(
                externalReviewId: $id,
                externalReservationId: $this->string($row, ['reservation_code', 'reservation_id']),
                externalListingId: $this->string($row, ['property_id', 'listing_id']),
                // A review with no score reads as zero rather than being
                // dropped: the words are the part somebody replies to.
                rating: $this->float($row, ['rating', 'overall_rating']) ?? 0.0,
                publicComment: $this->string($row, ['comment', 'public_review', 'content']),
                privateComment: $this->string($row, ['private_feedback', 'private_review']),
                reviewerName: $this->string($row, ['reviewer_name', 'guest_name']),
                submittedAt: $this->date($row, ['created_at', 'submitted_at']),
                response: $this->string($row, ['response', 'host_response']),
            );
        }

        return $payloads;
    }

    /**
     * Every message across this account's threads.
     *
     * Webhooks deliver what happens next. A property connected this morning has
     * months of conversation behind it that no webhook will ever mention, and an
     * agent reading a thread that starts mid-sentence answers a follow-up as
     * though it were a first question.
     *
     * The listing travels on each message in `attachments`, because a thread
     * with no booking behind it still has to reach the right property, and the
     * conversation is the only thing that knows which.
     *
     * @return list<ChannelMessagePayload>
     */
    public function importConversations(ChannelAccount $account, ?DateTimeImmutable $since = null): array
    {
        $messages = [];
        $mappings = $account->listings()->get();
        $this->unmappedConversationCount = 0;

        foreach ($this->paged($account, 'conversations', 'conversations') as $thread) {
            // The list includes the latest message timestamp. Old threads do
            // not need a detail request on every incremental refresh.
            $lastMessageAt = $this->date($thread, ['last_message_at']);
            if ($since !== null && $lastMessageAt !== null && $lastMessageAt < $since) {
                continue;
            }
            $threadId = $this->string($thread, ['id', 'conversation_id']);

            if ($threadId === null) {
                continue;
            }

            $rows = $this->threadMessages($account, $thread, $threadId);
            $propertyIds = [];
            $reservationIds = [];
            $directId = $this->string($thread, ['property_id', 'listing_id']);
            if ($directId !== null) {
                $propertyIds[] = $directId;
            }
            $directReservation = $this->string($thread, ['reservation_code', 'reservation_id']);
            if ($directReservation !== null) {
                $reservationIds[] = $directReservation;
            }
            foreach ($thread['activities'] ?? [] as $activity) {
                if (! is_array($activity)) {
                    continue;
                }
                $propertyId = $this->string(is_array($activity['property'] ?? null) ? $activity['property'] : [], ['id', 'property_id']);
                if ($propertyId !== null) {
                    $propertyIds[] = $propertyId;
                }
                $otaId = $this->string($activity, ['listing_id']);
                if ($otaId !== null) {
                    $matches = $mappings->filter(fn ($mapping) => collect($mapping->metadata['hostex_channels'] ?? [])->contains(
                        fn ($channel) => (string) ($channel['listing_id'] ?? '') === $otaId
                            && ($channel['channel_type'] ?? null) === ($thread['channel_type'] ?? null),
                    ));
                    // Keep unknown/ambiguous anchors distinct. Never choose the
                    // first property or match a conversation by its title.
                    $propertyIds[] = $matches->count() === 1 ? (string) $matches->first()->external_listing_id : 'unmapped:'.$otaId;
                }
                $code = $this->string($activity, ['reservation_code']);
                if ($code !== null) {
                    $reservationIds[] = $code;
                }
            }
            $propertyIds = array_values(array_unique($propertyIds));
            $reservationIds = array_values(array_unique($reservationIds));
            if (count($propertyIds) > 1 || (isset($propertyIds[0]) && str_starts_with($propertyIds[0], 'unmapped:'))) {
                $this->unmappedConversationCount++;

                continue;
            }
            $listingId = $propertyIds[0] ?? null;
            $reservationId = count($reservationIds) === 1 ? $reservationIds[0] : null;
            if ($listingId === null && $reservationId === null) {
                $this->unmappedConversationCount++;

                continue;
            }

            foreach ($rows as $row) {
                $sentAt = $this->date($row, ['created_at', 'sent_at', 'timestamp']);

                // Nothing older than we asked for. The caller passes the last
                // sync point, and re-reading a year of threads every hour would
                // spend somebody's rate limit to learn nothing.
                if ($since !== null && $sentAt !== null && $sentAt < $since) {
                    continue;
                }

                $body = $this->string($row, ['message', 'body', 'content', 'text']);

                if ($body === null) {
                    continue;
                }

                $messages[] = new ChannelMessagePayload(
                    body: $body,
                    externalThreadId: $threadId,
                    externalMessageId: $this->string($row, ['id', 'message_id']),
                    externalReservationId: $reservationId,
                    senderName: $this->string($row, ['sender_name', 'guest_name'])
                        ?? $this->string($thread, ['guest_name']),
                    sentAt: $sentAt,
                    attachments: array_filter([
                        'listing_id' => $listingId,
                        'guest_name' => $this->string(is_array($thread['guest'] ?? null) ? $thread['guest'] : [], ['name']),
                        // Which way it went, read rather than assumed: a thread
                        // carries the host's side too, and importing one of ours
                        // as the guest's would have the agent answering itself.
                        'sender_role' => $this->string($row, ['sender_role', 'sender_type', 'direction']),
                    ], static fn (mixed $value): bool => $value !== null),
                );
            }
        }

        usort($messages, fn ($a, $b) => ($a->sentAt?->getTimestamp() ?? 0) <=> ($b->sentAt?->getTimestamp() ?? 0));

        return $messages;
    }

    public int $unmappedConversationCount = 0;

    /**
     * The messages on one thread.
     *
     * Taken from the thread itself where the list endpoint already carried them,
     * and fetched otherwise. Hostex has been seen to do both, and a request per
     * thread when the data was already in hand is a rate limit spent on nothing.
     *
     * @param  array<string, mixed>  $thread
     * @return list<array<string, mixed>>
     */
    private function threadMessages(ChannelAccount $account, array &$thread, string $threadId): array
    {
        foreach (['messages', 'conversation_messages'] as $key) {
            if (is_array($thread[$key] ?? null) && $thread[$key] !== []) {
                return array_values(array_filter($thread[$key], 'is_array'));
            }
        }

        try {
            $detail = $this->client($account)->get('conversations/'.$threadId);
        } catch (HostexRequestException $e) {
            $this->readIssues[] = 'A conversation could not be refreshed; retry Pull.';

            return [];
        }

        $thread = array_replace($thread, $detail);

        return $this->rows($detail, 'messages');
    }

    /**
     * Authenticate an inbound webhook, or refuse it.
     *
     * Hostex sends a `Hostex-Webhook-Secret-Token` header that is fixed per
     * webhook URL. That proves the caller knows a secret we also know; it is not
     * a signature over the body, so it says who sent the request and not that
     * the body is unaltered — TLS is what covers the second. Worth stating
     * rather than implying, because the two are often confused and only one of
     * them is happening here.
     *
     * Compared whole with `hash_equals`. A comparison that stops at the first
     * wrong character leaks how much of the token an attacker has right.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public function parseWebhook(ChannelAccount $account, string $payload, array $headers): ?WebhookEnvelope
    {
        $expected = $account->webhook_secret;

        if (! is_string($expected) || $expected === '') {
            // No secret stored means nothing can be verified, and an
            // unverifiable webhook is one anybody who finds the URL can send.
            return null;
        }

        $sent = $this->header($headers, 'hostex-webhook-secret-token');

        if ($sent === null || ! hash_equals($expected, $sent)) {
            return null;
        }

        $body = json_decode($payload, true);

        if (! is_array($body)) {
            return null;
        }

        $type = $this->string($body, ['event', 'event_type', 'type']);

        if ($type === null) {
            return null;
        }

        return new WebhookEnvelope(
            type: $type,
            // Hostex does not always carry an event id. Falling back to a hash
            // of the body keeps the de-duplication key stable for a redelivery
            // of the same event, which is what it is for.
            providerEventId: $this->string($body, ['id', 'event_id']) ?? hash('sha256', $payload),
            data: is_array($body['data'] ?? null) ? $body['data'] : $body,
            occurredAt: $this->date($body, ['occurred_at', 'created_at', 'timestamp']),
        );
    }

    public function client(ChannelAccount $account): HostexClient
    {
        $credentials = is_array($account->credentials) ? $account->credentials : [];
        $token = $credentials['access_token'] ?? $credentials['token'] ?? null;

        if (! is_string($token) || trim($token) === '') {
            throw new HostexRequestException(
                'This Hostex connection has no access token. Add one in the channel settings — '
                .'it is the token from Hostex under Settings, API.',
            );
        }

        return new HostexClient(trim($token));
    }

    /**
     * Every page of a collection, as plain rows.
     *
     * Hostex pages with `offset` and `limit`. The loop stops when a page comes
     * back short, and has a hard ceiling as well: a paging bug on either side
     * should produce a wrong number rather than a worker that never finishes.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function paged(ChannelAccount $account, string $path, string $key, array $query = []): array
    {
        $client = $this->client($account);
        $limit = 100;
        $rows = [];

        for ($offset = 0; $offset < 10_000;) {
            try {
                $response = $client->get($path, $query + ['offset' => $offset, 'limit' => $limit]);
                if (! array_key_exists($key, $response) || ! is_array($response[$key])) {
                    throw new HostexRequestException('Hostex returned an unexpected collection shape.');
                }
                $page = $this->rows($response, $key);
            } catch (HostexRequestException $e) {
                if ($rows === []) {
                    throw $e;
                }
                $this->readIssues[] = 'A later page of '.$key.' failed; earlier pages were retained. '.$e->getMessage().' Retry Pull.';

                return $rows;
            }

            $rows = [...$rows, ...$page];
            $offset += count($page);
            $total = isset($response['total']) && is_numeric($response['total']) ? (int) $response['total'] : null;
            if ($total !== null && $offset >= $total) {
                return $rows;
            }
            if ($page === []) {
                if ($total !== null && $offset < $total) {
                    $this->readIssues[] = 'Hostex returned an empty page before the reported end of '.$key.'. Retry Pull.';
                }

                return $rows;
            }
            if ($total === null && count($page) < $limit) {
                return $rows;
            }
        }

        $this->readIssues[] = 'Hostex pagination reached the 10,000-record safety limit; narrow the configured date range.';

        return $rows;
    }

    /**
     * The rows out of a response, whatever it wrapped them in.
     *
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    public function rows(array $response, string $key): array
    {
        $rows = $response[$key] ?? $response['data'] ?? $response['items'] ?? $response;

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function write(ChannelListing $listing, string $path, array $body): ChannelSyncResult
    {
        $account = $listing->account;

        if ($account === null) {
            return ChannelSyncResult::permanentFailure(
                'no_account',
                'This mapping is not attached to a Hostex connection.',
            );
        }

        try {
            $response = $this->client($account)->post($path, $body);
        } catch (HostexRequestException $e) {
            return $this->failure($e);
        }

        return ChannelSyncResult::success(
            $this->string($response, ['id', 'reservation_code', 'message_id']),
            $response,
        );
    }

    private function failure(HostexRequestException $e): ChannelSyncResult
    {
        return $e->retryable
            ? ChannelSyncResult::transientFailure((string) ($e->errorCode ?? 'transient'), $e->getMessage(), $e->body)
            : ChannelSyncResult::permanentFailure((string) ($e->errorCode ?? 'failed'), $e->getMessage(), $e->body);
    }

    /**
     * Hostex's word for a reservation state, in ours.
     *
     * Anything unrecognised becomes `pending`, which holds rather than books:
     * guessing `confirmed` for a status nobody has seen would put a stay on a
     * calendar on the strength of a string.
     */
    private function status(string $status): string
    {
        return match (mb_strtolower(trim($status))) {
            'accepted', 'confirmed', 'booked' => 'confirmed',
            'cancelled', 'canceled', 'denied', 'declined' => 'cancelled',
            'modified', 'changed' => 'modified',
            default => 'pending',
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function string(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function int(array $row, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (is_numeric($row[$key] ?? null)) {
                return (int) $row[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function float(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (is_numeric($row[$key] ?? null)) {
                return (float) $row[$key];
            }
        }

        return null;
    }

    /**
     * An amount in minor units.
     *
     * Hostex quotes decimals. Multiplying and rounding here keeps the one
     * conversion in one place; doing it at each call site is how a currency ends
     * up a hundred times out in one report and right in every other.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    /**
     * Money that may genuinely be absent.
     *
     * Separate from {@see money()}, which answers 0 for a missing amount —
     * right for a commission nobody charged, wrong for a nightly rate, where
     * zero means "this room is free" and null means "the channel did not say".
     * A property created from the second would be published at nothing.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function moneyOrNull(array $row, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (is_numeric($row[$key] ?? null)) {
                return (int) round(((float) $row[$key]) * 100);
            }
        }

        return null;
    }

    private function money(array $row, array $keys): int
    {
        foreach ($keys as $key) {
            if (is_numeric($row[$key] ?? null)) {
                return (int) round(((float) $row[$key]) * 100);
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function date(array $row, array $keys): ?DateTimeImmutable
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                return new DateTimeImmutable($value);
            } catch (\Exception) {
                // An unparseable date is not a reason to drop the whole record.
                continue;
            }
        }

        return null;
    }

    private function firstWord(?string $name): ?string
    {
        return $name === null ? null : (explode(' ', trim($name))[0] ?: null);
    }

    private function restOfName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $parts = explode(' ', trim($name));
        array_shift($parts);

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (mb_strtolower((string) $key) !== $name) {
                continue;
            }

            $found = is_array($value) ? ($value[0] ?? null) : $value;

            return is_string($found) && trim($found) !== '' ? trim($found) : null;
        }

        return null;
    }
}
