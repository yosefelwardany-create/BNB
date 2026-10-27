<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\DataObjects\ChannelAvailabilityUpdate;
use App\Domain\Integrations\DataObjects\ChannelRateUpdate;
use App\Domain\Integrations\DataObjects\ChannelSyncResult;

/**
 * A channel that wants several mappings' updates as one message.
 *
 * A mapping here is one sellable unit — for Channex, one room type — while
 * the channel may address its messages, and count its rate limit, by
 * something larger: a property holding several room types. Sent mapping by
 * mapping, a property with three room types costs three calls where the
 * channel expects one, and Channex's certification fails exactly that:
 * "per-date or per-rate API calls where the test specifies 1 API call".
 *
 * Its own interface rather than more methods on the main one, because every
 * other adapter would have to implement it to send one message per mapping,
 * which is what they already do.
 */
interface PushesAriInBatches
{
    /**
     * What this mapping shares a message — and a rate limit — with.
     *
     * Mappings with the same key travel together. Null means the mapping
     * cannot be addressed yet, and it is sent alone so that it is refused by
     * name rather than taking the rest of its group down with it.
     */
    public function ariBatchKey(ChannelListing $listing): ?string;

    /**
     * @param  list<array{0: ChannelListing, 1: ChannelAvailabilityUpdate}>  $updates
     * @return list<ChannelSyncResult> one per update, in the order given
     */
    public function pushAvailabilityBatch(array $updates): array;

    /**
     * @param  list<array{0: ChannelListing, 1: ChannelRateUpdate}>  $updates
     * @return list<ChannelSyncResult> one per update, in the order given
     */
    public function pushRatesBatch(array $updates): array;
}
