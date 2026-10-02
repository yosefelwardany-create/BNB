<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Integrations\DataObjects\ChannelMessagePayload;
use DateTimeImmutable;

/**
 * A channel whose existing conversations can be read, not merely listened to.
 *
 * Separate from {@see ChannelAdapterInterface::CAPABILITY_MESSAGING}, which is
 * about *sending*. A channel can accept a reply without letting anybody read the
 * thread it belongs to, and the difference matters on the day a connection is
 * made: webhooks deliver what happens next, and a property connected this
 * morning has months of conversation behind it that no webhook will ever
 * mention.
 *
 * Its own interface rather than another method on the main one, because every
 * other adapter would have to implement it to return nothing.
 */
interface ImportsConversations
{
    /**
     * Messages across this account's threads, newest first where the channel
     * allows it.
     *
     * @return list<ChannelMessagePayload>
     */
    public function importConversations(ChannelAccount $account, ?DateTimeImmutable $since = null): array;
}
