<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * A reservation as it exists on a channel.
 *
 * Amounts are integer minor units. `externalReservationId` is the key the
 * platform deduplicates on, which is what stops a redelivered webhook creating
 * a second booking.
 */
final class ChannelReservationPayload
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $externalReservationId,
        public readonly string $externalListingId,
        public readonly string $status,              // confirmed|cancelled|pending|modified
        public readonly \DateTimeImmutable $checkIn,
        public readonly \DateTimeImmutable $checkOut,
        public readonly string $currency,
        public readonly int $totalAmount,
        public readonly int $payoutAmount = 0,       // what the channel will remit
        public readonly int $commissionAmount = 0,
        public readonly int $taxAmount = 0,
        public readonly int $adults = 1,
        public readonly int $children = 0,
        public readonly int $infants = 0,
        public readonly int $pets = 0,
        public readonly ?string $guestFirstName = null,
        public readonly ?string $guestLastName = null,
        public readonly ?string $guestEmail = null,
        public readonly ?string $guestPhone = null,
        public readonly ?string $guestCountry = null,
        public readonly ?string $guestLanguage = null,
        public readonly ?string $confirmationCode = null,
        public readonly ?\DateTimeImmutable $bookedAt = null,
        public readonly ?\DateTimeImmutable $cancelledAt = null,
        public readonly ?string $cancellationReason = null,
        public readonly ?string $notes = null,
        public readonly array $raw = [],
    ) {}

    public function nights(): int
    {
        return max(1, (int) $this->checkIn->diff($this->checkOut)->days);
    }
}
