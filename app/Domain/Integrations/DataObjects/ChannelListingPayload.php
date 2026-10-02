<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * A listing as the channel understands it.
 *
 * Used in both directions: importing what already exists on a channel, and
 * publishing our content to it.
 */
final class ChannelListingPayload
{
    /**
     * @param  list<string>  $amenities
     * @param  list<string>  $photoUrls
     * @param  array<string, mixed>  $extra  Channel-specific fields that have no cross-channel equivalent.
     */
    public function __construct(
        public readonly ?string $externalListingId,
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly ?string $propertyType = null,
        public readonly ?int $maxGuests = null,
        public readonly ?int $bedrooms = null,
        public readonly ?int $bathrooms = null,
        public readonly ?int $beds = null,
        public readonly array $amenities = [],
        public readonly array $photoUrls = [],
        public readonly ?string $addressLine1 = null,
        public readonly ?string $city = null,
        public readonly ?string $countryCode = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?string $currency = null,
        /**
         * The listing's standing nightly rate, in minor units.
         *
         * Imported because a property cannot go live without one, and a platform
         * that discovers a listing and then cannot activate it has not finished
         * the job. Null where the channel does not say, which is left for a
         * person rather than filled with a plausible number — a wrong rate sells
         * nights at the wrong price.
         */
        public readonly ?int $baseRate = null,
        public readonly ?string $checkInTime = null,
        public readonly ?string $checkOutTime = null,
        public readonly ?string $status = null,
        public readonly array $extra = [],
    ) {}
}
