<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\Exceptions\ChannexRequestException;
use App\Domain\Integrations\Providers\Channels\ChannexChannelAdapter;
use App\Domain\Integrations\Support\ChannexClient;

/**
 * Asks Channex to tell Habitat the moment a booking arrives at one of the
 * connection's properties, so it is read within seconds rather than at the
 * next five-minute import.
 *
 * One webhook per Channex property linked to a home here, carrying the
 * connection's secret in a header Habitat checks
 * ({@see ChannexChannelAdapter::parseWebhook()}). Kept up to date by every
 * import, and only sent to Channex when the properties, Habitat's address or
 * the secret have changed.
 */
class ChannexWebhooks
{
    /** Channex's name for every booking event: new, modified and cancelled. */
    public const EVENTS = 'booking';

    private const SETTING = 'channex_webhooks';

    /**
     * @return array<string, int|string>
     *
     * @throws ChannexRequestException
     */
    public function ensure(ChannelAccount $account): array
    {
        // Habitat's public address, which a queue worker has no request to
        // learn from.
        $callback = rtrim((string) config('app.url'), '/').route('webhooks.channels', ['account' => $account->getKey()], absolute: false);

        if (! str_starts_with($callback, 'https://')) {
            return ['skipped' => 'Habitat is not on an https address, so Channex cannot reach it.'];
        }

        $properties = $this->linkedProperties($account);

        if ($properties === []) {
            return ['skipped' => 'No Channex property is linked to a home yet.'];
        }

        $secret = $account->webhook_secret;

        if (! is_string($secret) || $secret === '') {
            $secret = $account->issueWebhookSecret();
        }

        $fingerprint = hash('sha256', implode('|', [$callback, self::EVENTS, $secret, ...$properties]));

        if (($account->settings[self::SETTING]['fingerprint'] ?? null) === $fingerprint) {
            return ['unchanged' => count($properties)];
        }

        $client = new ChannexClient(ChannexChannelAdapter::apiKey($account), $account->credential('base_url'));

        // Habitat's own, found by its address: one per property.
        $existing = collect($client->all('webhooks'))
            ->filter(fn (array $hook): bool => ($hook['callback_url'] ?? null) === $callback && is_string($hook['property_id'] ?? null))
            ->keyBy('property_id');

        $created = 0;
        $updated = 0;

        foreach ($properties as $property) {
            $webhook = ['webhook' => [
                'property_id' => $property,
                'callback_url' => $callback,
                'event_mask' => self::EVENTS,
                'headers' => [ChannexChannelAdapter::WEBHOOK_SECRET_HEADER => $secret],
                'is_active' => true,
                'send_data' => true,
            ]];

            $id = $existing->get($property)['id'] ?? null;

            if (is_string($id) && $id !== '') {
                $client->put('webhooks/'.$id, $webhook);
                $updated++;
            } else {
                $client->post('webhooks', $webhook);
                $created++;
            }
        }

        $account->forceFill(['settings' => array_merge($account->settings ?? [], [self::SETTING => [
            'fingerprint' => $fingerprint,
            'property_ids' => $properties,
            'registered_at' => now()->toIso8601String(),
        ]])])->save();

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * The Channex properties whose room types are linked to a home here.
     *
     * @return list<string>
     */
    private function linkedProperties(ChannelAccount $account): array
    {
        return ChannelListing::query()->withoutGlobalScope('organization')
            ->where('channel_account_id', $account->getKey())
            ->whereNotNull('property_id')
            ->get()
            ->map(fn (ChannelListing $mapping): mixed => $mapping->metadata['channex']['property_id'] ?? null)
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
