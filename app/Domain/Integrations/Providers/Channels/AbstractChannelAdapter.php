<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\Contracts\ChannelAdapterInterface;
use App\Domain\Integrations\DataObjects\ChannelAvailabilityUpdate;
use App\Domain\Integrations\DataObjects\ChannelListingPayload;
use App\Domain\Integrations\DataObjects\ChannelMessagePayload;
use App\Domain\Integrations\DataObjects\ChannelRateUpdate;
use App\Domain\Integrations\DataObjects\ChannelReservationPayload;
use App\Domain\Integrations\DataObjects\ChannelSyncResult;
use App\Domain\Integrations\DataObjects\WebhookEnvelope;

/**
 * Default behaviour for channel adapters.
 *
 * Unsupported operations return a clear permanent failure rather than throwing
 * or silently succeeding: the synchronisation engine checks
 * {@see supports()} first, so reaching one of these is a programming error
 * that should be visible in the sync log, not a crash.
 */
abstract class AbstractChannelAdapter implements ChannelAdapterInterface
{
    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * Live adapters have nothing to explain. A simulated one overrides this
     * and says what is missing.
     */
    public function simulationReason(): ?string
    {
        return $this->isLive()
            ? null
            : sprintf('%s is served by a local simulation in this installation.', $this->displayName());
    }

    public function testConnection(ChannelAccount $account): ChannelSyncResult
    {
        return ChannelSyncResult::success();
    }

    public function importListings(ChannelAccount $account): array
    {
        return [];
    }

    public function publishListing(ChannelListing $listing, ChannelListingPayload $payload): ChannelSyncResult
    {
        return $this->unsupported(self::CAPABILITY_PUBLISH_LISTINGS);
    }

    public function pushAvailability(ChannelListing $listing, ChannelAvailabilityUpdate $update): ChannelSyncResult
    {
        return $this->unsupported(self::CAPABILITY_AVAILABILITY);
    }

    public function pushRates(ChannelListing $listing, ChannelRateUpdate $update): ChannelSyncResult
    {
        return $this->unsupported(self::CAPABILITY_PRICING);
    }

    public function importReservations(ChannelAccount $account, ?\DateTimeImmutable $since = null): array
    {
        return [];
    }

    public function pushReservationChange(ChannelListing $listing, ChannelReservationPayload $payload): ChannelSyncResult
    {
        return $this->unsupported(self::CAPABILITY_MODIFY_RESERVATIONS);
    }

    public function cancelReservation(ChannelListing $listing, string $externalReservationId, ?string $reason = null): ChannelSyncResult
    {
        return $this->unsupported(self::CAPABILITY_CANCEL_RESERVATIONS);
    }

    public function sendMessage(ChannelListing $listing, ChannelMessagePayload $message): ChannelSyncResult
    {
        return $this->unsupported(self::CAPABILITY_MESSAGING);
    }

    public function importReviews(ChannelAccount $account, ?\DateTimeImmutable $since = null): array
    {
        return [];
    }

    public function parseWebhook(ChannelAccount $account, string $payload, array $headers): ?WebhookEnvelope
    {
        return null;
    }

    protected function unsupported(string $capability): ChannelSyncResult
    {
        return ChannelSyncResult::permanentFailure(
            'unsupported_capability',
            sprintf('%s does not support %s.', $this->displayName(), str_replace('_', ' ', $capability)),
        );
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    protected function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === strtolower($name)) {
                return is_array($value) ? ($value[0] ?? null) : (string) $value;
            }
        }

        return null;
    }
}
