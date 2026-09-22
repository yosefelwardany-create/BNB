<?php

declare(strict_types=1);

namespace App\Domain\Reservations\DataObjects;

use App\Domain\Guests\Models\Guest;
use App\Domain\Listings\Models\Listing;
use App\Domain\Pricing\Models\RatePlan;
use App\Domain\Properties\Models\Unit;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Everything needed to create a reservation, from any source.
 *
 * The admin interface, the booking engine, a channel import and the public API
 * all build one of these, so a booking means the same thing however it arrived
 * — including the guarantees about availability and pricing.
 */
final class ReservationRequest
{
    /**
     * @param  array<string, mixed>|null  $guestAttributes  Used when the guest is not yet a profile.
     * @param  array<string, mixed>|null  $sourceMetadata
     */
    public function __construct(
        public readonly Listing $listing,
        public readonly CarbonImmutable $checkIn,
        public readonly CarbonImmutable $checkOut,
        public readonly int $adults = 1,
        public readonly int $children = 0,
        public readonly int $infants = 0,
        public readonly int $pets = 0,
        public readonly ReservationStatus $status = ReservationStatus::Confirmed,
        public readonly string $source = 'direct',
        public readonly ?Guest $guest = null,
        public readonly ?array $guestAttributes = null,
        public readonly ?Unit $unit = null,
        public readonly ?string $unitTypeId = null,
        public readonly ?RatePlan $ratePlan = null,
        public readonly ?string $promotionCode = null,
        public readonly ?string $guestNotes = null,
        public readonly ?string $internalNotes = null,
        public readonly ?CarbonImmutable $bookedAt = null,
        /** Commission the channel keeps, when the booking came from one. */
        public readonly ?Money $channelCommission = null,
        public readonly ?string $channelAccountId = null,
        public readonly ?string $externalReservationId = null,
        public readonly ?string $externalConfirmationCode = null,
        public readonly ?array $sourceMetadata = null,
        public readonly float $exchangeRate = 1.0,
        /** Set when an authorised agent books past a stay restriction. */
        public readonly bool $overrideRestrictions = false,
        public readonly string $actorType = 'user',
    ) {}

    public function nights(): int
    {
        return (int) $this->checkIn->startOfDay()->diffInDays($this->checkOut->startOfDay());
    }

    public function totalGuests(): int
    {
        return $this->adults + $this->children;
    }
}
