<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\Contracts\ChannelAdapterInterface;
use App\Domain\Integrations\DataObjects\ChannelAvailabilityUpdate;
use App\Domain\Integrations\DataObjects\ChannelRateUpdate;
use Carbon\CarbonImmutable;

/**
 * A push that has been built, found to have changed, and not yet sent.
 *
 * Building and sending used to be one step, which made "one message per
 * mapping" the only possible shape. Separated, several mappings' pushes can be
 * built first and then sent together to a channel that wants them as one —
 * while each still keeps its own hash, its own sync job and its own outcome.
 */
final class PreparedPush
{
    /**
     * @param  'availability'|'rates'  $kind
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly ChannelListing $mapping,
        public readonly string $kind,
        public readonly ChannelAdapterInterface $adapter,
        public readonly ChannelAvailabilityUpdate|ChannelRateUpdate $update,
        public readonly string $hash,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly int $records,
        public readonly array $summary,
    ) {}
}
