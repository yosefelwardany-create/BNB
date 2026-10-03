<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Properties\Enums\PropertyType;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Turning a discovered channel listing into a property you actually own.
 *
 * ## The gap this closes
 *
 * The importer discovers listings and maps them to properties that already
 * exist. It creates nothing, which left somebody connecting a channel for the
 * first time with a row saying "New Private Room — not mapped to anything" and
 * no way forward but to retype everything the channel had just told us.
 *
 * And it *had* told us: the adapter reads the title, type, address, city,
 * country, capacity, bedrooms, bathrooms and currency on every import. All of it
 * was stored as metadata and never used. The data was fetched, kept as a
 * souvenir, and thrown away.
 *
 * ## Why this is safe where auto-mapping is not
 *
 * The rule that the importer never guesses a mapping is about *writes*: a
 * mapping decides which calendar a booking lands on, so a wrong one double-books
 * a property and empties another, and nobody finds out until a guest is at the
 * door.
 *
 * Creating a property from a listing is a different risk entirely. A wrong
 * bedroom count is a typo somebody fixes in the property screen. Applying the
 * cautious rule to both is what produced an empty platform that imported
 * nothing, which is not safety — it is the appearance of safety with none of
 * the use.
 *
 * So this is deliberate and one listing at a time: a person presses a button
 * next to a row they are looking at. Nothing happens on a timer.
 *
 * ## What it does not do
 *
 * It does not invent what the channel did not say. A missing address stays
 * missing and the property is created without one, rather than filled with a
 * plausible guess that reads as fact on an owner statement. The screen shows
 * what came across so the gaps are visible and fillable.
 */
class ChannelListingAdopter
{
    /**
     * Why the adopted property could not go live, when it could not.
     *
     * Read once, straight after {@see adopt()}. It is the sentence the operator
     * needs before activating local booking sales. Hostex inbound imports can
     * populate a mapped draft without inventing its missing capacity.
     */
    private ?string $incomplete = null;

    public function __construct(private readonly PropertyService $properties) {}

    /**
     * What still has to be filled in before local activation, or null.
     */
    public function adoptionNotes(): ?string
    {
        return $this->incomplete;
    }

    /**
     * Create a property from this listing, and map the two together.
     */
    public function adopt(ChannelListing $mapping, bool $activate = true): Property
    {
        if ($mapping->property_id !== null) {
            throw new RuntimeException(
                'This listing is already attached to a property. Unmap it first if it went to the wrong one.',
            );
        }

        $this->incomplete = null;

        $metadata = is_array($mapping->metadata) ? $mapping->metadata : [];

        return DB::transaction(function () use ($mapping, $metadata, $activate): Property {
            ChannelAccount::query()->whereKey($mapping->channel_account_id)->lockForUpdate()->firstOrFail();
            $mapping->refresh()->loadMissing('account');
            if ($mapping->property_id !== null) {
                throw new RuntimeException('This source listing is already mapped. Refresh the list.');
            }
            $property = $this->properties->create(array_filter([
                // The channel's own name for it, which is what the operator
                // recognises on both screens.
                'name' => $mapping->external_name ?? 'Imported listing',
                'property_type' => $this->propertyType($metadata),
                'address_line_1' => $this->string($metadata, 'address_line_1'),
                'city' => $this->string($metadata, 'city'),
                'country_code' => $this->countryCode($metadata),
                'latitude' => $metadata['latitude'] ?? null,
                'longitude' => $metadata['longitude'] ?? null,
                'max_occupancy' => $this->int($metadata, 'max_guests') ?? ($mapping->account?->channel === 'hostex' ? 0 : null),
                'bedrooms' => $this->int($metadata, 'bedrooms'),
                'bathrooms' => $this->int($metadata, 'bathrooms'),
                'beds' => $this->int($metadata, 'beds'),
                'description' => $this->string($metadata, 'description'),
                'base_rate' => $this->int($metadata, 'base_rate'),
                // Only when the channel named one. Falling back to the
                // organization's currency is PropertyService's job, and it
                // knows the organization; this does not.
                'currency' => $this->currency($metadata),
                'timezone' => $mapping->account?->channel === 'hostex' ? 'UTC' : null,
                'settings' => $mapping->account?->channel === 'hostex' ? ['timezone_origin' => 'unresolved'] : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''));

            /*
             * Live where it can be, a draft where it cannot.
             *
             * Existing Hostex stays can be imported into a mapped draft.
             * Local activation has stricter requirements because it enables
             * selling new inventory through this platform.
             *
             * But activation requires a complete address, an occupancy and a
             * nightly rate, and a channel does not always supply all three.
             * Filling the gaps with plausible values to force it through would
             * publish a rate nobody chose. So the property is created either
             * way, and {@see adoptionNotes()} says exactly what is missing —
             * which is a job somebody can finish in a minute, rather than a
             * refusal that leaves them with nothing.
             */
            $mapping->forceFill([
                'property_id' => $property->getKey(),
                'listing_id' => $property->listings()->orderByDesc('is_primary')->first()?->getKey(),
                'is_active' => true,
            ])->save();

            if ($mapping->account?->channel === 'hostex') {
                app(HostexPropertySynchronizer::class)->apply($mapping);
            }

            if ($activate) {
                try {
                    $this->properties->activate($property->refresh());
                } catch (Throwable $e) {
                    $this->incomplete = $e->getMessage();
                }
            }

            return $property->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    /**
     * The channel's word for what this is, in our vocabulary.
     *
     * Never null: `properties.property_type` is NOT NULL, so there is no "leave
     * it blank" to fall back on. `other` is the honest answer when the channel
     * said nothing or said something we do not recognise — it reads as
     * unspecified on every screen, where `apartment` would read as a fact
     * somebody had checked. The difference reaches owner statements and
     * occupancy figures, so it is worth the extra enum case.
     */
    private function propertyType(array $metadata): string
    {
        $type = $this->string($metadata, 'property_type');

        if ($type === null) {
            return PropertyType::Other->value;
        }

        /*
         * The channel's vocabulary is not ours.
         *
         * Hostex says "Apartment", "Private room", "Entire home/apt". Only the
         * values this platform's enum accepts may be stored, and a type it does
         * not recognise is left for a person rather than forced into the nearest
         * match — "house" and "apartment" differ on an owner statement.
         */
        $normalised = str_replace([' ', '-', '/'], '_', mb_strtolower(trim($type)));

        return match (true) {
            str_contains($normalised, 'serviced') => PropertyType::ServicedApartment->value,
            str_contains($normalised, 'apartment'), str_contains($normalised, 'apt') => PropertyType::Apartment->value,
            str_contains($normalised, 'condo') => PropertyType::Condominium->value,
            str_contains($normalised, 'studio') => PropertyType::Studio->value,
            str_contains($normalised, 'loft') => PropertyType::Loft->value,
            str_contains($normalised, 'townhouse') => PropertyType::Townhouse->value,
            str_contains($normalised, 'villa') => PropertyType::Villa->value,
            str_contains($normalised, 'cabin') => PropertyType::Cabin->value,
            str_contains($normalised, 'cottage') => PropertyType::Cottage->value,
            str_contains($normalised, 'guesthouse'), str_contains($normalised, 'guest_house') => PropertyType::Guesthouse->value,
            str_contains($normalised, 'hostel') => PropertyType::Hostel->value,
            str_contains($normalised, 'hotel') => PropertyType::HotelRoom->value,
            str_contains($normalised, 'house'), str_contains($normalised, 'home') => PropertyType::House->value,
            // A private room in a shared place, which is most of what a
            // room-by-room operator lists. There is no "room" type, and
            // `other` is honest where `apartment` would be a guess that
            // reaches owner statements and occupancy figures.
            str_contains($normalised, 'room') => PropertyType::Other->value,
            default => PropertyType::Other->value,
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function countryCode(array $metadata): ?string
    {
        $code = $this->string($metadata, 'country_code');

        // Two letters or nothing. A channel that sent "Canada" where a code was
        // expected would otherwise be stored as a country nothing can match.
        return $code !== null && mb_strlen($code) === 2 ? mb_strtoupper($code) : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function currency(array $metadata): ?string
    {
        $currency = $this->string($metadata, 'currency');

        return $currency !== null && mb_strlen($currency) === 3 ? mb_strtoupper($currency) : null;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function string(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function int(array $source, string $key): ?int
    {
        $value = $source[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
