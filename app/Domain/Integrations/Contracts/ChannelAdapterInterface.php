<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

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

/**
 * The contract every distribution channel implements.
 *
 * The platform is always the source of truth for availability, pricing and
 * reservations; a channel is a projection of that truth plus a source of
 * inbound bookings. Adapters therefore translate, they do not decide.
 *
 * Not every channel supports every capability — an iCal feed has no pricing
 * and no messaging — so {@see capabilities()} declares what is available and
 * the synchronisation engine skips the rest rather than failing.
 */
interface ChannelAdapterInterface
{
    public const CAPABILITY_IMPORT_LISTINGS = 'import_listings';

    public const CAPABILITY_PUBLISH_LISTINGS = 'publish_listings';

    public const CAPABILITY_AVAILABILITY = 'availability';

    public const CAPABILITY_PRICING = 'pricing';

    public const CAPABILITY_RESTRICTIONS = 'restrictions';

    public const CAPABILITY_IMPORT_RESERVATIONS = 'import_reservations';

    public const CAPABILITY_MODIFY_RESERVATIONS = 'modify_reservations';

    public const CAPABILITY_CANCEL_RESERVATIONS = 'cancel_reservations';

    public const CAPABILITY_MESSAGING = 'messaging';

    public const CAPABILITY_REVIEWS = 'reviews';

    public const CAPABILITY_WEBHOOKS = 'webhooks';

    /**
     * Registry key, e.g. "airbnb", "booking_com", "ical", "direct".
     */
    public function key(): string;

    public function displayName(): string;

    /**
     * Whether the adapter talks to a real external system. A simulated adapter
     * is always labelled as such in the interface.
     */
    public function isLive(): bool;

    /**
     * @return list<string>
     */
    public function capabilities(): array;

    public function supports(string $capability): bool;

    /**
     * Verify stored credentials are usable. Called when a connection is
     * created and periodically afterwards.
     */
    public function testConnection(ChannelAccount $account): ChannelSyncResult;

    /**
     * Discover listings that already exist on the channel so they can be
     * mapped to internal listings.
     *
     * @return list<ChannelListingPayload>
     */
    public function importListings(ChannelAccount $account): array;

    /**
     * Create or update the channel-side listing from ours.
     */
    public function publishListing(ChannelListing $listing, ChannelListingPayload $payload): ChannelSyncResult;

    /**
     * Push availability for a date range.
     */
    public function pushAvailability(ChannelListing $listing, ChannelAvailabilityUpdate $update): ChannelSyncResult;

    /**
     * Push nightly rates and stay restrictions.
     */
    public function pushRates(ChannelListing $listing, ChannelRateUpdate $update): ChannelSyncResult;

    /**
     * Pull reservations changed since a point in time.
     *
     * @return list<ChannelReservationPayload>
     */
    public function importReservations(ChannelAccount $account, ?\DateTimeImmutable $since = null): array;

    /**
     * Tell the channel about a reservation change we originated.
     */
    public function pushReservationChange(ChannelListing $listing, ChannelReservationPayload $payload): ChannelSyncResult;

    /**
     * Cancel a reservation on the channel.
     */
    public function cancelReservation(ChannelListing $listing, string $externalReservationId, ?string $reason = null): ChannelSyncResult;

    /**
     * Send a guest message through the channel's own thread.
     */
    public function sendMessage(ChannelListing $listing, ChannelMessagePayload $message): ChannelSyncResult;

    /**
     * Pull reviews left on the channel.
     *
     * @return list<ChannelReviewPayload>
     */
    public function importReviews(ChannelAccount $account, ?\DateTimeImmutable $since = null): array;

    /**
     * Authenticate and normalise an inbound webhook. Returning null means the
     * payload failed verification and must be ignored.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public function parseWebhook(ChannelAccount $account, string $payload, array $headers): ?WebhookEnvelope;
}
