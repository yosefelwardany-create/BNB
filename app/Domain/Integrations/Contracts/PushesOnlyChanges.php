<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use App\Domain\Channels\Models\ChannelListing;

/**
 * A channel that is sent what changed and nothing else.
 *
 * Channex checks each message against the action that caused it: a price set
 * for one night must arrive as one value with a rate, rules saved together
 * over ten nights as one value over those nights carrying those rules. The
 * nights a change touched and the rules it set are kept with the mapping
 * ({@see ChannelListing::markDirty()}), and such
 * a channel is sent only those; a full sync sends every rule for every night.
 *
 * Every other channel is sent the whole changed window, as before.
 */
interface PushesOnlyChanges {}
