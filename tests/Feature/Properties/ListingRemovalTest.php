<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Domain\Listings\Exceptions\ListingInUseException;
use App\Domain\Listings\Models\Listing;
use App\Domain\Listings\Services\ListingService;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Users\Support\RoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Removing a listing, and getting it back.
 *
 * Two things are being held here.
 *
 * The first is that removing a listing keeps it. Reservations carry a
 * `listing_id`, statements are drawn from those reservations, and
 * `listing_versions` records what was published when — a real delete would
 * either orphan that or take it with it, and a stay somebody paid for has to
 * stay explicable years later. So the button archives, and the row is still
 * there afterwards.
 *
 * The second is the guard, which exists because the button would otherwise
 * quietly undo the thing that makes a property usable. Everything downstream
 * takes a listing; a property whose last one is archived vanishes from every
 * picker while still reading as active in the portfolio. That is the exact
 * failure this platform spent three releases removing, and nothing would put it
 * back — `properties:ensure-listings` looks for properties with no listing, and
 * an archived one is still a listing.
 */
class ListingRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_removing_a_listing_archives_it_and_keeps_the_row(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $property = $this->bookableProperty();
        $extra = $this->app->make(ListingService::class)
            ->create($property, ['name' => 'Garden room only']);

        $this->deleteJson("/api/v1/listings/{$extra->id}", ['reason' => 'Stopped letting the room'])
            ->assertOk()
            ->assertJson(['message' => 'Listing archived.']);

        $this->assertSame('archived', $extra->refresh()->status->value);
        $this->assertNotNull(Listing::query()->find($extra->getKey()));
    }

    public function test_an_archived_listing_leaves_the_pickers_but_can_be_asked_for(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $property = $this->bookableProperty();
        $extra = $this->app->make(ListingService::class)
            ->create($property, ['name' => 'Garden room only']);

        $this->deleteJson("/api/v1/listings/{$extra->id}")->assertOk();

        // Out of the booking form by default.
        $this->assertCount(1, $this->getJson('/api/v1/listings')->assertOk()->json('data'));

        // And askable for by status, which is how the property screen shows what
        // was removed — a removal nobody can see is a removal nobody can undo.
        $withArchived = $this->getJson(
            "/api/v1/properties/{$property->getKey()}/listings?status=draft,published,paused,archived"
        )->assertOk()->json('data');

        $this->assertCount(2, $withArchived);
    }

    public function test_a_removed_listing_can_be_brought_back_off_sale(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $property = $this->bookableProperty();
        $extra = $this->app->make(ListingService::class)
            ->create($property, ['name' => 'Garden room only']);

        $this->deleteJson("/api/v1/listings/{$extra->id}")->assertOk();

        // Paused, not published. What was true when it was archived may not be
        // true now — the property could have been deactivated, a photograph
        // removed — so it goes through the publication gate again rather than
        // reappearing on a channel by itself.
        $this->postJson("/api/v1/listings/{$extra->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'paused');

        $this->assertCount(2, $this->getJson('/api/v1/listings')->assertOk()->json('data'));
    }

    public function test_restoring_something_that_was_never_archived_changes_nothing(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $listing = $this->bookableProperty()->listings()->sole();

        $this->postJson("/api/v1/listings/{$listing->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_the_last_listing_of_a_live_property_cannot_be_removed(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $property = $this->bookableProperty();
        $listing = $property->listings()->sole();

        $response = $this->deleteJson("/api/v1/listings/{$listing->id}")->assertStatus(422);

        // The refusal names the property and both ways forward, because "no" on a
        // button somebody just pressed is indistinguishable from a fault.
        $this->assertStringContainsString($property->name, $response->json('message'));
        $this->assertStringContainsString('only listing', $response->json('message'));

        $this->assertSame('draft', $listing->refresh()->status->value);
    }

    public function test_it_can_be_removed_once_the_property_is_off_sale(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $property = $this->bookableProperty();
        $listing = $property->listings()->sole();

        // Nobody is being sold anything now, so tidying up is reasonable.
        $this->postJson("/api/v1/properties/{$property->getKey()}/deactivate")->assertOk();

        $this->deleteJson("/api/v1/listings/{$listing->id}")->assertOk();
        $this->assertSame('archived', $listing->refresh()->status->value);
    }

    public function test_the_guard_counts_only_listings_that_are_still_on_the_books(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $listings = $this->app->make(ListingService::class);
        $property = $this->bookableProperty();

        $second = $listings->create($property, ['name' => 'Garden room only']);
        $listings->archive($second);

        // Two rows, one of them archived, so the first is still the last live
        // one. Counting rows rather than live rows would let it through.
        $this->expectException(ListingInUseException::class);

        $listings->archive($property->listings()->where('is_primary', true)->sole());
    }

    public function test_a_listing_reports_its_own_title_apart_from_the_one_it_shows(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $listing = $this->bookableProperty()->listings()->sole();

        /*
         * These two must not be conflated, and the edit form is where it would
         * bite. `title` falls back to the property's name — correct for a guest
         * and for a channel. `own_title` is the column, and it is null here.
         *
         * A form seeded from `title` would show "Alfama Terrace" and be
         * indistinguishable from a listing that really carries that title. The
         * next save would make it one, and renaming the property would then stop
         * reaching it with nothing on screen having said so.
         */
        $this->getJson("/api/v1/listings/{$listing->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Alfama Terrace')
            ->assertJsonPath('data.own_title', null);

        $this->patchJson("/api/v1/listings/{$listing->id}", [
            'title' => 'Bright flat above the tram line',
        ])->assertOk();

        $this->getJson("/api/v1/listings/{$listing->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Bright flat above the tram line')
            ->assertJsonPath('data.own_title', 'Bright flat above the tram line');
    }

    public function test_editing_one_field_leaves_the_rest_inheriting(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $property = $this->bookableProperty();
        $listing = $property->listings()->sole();

        // What the form sends when somebody changes only the rate: one field.
        $this->patchJson("/api/v1/listings/{$listing->id}", ['base_rate' => 15000])
            ->assertOk();

        $detail = $this->getJson("/api/v1/listings/{$listing->id}")->assertOk()->json('data');

        // The rate is now the listing's own; everything else still follows the
        // property, which is the whole point of showing inherited fields empty.
        $this->assertSame(['base_rate'], $detail['overridden_fields']);
        $this->assertSame(15000, $detail['resolved']['base_rate']);
        $this->assertSame($property->max_occupancy, $detail['resolved']['max_occupancy']);
    }

    public function test_removing_a_listing_needs_the_permission_for_it(): void
    {
        ['organization' => $organization] = $this->createTenantWithAdmin();

        $property = $this->bookableProperty();
        $extra = $this->app->make(ListingService::class)
            ->create($property, ['name' => 'Garden room only']);

        // Someone who may look at listings and change them, but not take them off.
        $user = $this->createUser($organization, [RoleRegistry::RESERVATIONS_AGENT]);
        $this->actingAsUser($user, $organization);

        $this->deleteJson("/api/v1/listings/{$extra->id}")->assertForbidden();
        $this->postJson("/api/v1/listings/{$extra->id}/restore")->assertForbidden();

        $this->assertSame('draft', $extra->refresh()->status->value);
    }

    /**
     * A property that is actually on sale, which is what the guard is about.
     */
    private function bookableProperty(): Property
    {
        $property = $this->app->make(PropertyService::class)->create([
            'name' => 'Alfama Terrace',
            'property_type' => 'apartment',
            'address_line_1' => 'Rua dos Remédios 12',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'max_occupancy' => 4,
            'base_rate' => 12000,
        ]);

        return $this->app->make(PropertyService::class)->activate($property);
    }
}
