<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Registries;

use App\Domain\Integrations\Contracts\MessageTransportInterface;
use App\Domain\Integrations\Providers\Messaging\ChannelThreadTransport;
use App\Domain\Integrations\Providers\Messaging\EmailTransport;
use App\Domain\Integrations\Providers\Messaging\LocalTransport;

/**
 * The transports a message can leave by.
 *
 * @extends ProviderRegistry<MessageTransportInterface>
 */
class MessageTransportRegistry extends ProviderRegistry
{
    protected function registerDefaults(): void
    {
        // Real delivery, when the application has a real mailer configured.
        $this->register('email', EmailTransport::class);

        // A guest who wrote through a channel is answered in that channel's
        // own inbox. It addresses the thread through the mapped listing, so it
        // can only deliver for a conversation that carries one — and it says
        // which precondition failed when it cannot.
        $this->register('channel', ChannelThreadTransport::class);

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
