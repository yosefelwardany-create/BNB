<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\DataObjects\ChannelAvailabilityUpdate;
use App\Domain\Integrations\DataObjects\ChannelListingPayload;
use App\Domain\Integrations\DataObjects\ChannelMessagePayload;
use App\Domain\Integrations\DataObjects\ChannelRateUpdate;
use App\Domain\Integrations\DataObjects\ChannelReservationPayload;
use App\Domain\Integrations\DataObjects\ChannelReviewPayload;
use App\Domain\Integrations\DataObjects\ChannelSyncResult;
use App\Domain\Integrations\DataObjects\WebhookEnvelope;
use Illuminate\Support\Str;

/**
 * Stands in for a major OTA until a commercial API agreement is in place.
 *
 * Every large OTA requires a signed partner agreement and certification before
 * its API can be used, so no working implementation can ship before that
 * exists. Rather than leave the channel machinery untested until then, this
 * adapter implements the same interface and exercises the whole path:
 * publishing a listing, pushing availability and rates, importing
 * reservations, verifying webhooks, and the retry classification the sync
 * engine depends on.
 *
 * It is explicit about what it is. `isLive()` returns false, and every screen
 * that shows a channel connection uses that flag to state that the channel is
 * simulated. Nothing in the product reports a simulated channel as connected.
 *
 * When a real adapter is written, it implements the same interface and is
 * registered under the same key; nothing else in the platform changes.
 */
class SimulatedOtaAdapter extends AbstractChannelAdapter
{
    public function __construct(
        private readonly string $channelKey,
        private readonly string $channelName,
    ) {}

    public function key(): string
    {
        return $this->channelKey;
    }

    public function displayName(): string
    {
        return $this->channelName.' (simulated)';
    }

    public function isLive(): bool
    {
        return false;
    }

    public function capabilities(): array
    {
        return [
            self::CAPABILITY_IMPORT_LISTINGS,
            self::CAPABILITY_PUBLISH_LISTINGS,
            self::CAPABILITY_AVAILABILITY,
            self::CAPABILITY_PRICING,
            self::CAPABILITY_RESTRICTIONS,
            self::CAPABILITY_IMPORT_RESERVATIONS,
            self::CAPABILITY_MODIFY_RESERVATIONS,
            self::CAPABILITY_CANCEL_RESERVATIONS,
            self::CAPABILITY_MESSAGING,
            self::CAPABILITY_REVIEWS,
            self::CAPABILITY_WEBHOOKS,
        ];
    }

    public function testConnection(ChannelAccount $account): ChannelSyncResult
    {
        if (blank($account->credential('api_key'))) {
            return ChannelSyncResult::permanentFailure(
                'missing_credentials',
                'No API key has been stored for this connection.',
            );
        }

        return ChannelSyncResult::success(data: [
            'simulated' => true,
            'message' => sprintf(
                'Connection validated against the simulated %s adapter. No request left this system.',
                $this->channelName,
            ),
        ]);
    }

    public function importListings(ChannelAccount $account): array
    {
        // Nothing is invented: with no external system there are no remote
        // listings to discover. Returning an empty set is the truthful answer
        // and keeps the mapping screen honest.
        return [];
    }

    public function publishListing(ChannelListing $listing, ChannelListingPayload $payload): ChannelSyncResult
    {
        if (trim($payload->title) === '') {
            return ChannelSyncResult::permanentFailure('title_required', 'A listing title is required.');
        }

        if ($payload->maxGuests !== null && $payload->maxGuests < 1) {
            return ChannelSyncResult::permanentFailure('invalid_occupancy', 'Maximum occupancy must be at least one.');
        }

        if ($payload->photoUrls === []) {
            // Every OTA rejects a listing with no photography; catching it
            // locally means the operator sees a useful message.
            return ChannelSyncResult::permanentFailure(
                'photos_required',
                sprintf('%s requires at least one photo before a listing can be published.', $this->channelName),
            );
        }

        return ChannelSyncResult::success(
            $listing->external_listing_id ?? $this->externalId('lst'),
            ['simulated' => true],
        );
    }

    public function pushAvailability(ChannelListing $listing, ChannelAvailabilityUpdate $update): ChannelSyncResult
    {
        if ($update->days === []) {
            return ChannelSyncResult::permanentFailure('empty_update', 'No dates were supplied.');
        }

        return ChannelSyncResult::success($listing->external_listing_id, [
            'simulated' => true,
            'nights' => count($update->days),
            'from' => $update->from->format('Y-m-d'),
            'to' => $update->to->format('Y-m-d'),
        ]);
    }

    public function pushRates(ChannelListing $listing, ChannelRateUpdate $update): ChannelSyncResult
    {
        foreach ($update->nightlyRates as $date => $minorUnits) {
            if ($minorUnits <= 0) {
                // A zero or negative rate is rejected by every channel; the
                // platform should never send one.
                return ChannelSyncResult::permanentFailure(
                    'invalid_rate',
                    sprintf('The rate for %s is not a positive amount.', $date),
                );
            }
        }

        return ChannelSyncResult::success($listing->external_listing_id, [
            'simulated' => true,
            'nights' => count($update->nightlyRates),
            'currency' => $update->currency,
        ]);
    }

    public function importReservations(ChannelAccount $account, ?\DateTimeImmutable $since = null): array
    {
        // Fabricating bookings would put fictitious revenue into a real
        // ledger, so the simulated adapter never produces any. Development
        // data comes from the demo seeder, which is clearly labelled as such.
        return [];
    }

    public function pushReservationChange(ChannelListing $listing, ChannelReservationPayload $payload): ChannelSyncResult
    {
        return ChannelSyncResult::success($payload->externalReservationId, ['simulated' => true]);
    }

    public function cancelReservation(ChannelListing $listing, string $externalReservationId, ?string $reason = null): ChannelSyncResult
    {
        return ChannelSyncResult::success($externalReservationId, ['simulated' => true]);
    }

    public function sendMessage(ChannelListing $listing, ChannelMessagePayload $message): ChannelSyncResult
    {
        if (trim($message->body) === '') {
            return ChannelSyncResult::permanentFailure('empty_message', 'The message body is empty.');
        }

        return ChannelSyncResult::success($this->externalId('msg'), ['simulated' => true]);
    }

    /**
     * @return list<ChannelReviewPayload>
     */
    public function importReviews(ChannelAccount $account, ?\DateTimeImmutable $since = null): array
    {
        return [];
    }

    /**
     * Webhooks are verified with an HMAC over the raw body, which is what the
     * real channels use. Implementing it here means the inbound path — replay
     * protection, signature comparison, envelope normalisation — is genuinely
     * covered by tests.
     */
    public function parseWebhook(ChannelAccount $account, string $payload, array $headers): ?WebhookEnvelope
    {
        $secret = $account->credential('webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return null;
        }

        $signature = $this->headerValue($headers, 'x-channel-signature');
        $timestamp = $this->headerValue($headers, 'x-channel-timestamp');

        if ($signature === null || $timestamp === null) {
            return null;
        }

        // Reject stale signatures so a captured request cannot be replayed.
        $tolerance = (int) config('pms.webhooks.tolerance_seconds', 300);

        if (abs(time() - (int) $timestamp) > $tolerance) {
            return null;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode($payload, true);

        if (! is_array($data) || ! isset($data['event'], $data['id'])) {
            return null;
        }

        return new WebhookEnvelope(
            type: (string) $data['event'],
            providerEventId: (string) $data['id'],
            data: $data['data'] ?? [],
            occurredAt: isset($data['occurred_at'])
                ? new \DateTimeImmutable((string) $data['occurred_at'])
                : null,
        );
    }

    private function externalId(string $prefix): string
    {
        return $prefix.'_'.Str::lower((string) Str::ulid());
    }
}
