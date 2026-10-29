<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Enums\ListingStatus;
use App\Domain\Properties\Models\Amenity;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyPhoto;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Websites\Models\Website;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The guest's side of a booking website: what anybody on the internet sees.
 *
 * Read across client accounts without a signed-in user, so the rules are held
 * here explicitly: only a live site, only its own published listings of
 * active accounts, and never a property's exact address.
 */
class SitePagesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $harbour;

    private Organization $hills;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->harbour = $this->createOrganization(['name' => 'Harbour Homes', 'base_currency' => 'EUR']);
        $this->hills = $this->createOrganization(['name' => 'Hill Cottages', 'base_currency' => 'EUR']);

        $this->site = Website::query()->create([
            'slug' => 'brand',
            'name' => 'Stay With Us',
            'kind' => Website::KIND_BRAND,
            'status' => Website::STATUS_LIVE,
            'settings' => ['heading' => 'Homes by the sea', 'tagline' => 'Book direct'],
        ]);

        // Pages are public: nobody is signed in and no account is bound.
        $this->app->make(TenantContext::class)->clear();
    }

    public function test_a_live_site_shows_its_featured_listings(): void
    {
        $this->show($this->listing($this->harbour, 'Sea View Flat'), featured: true);
        $this->show($this->listing($this->hills, 'Old Barn'), featured: true);

        $this->get('/sites/brand')
            ->assertOk()
            ->assertSee('Sea View Flat')
            ->assertSee('Old Barn')
            ->assertSee('Homes by the sea')
            ->assertSee('<title>Stay With Us</title>', false)
            ->assertSee('property="og:title"', false);
    }

    public function test_a_draft_site_is_not_found(): void
    {
        $this->site->forceFill(['status' => Website::STATUS_DRAFT])->save();

        $this->get('/sites/brand')->assertNotFound();
    }

    public function test_an_unknown_site_is_not_found(): void
    {
        $this->get('/sites/nowhere')->assertNotFound();
    }

    public function test_unpublished_listings_and_suspended_clients_do_not_appear(): void
    {
        $paused = $this->listing($this->harbour, 'Paused Cabin');
        $this->show($paused);
        $paused->forceFill(['status' => ListingStatus::Paused])->save();

        $suspended = $this->createOrganization(['name' => 'Gone Away']);
        $hidden = $this->listing($suspended, 'Hidden Loft');
        $this->show($hidden);
        DB::table('organizations')->where('id', $suspended->getKey())->update(['status' => 'suspended']);
        $this->app->make(TenantContext::class)->clear();

        $this->show($this->listing($this->hills, 'Old Barn'));

        $this->get('/sites/brand/search')
            ->assertOk()
            ->assertSee('Old Barn')
            ->assertDontSee('Paused Cabin')
            ->assertDontSee('Hidden Loft');

        $this->get('/sites/brand/stay/'.$paused->slug)->assertNotFound();
        $this->get('/sites/brand/stay/'.$hidden->slug)->assertNotFound();
    }

    public function test_a_listing_not_on_the_site_has_no_page_here(): void
    {
        $elsewhere = $this->listing($this->harbour, 'Not Here');

        $this->get('/sites/brand/stay/'.$elsewhere->slug)->assertNotFound();
    }

    public function test_a_property_page_shows_the_listing_but_never_its_address(): void
    {
        $listing = $this->listing($this->harbour, 'Sea View Flat', [
            'description' => 'Wake up to the harbour from every window.',
            'address_line_1' => '14 Quay Street',
            'latitude' => 51.507351,
            'longitude' => -0.127758,
            'check_in_time' => '15:00',
        ]);
        $this->amenity($listing, 'wifi', 'Wi-Fi');
        $this->show($listing);

        $this->get('/sites/brand/stay/'.$listing->slug)
            ->assertOk()
            ->assertSee('Sea View Flat')
            ->assertSee('Wake up to the harbour from every window.')
            ->assertSee('Wi-Fi')
            ->assertSee('15:00')
            ->assertDontSee('14 Quay Street')
            // Approximate: the map is centred on rounded coordinates.
            ->assertSee('51.51')
            ->assertDontSee('51.507351');
    }

    public function test_every_photo_can_be_opened_from_the_property_page(): void
    {
        // A phone shows only the first photo in the page; the rest must be one
        // tap away, not only those past the fifth.
        $listing = $this->listing($this->harbour, 'Sea View Flat');
        $this->show($listing);
        $photos = collect(range(1, 3))->map(fn (int $n): PropertyPhoto => $this->photo($listing->property, "photos/{$n}.jpg"));

        $page = $this->get('/sites/brand/stay/'.$listing->slug)
            ->assertOk()
            ->assertSee('All 3 photos');

        $all = substr($page->getContent(), (int) strpos($page->getContent(), 'All 3 photos'));

        foreach ($photos as $photo) {
            $this->assertStringContainsString('/sites/brand/photos/'.$photo->getKey(), $all);
        }
    }

    public function test_search_filters_by_guests_bedrooms_and_amenities(): void
    {
        $match = $this->listing($this->harbour, 'Family House', ['max_occupancy' => 6, 'bedrooms' => 3]);
        $this->amenity($match, 'wifi', 'Wi-Fi');
        $small = $this->listing($this->harbour, 'Studio', ['max_occupancy' => 2, 'bedrooms' => 1]);
        $this->amenity($small, 'wifi', 'Wi-Fi');
        $noWifi = $this->listing($this->hills, 'Off Grid Barn', ['max_occupancy' => 8, 'bedrooms' => 4]);

        foreach ([$match, $small, $noWifi] as $listing) {
            $this->show($listing);
        }

        $this->get('/sites/brand/search?guests=5&bedrooms=2&amenities[]=wifi')
            ->assertOk()
            ->assertSee('Family House')
            ->assertDontSee('Studio')
            ->assertDontSee('Off Grid Barn');
    }

    public function test_search_with_dates_leaves_out_booked_listings_and_shows_the_total(): void
    {
        $booked = $this->listing($this->harbour, 'Booked Flat');
        $free = $this->listing($this->hills, 'Free Cottage', ['base_rate' => 12000, 'cleaning_fee' => 0]);
        $this->show($booked);
        $this->show($free);

        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->book($booked, $checkIn, $checkIn->addDays(3));

        $this->get('/sites/brand/search?checkin='.$checkIn->toDateString().'&checkout='.$checkIn->addDays(3)->toDateString().'&guests=2')
            ->assertOk()
            ->assertSee('Free Cottage')
            ->assertDontSee('Booked Flat')
            // Three nights at €120.00, from the pricing engine.
            ->assertSee('€360.00');
    }

    public function test_impossible_dates_are_explained_not_crashed(): void
    {
        $this->show($this->listing($this->harbour, 'Sea View Flat'));

        $this->get('/sites/brand/search?checkin=2026-12-10&checkout=2026-12-05')
            ->assertOk()
            ->assertSee('Check-out must be after check-in.');

        $this->get('/sites/brand/search?checkin=2001-01-01&checkout=2001-01-03')
            ->assertOk()
            ->assertSee('Those dates are in the past.');
    }

    public function test_photos_are_served_only_for_the_sites_own_listings(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('photos/sea.jpg', 'jpeg-bytes');
        Storage::disk('local')->put('photos/other.jpg', 'jpeg-bytes');

        $listing = $this->listing($this->harbour, 'Sea View Flat');
        $this->show($listing);
        $own = $this->photo($listing->property, 'photos/sea.jpg');

        $other = $this->listing($this->hills, 'Not On The Site');
        $foreign = $this->photo($other->property, 'photos/other.jpg');

        $this->get('/sites/brand/photos/'.$own->getKey())->assertOk();
        $this->get('/sites/brand/photos/'.$foreign->getKey())->assertNotFound();
    }

    public function test_the_sitemap_lists_every_property_page(): void
    {
        $sea = $this->listing($this->harbour, 'Sea View Flat');
        $barn = $this->listing($this->hills, 'Old Barn');
        $this->show($sea);
        $this->show($barn);

        $this->get('/sites/brand/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee(url('/sites/brand/stay/'.$sea->slug), false)
            ->assertSee(url('/sites/brand/stay/'.$barn->slug), false);

        $this->get('/sites/brand/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap: '.url('/sites/brand/sitemap.xml'), false);
    }

    // ------------------------------------------------------------------

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
