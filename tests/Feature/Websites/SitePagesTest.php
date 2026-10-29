<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Listings\Models\ListingPhoto;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Enums\ListingStatus;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyPhoto;
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
    use BuildsSites;
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

    public function test_tonight_is_priced_on_the_property_page_until_midnight_where_the_home_is(): void
    {
        // 21:00 on 9 December in Bogotá is already 10 December in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-12-10 02:00:00', 'UTC'));
        $listing = $this->listing($this->harbour, 'Casa Andina', ['timezone' => 'America/Bogota']);
        $this->show($listing);

        $this->get('/sites/brand/stay/'.$listing->slug.'?checkin=2026-12-09&checkout=2026-12-11')
            ->assertOk()
            ->assertDontSee('Those dates are in the past.')
            ->assertSee('for 2 nights');
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

    public function test_a_draft_sites_logo_still_loads_for_its_preview(): void
    {
        // The operator uploads a logo before the site is live and must see it.
        Storage::fake('media');
        Storage::disk('media')->put('websites/logo.png', 'png-bytes');
        $this->site->forceFill([
            'status' => Website::STATUS_DRAFT,
            'settings' => ['logo_path' => 'websites/logo.png'],
        ])->save();

        $this->get('/sites/brand/media/logo')->assertOk();
        $this->get('/sites/nowhere/media/logo')->assertNotFound();
    }

    public function test_page_titles_are_escaped_once(): void
    {
        $listing = $this->listing($this->harbour, "Fisherman's Cottage & Loft");
        $this->show($listing);

        $html = $this->get('/sites/brand/stay/'.$listing->slug)->assertOk()->getContent();

        $this->assertStringContainsString('<title>Fisherman&#039;s Cottage &amp; Loft · Stay With Us</title>', $html);
        $this->assertStringNotContainsString('&amp;#039;', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    public function test_a_stay_longer_than_the_maximum_says_so(): void
    {
        $listing = $this->listing($this->harbour, 'Sea View Flat', ['maximum_nights' => 7]);
        $this->show($listing);
        $checkIn = CarbonImmutable::today()->addDays(20);

        $this->get('/sites/brand/stay/'.$listing->slug.'?checkin='.$checkIn->toDateString().'&checkout='.$checkIn->addDays(10)->toDateString())
            ->assertOk()
            ->assertSee('The maximum stay is 7 nights')
            ->assertSee('Maximum stay');
    }

    public function test_more_guests_than_the_home_sleeps_says_so(): void
    {
        $listing = $this->listing($this->harbour, 'Sea View Flat', ['max_occupancy' => 4]);
        $this->show($listing);
        $checkIn = CarbonImmutable::today()->addDays(20);

        $this->get('/sites/brand/stay/'.$listing->slug.'?guests=6&checkin='.$checkIn->toDateString().'&checkout='.$checkIn->addDays(3)->toDateString())
            ->assertOk()
            ->assertSee('This home sleeps up to 4 guests.');
    }

    public function test_very_long_stays_are_refused_before_anything_is_priced(): void
    {
        $this->show($this->listing($this->harbour, 'Sea View Flat'));
        $checkIn = CarbonImmutable::today()->addDays(20);

        $this->get('/sites/brand/search?checkin='.$checkIn->toDateString().'&checkout='.$checkIn->addDays(400)->toDateString())
            ->assertOk()
            ->assertSee('Stays can be up to 365 nights.');
    }

    public function test_a_photo_hidden_from_the_listing_is_not_served(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('photos/shown.jpg', 'jpeg-bytes');
        Storage::disk('local')->put('photos/hidden.jpg', 'jpeg-bytes');

        $listing = $this->listing($this->harbour, 'Sea View Flat');
        $this->show($listing);
        $shown = $this->photo($listing->property, 'photos/shown.jpg');
        $hidden = $this->photo($listing->property, 'photos/hidden.jpg');

        $this->actingForOrganization($this->harbour);
        ListingPhoto::query()->create(['organization_id' => $this->harbour->getKey(), 'listing_id' => $listing->getKey(), 'property_photo_id' => $shown->getKey(), 'position' => 0, 'is_hidden' => false]);
        ListingPhoto::query()->create(['organization_id' => $this->harbour->getKey(), 'listing_id' => $listing->getKey(), 'property_photo_id' => $hidden->getKey(), 'position' => 1, 'is_hidden' => true]);
        $this->app->make(TenantContext::class)->clear();

        $this->get('/sites/brand/photos/'.$shown->getKey())->assertOk();
        // Hidden on purpose — a house number, a neighbour's window — so it
        // must stop being served, not only stop being linked.
        $this->get('/sites/brand/photos/'.$hidden->getKey())->assertNotFound();
    }

    public function test_a_photo_is_served_without_reading_the_whole_site(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('photos/sea.jpg', 'jpeg-bytes');

        $listing = $this->listing($this->harbour, 'Sea View Flat');
        $this->show($listing);
        $this->show($this->listing($this->hills, 'Old Barn'));
        $this->show($this->listing($this->hills, 'Hill House'));
        $photo = $this->photo($listing->property, 'photos/sea.jpg');

        DB::enableQueryLog();
        $this->get('/sites/brand/photos/'.$photo->getKey())->assertOk();

        // A page asks for a dozen of these at once.
        $this->assertLessThanOrEqual(6, count(DB::getQueryLog()));
    }

    public function test_photos_do_not_spend_the_page_allowance(): void
    {
        config(['pms.sites.page_requests_per_minute' => 2]);
        Storage::fake('local');
        Storage::disk('local')->put('photos/sea.jpg', 'jpeg-bytes');
        $listing = $this->listing($this->harbour, 'Sea View Flat');
        $this->show($listing);
        $photo = $this->photo($listing->property, 'photos/sea.jpg');

        foreach (range(1, 5) as $ignored) {
            $this->get('/sites/brand/photos/'.$photo->getKey())->assertOk();
        }

        $this->get('/sites/brand')->assertOk();
    }

    public function test_a_photo_with_an_unusable_address_is_left_out_not_a_crash(): void
    {
        $listing = $this->listing($this->harbour, 'Sea View Flat');
        $this->show($listing, featured: true);

        $this->actingForOrganization($this->harbour);
        PropertyPhoto::query()->create([
            'organization_id' => $this->harbour->getKey(),
            'property_id' => $listing->property_id,
            'disk' => 'external',
            'path' => 'imported',
            'external_url' => 'http://insecure.example/a.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        $this->app->make(TenantContext::class)->clear();

        $this->get('/sites/brand')->assertOk()->assertDontSee('insecure.example');
        $this->get('/sites/brand/stay/'.$listing->slug)->assertOk()->assertDontSee('insecure.example');
    }

    public function test_each_visitor_behind_the_proxy_has_their_own_allowance(): void
    {
        config(['pms.sites.page_requests_per_minute' => 2]);
        $this->show($this->listing($this->harbour, 'Sea View Flat'));

        // Render's router sits on a private address; the visitor is in
        // X-Forwarded-For. Counted by the router, every guest would share one.
        foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $visitor) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.5'])
                ->withHeader('X-Forwarded-For', $visitor)
                ->get('/sites/brand')
                ->assertOk();
        }

        $one = fn () => $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.5'])
            ->withHeader('X-Forwarded-For', '203.0.113.9')
            ->get('/sites/brand');

        $one()->assertOk();
        $one()->assertOk();
        $one()->assertStatus(429);
    }
}
