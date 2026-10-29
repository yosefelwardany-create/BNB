<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Amenity;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyPhoto;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Websites\Models\Website;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Homes, photos and bookings for a booking website's tests.
 *
 * Each is created inside its own account, as staff would create it, and the
 * bound account is cleared afterwards: a site's visitor is signed in nowhere.
 *
 * @property Website $site
 */
trait BuildsSites
{
    /**
     * @param  array<string, mixed>  $property
     */
    private function listing(Organization $organization, string $title, array $property = []): Listing
    {
        $this->actingForOrganization($organization);

        $home = Property::factory()->active()->create(array_merge([
            'organization_id' => $organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'cleaning_fee' => 0,
            'max_occupancy' => 4,
            'minimum_nights' => 1,
        ], $property));

        $listing = Listing::factory()->published()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $home->getKey(),
            'title' => $title,
            'currency' => 'EUR',
        ]);

        $this->app->make(TenantContext::class)->clear();

        return $listing;
    }

    private function show(Listing $listing, bool $featured = false): void
    {
        $this->site->listings()->create([
            'listing_id' => $listing->getKey(),
            'organization_id' => $listing->organization_id,
            'position' => $this->site->listings()->count(),
            'is_featured' => $featured,
        ]);
    }

    private function amenity(Listing $listing, string $key, string $name): void
    {
        $amenity = Amenity::query()->firstOrCreate(['key' => $key], ['name' => $name, 'category' => 'essentials']);

        DB::table('amenity_property')->insert([
            'amenity_id' => $amenity->getKey(),
            'property_id' => $listing->property_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function photo(Property $property, string $path): PropertyPhoto
    {
        $this->actingForOrganization($property->organization);

        $photo = PropertyPhoto::query()->create([
            'organization_id' => $property->organization_id,
            'property_id' => $property->getKey(),
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'image/jpeg',
        ]);

        $this->app->make(TenantContext::class)->clear();

        return $photo;
    }

    private function book(Listing $listing, CarbonImmutable $checkIn, CarbonImmutable $checkOut): void
    {
        $this->actingForOrganization($listing->organization);

        $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $listing,
            checkIn: $checkIn,
            checkOut: $checkOut,
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: ['first_name' => 'Ana', 'last_name' => 'Costa', 'email' => 'ana@example.test'],
            bookedAt: CarbonImmutable::today(),
        ));

        $this->app->make(TenantContext::class)->clear();
    }
}
