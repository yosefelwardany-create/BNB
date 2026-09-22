<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Registries;

use App\Domain\Integrations\Contracts\MessageTransportInterface;
use App\Domain\Integrations\Providers\Messaging\EmailTransport;
use App\Domain\Integrations\Providers\Messaging\LocalTransport;

/**
 * The transports a message can leave by.
 *
 * Channel-native messaging (an Airbnb or Booking.com thread) is delivered by
 * the channel adapter that owns the connection rather than by a transport
 * registered here, because it needs the mapped listing to address the thread.
 * {@see \App\Domain\Messaging\Services\MessageDispatcher} routes to it.
 *
 * @extends ProviderRegistry<MessageTransportInterface>
 */
class MessageTransportRegistry extends ProviderRegistry
{
    protected function registerDefaults(): void
    {
        // Real delivery, when the application has a real mailer configured.
        $this->register('email', EmailTransport::class);

        // The fallback of record: everything is kept and visibly marked
        // undelivered rather than silently dropped.
        $this->register('local', LocalTransport::class);
    }

    protected function defaultKey(): string
    {
        return (string) config('pms.providers.messaging', 'email');
    }

    protected static function providerNoun(): string
    {
        return 'message transport';
    }

    /**
     * How every transport currently stands, for the settings screen.
     *
     * @return list<array{key: string, name: string, live: bool, reason: string|null}>
     */
    public function status(): array
    {
        $status = [];

        foreach ($this->all() as $key => $transport) {
            $status[] = [
                'key' => $key,
                'name' => $transport->displayName(),
                'live' => $transport->isLive(),
                'reason' => $transport->simulationReason(),
            ];
        }

        return $status;
    }
}
