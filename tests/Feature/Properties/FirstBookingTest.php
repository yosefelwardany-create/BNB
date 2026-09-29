<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Domain\Listings\Models\Listing;
use App\Domain\Listings\Services\ListingService;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * A company that has just signed up can add a property and book it.
 *
 * Written because that was not true. Every part worked on its own — properties
 * could be created, listings could be created, reservations could be created —
 * and the chain between them was broken in a way no single-endpoint test could
 * see: a reservation takes a *listing*, nothing created one when a property was
 * added, and no screen in the interface created one either. So the reservation
 * form's listing picker was empty, permanently, for anybody who had not seeded a
 * demo, and the person who hit it could only report that adding a booking did not
 * offer any of the properties they had added.
 *
 * The lesson is the one about a picker: a required field fed by records the
 * interface cannot produce is a dead end, and the endpoint behind it passes its
 * own tests the whole time. So these tests walk the path a person actually walks
 * rather than checking each endpoint in isolation.
 */
class FirstBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_property_can_be_booked_straight_away(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $property = $this->postJson('/api/v1/properties', [
            'name' => 'Alfama Terrace',
            'property_type' => 'apartment',
            'address_line_1' => 'Rua dos Remédios 12',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'max_occupancy' => 4,
            'base_rate' => 12000,
        ])->assertCreated()->json('data.id');

        // The picker the reservation form reads, asked exactly as it asks it.
        $listings = $this->getJson('/api/v1/listings?per_page=100')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $listings, 'A new property should come with a listing to book.');
        $this->assertSame($property, $listings[0]['property_id']);
        $this->assertNotSame('', (string) $listings[0]['title']);

        // The second half of the same journey. A property is created as a draft
        // and the availability engine refuses a draft, so it has to be possible
        // to find out what is missing and put it right — both through the API the
        // screen calls, and with nothing invented in between.
        $this->getJson("/api/v1/properties/{$property}/readiness")
            ->assertOk()
            ->assertJson(['ready' => true, 'blockers' => [], 'status' => 'draft']);

        $this->postJson("/api/v1/properties/{$property}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->postJson('/api/v1/reservations', [
            'listing_id' => $listings[0]['id'],
            'check_in' => now()->addWeek()->toDateString(),
            'check_out' => now()->addWeek()->addDays(3)->toDateString(),
            'adults' => 2,
            'guest' => ['first_name' => 'Ana', 'last_name' => 'Silva'],
        ])->assertCreated();
    }

    public function test_readiness_names_what_is_missing_rather_than_only_refusing(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        // The property the form produces when somebody fills in only what it
        // marks as required.
        $property = $this->postJson('/api/v1/properties', [
            'name' => 'Half Filled',
            'property_type' => 'studio',
        ])->assertCreated()->json('data.id');

        $blockers = $this->getJson("/api/v1/properties/{$property}/readiness")
            ->assertOk()
            ->assertJson(['ready' => false])
            ->json('blockers');

        $this->assertNotEmpty($blockers, 'A property that cannot be activated must say why.');

        $this->postJson("/api/v1/properties/{$property}/activate")->assertStatus(422);
    }

    public function test_the_picker_survives_a_second_property(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        // Two, deliberately. Eloquent allows a lazy load when a query returned a
        // single model, so a resource that reaches for an unloaded relation is
        // invisible at one row and a 500 at two — and the picker is the one
        // endpoint where a tenant reliably has more than one.
        foreach (['First Flat', 'Second Flat'] as $name) {
            $this->postJson('/api/v1/properties', [
                'name' => $name,
                'property_type' => 'apartment',
            ])->assertCreated();
        }

        $this->getJson('/api/v1/listings?per_page=100')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_picker_returns_every_listing_exactly_once(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        // Twelve sharing one name, because listings are named after their
        // property and ties in the sort column are the normal case here.
        //
        // What this pins is the end state — every listing offered once, none
        // dropped. It does not prove the `orderBy('id')` tiebreak that went in
        // alongside it: Postgres returns a stable order for a table this small
        // whether the tiebreak is there or not, so the test passes without it.
        // The tiebreak is there because an order that is only incidentally
        // stable is not a guarantee, and this is the query a person picks from.
        foreach (range(1, 12) as $ignored) {
            $this->postJson('/api/v1/properties', [
                'name' => 'Same Name', 'property_type' => 'apartment',
            ])->assertCreated();
        }

        $seen = [];

        foreach ([1, 2, 3] as $page) {
            foreach ($this->getJson("/api/v1/listings?per_page=5&page={$page}")->assertOk()->json('data') as $listing) {
                $seen[] = $listing['id'];
            }
        }

        $this->assertCount(12, $seen);
        $this->assertSame(12, count(array_unique($seen)));
    }

    public function test_a_propertys_listings_are_only_its_own(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $first = $this->postJson('/api/v1/properties', [
            'name' => 'Mine', 'property_type' => 'apartment',
        ])->json('data.id');

        $this->postJson('/api/v1/properties', [
            'name' => 'Somebody else’s', 'property_type' => 'house',
        ])->assertCreated();

        // The nested route used to ignore its own parameter and answer with
        // every listing in the organization, which reads as each property owning
        // all of them.
        $this->getJson("/api/v1/properties/{$first}/listings")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mine');
    }

    public function test_the_listing_is_a_draft_named_after_the_property(): void
    {
        $this->createTenantWithAdmin();

        $property = $this->app->make(PropertyService::class)
            ->create(['name' => 'Graça Loft', 'property_type' => 'loft']);

        $listing = $property->listings()->sole();

        $this->assertSame('Graça Loft', $listing->name);
        $this->assertTrue($listing->is_primary);
        // Draft, not published: publication requires a photograph and an active
        // property, and neither is invented here.
        $this->assertSame('draft', $listing->status->value);
        $this->assertNull($listing->published_at);
        $this->assertSame($property->currency, $listing->currency);
    }

    public function test_a_second_listing_can_still_be_added_and_does_not_displace_the_first(): void
    {
        $this->createTenantWithAdmin();

        $property = $this->app->make(PropertyService::class)
            ->create(['name' => 'Quinta do Vale', 'property_type' => 'house']);

        $extra = $this->app->make(ListingService::class)
            ->create($property, ['name' => 'Garden room only']);

        $this->assertCount(2, $property->listings()->get());

        // The one created with the property stays primary. A second listing
        // quietly taking that role would move which one channels and reports
        // treat as the property's own.
        $this->assertFalse($extra->is_primary);
        $this->assertSame(
            'Quinta do Vale',
            $property->listings()->where('is_primary', true)->sole()->name,
        );
    }

    public function test_enriching_the_primary_listing_does_not_create_a_second(): void
    {
        $this->createTenantWithAdmin();

        $listings = $this->app->make(ListingService::class);

        $property = $this->app->make(PropertyService::class)
            ->create(['name' => 'Bica Studio', 'property_type' => 'studio']);

        $listings->primaryFor($property, ['title' => 'Bright studio above the funicular']);
        $listings->primaryFor($property, ['summary' => 'Two minutes from the tram.']);

        $listing = $property->listings()->sole();

        $this->assertSame('Bright studio above the funicular', $listing->title);
        $this->assertSame('Two minutes from the tram.', $listing->summary);
    }

    public function test_properties_that_predate_this_are_given_a_listing(): void
    {
        ['organization' => $organization] = $this->createTenantWithAdmin();

        $property = $this->app->make(PropertyService::class)
            ->create(['name' => 'Older Property', 'property_type' => 'apartment']);

        // The state those properties are in: the record without its listing.
        // Deleted here rather than by the command, which never removes anything.
        Listing::query()->where('property_id', $property->getKey())->delete();

        // Run as it really runs: from the command line, with no tenant bound and
        // nobody signed in. A listing written under the wrong organization would
        // be a cross-tenant leak, so the assertion below is on its owner.
        $this->app->make(TenantContext::class)->clear();
        auth()->forgetUser();

        Artisan::call('properties:ensure-listings', ['--dry-run' => true]);
        $this->assertSame(0, $property->listings()->count(), 'A dry run must create nothing.');

        Artisan::call('properties:ensure-listings');

        $listing = $property->fresh()->listings()->sole();
        $this->assertSame('Older Property', $listing->name);
        $this->assertSame($organization->getKey(), $listing->organization_id);

        // Idempotent: the second run is a no-op rather than a duplicate.
        Artisan::call('properties:ensure-listings');
        $this->assertSame(1, $property->fresh()->listings()->count());
    }

    public function test_the_command_leaves_an_existing_listing_alone(): void
    {
        $this->createTenantWithAdmin();

        $property = $this->app->make(PropertyService::class)
            ->create(['name' => 'Already Listed', 'property_type' => 'villa']);

        $before = $property->listings()->sole();

        $this->app->make(ListingService::class)
            ->update($before, ['title' => 'A title somebody wrote']);

        Artisan::call('properties:ensure-listings');

        $after = $property->fresh()->listings()->sole();
        $this->assertTrue($after->is($before));
        $this->assertSame('A title somebody wrote', $after->title);
    }

    public function test_every_property_in_the_demo_has_somewhere_to_take_a_booking(): void
    {
        $this->createTenantWithAdmin();

        $this->app->make(PropertyService::class)
            ->create(['name' => 'Seeded', 'property_type' => 'apartment']);

        $this->assertSame(
            0,
            Property::query()->whereDoesntHave('listings')->count(),
            'A property with no listing cannot be booked, put on a calendar or priced.',
        );
    }
}
