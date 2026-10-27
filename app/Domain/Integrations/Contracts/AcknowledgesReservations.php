<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Integrations\DataObjects\ChannelReservationPayload;

/**
 * A channel that has to be told a booking arrived safely.
 *
 * Channex keeps offering a booking revision until it is acknowledged, and
 * never offers it again once it is. So the acknowledgement has to follow the
 * booking being *stored*, not merely read: an adapter that acknowledged on
 * reading lost, for good, every booking the importer could not then place.
 *
 * Its own interface rather than a method on the main one, because no other
 * channel has the concept.
 */
interface AcknowledgesReservations
{
    /**
     * Tell the channel this booking is stored here. A failure is the
     * adapter's to log: the booking is safe, and the channel will offer it
     * again, which the import is idempotent about.
     */
    public function acknowledgeReservation(ChannelAccount $account, ChannelReservationPayload $payload): void;
}
