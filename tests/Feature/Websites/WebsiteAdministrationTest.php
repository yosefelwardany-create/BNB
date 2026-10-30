<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyPhoto;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use App\Domain\Websites\Models\Website;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Booking websites, as the platform owner manages them.
 *
 * A brand site shows properties from every client account; a client site only
 * that client's. Both are the owner's to create, because in the managed
 * service the owner does every operational job and a client's login reads.
 */
class WebsiteAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Organization $harbour;

    private Organization $hills;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->createPlatformAdmin();
        $this->harbour = $this->createOrganization(['name' => 'Harbour Homes']);
        $this->hills = $this->createOrganization(['name' => 'Hill Cottages']);
    }

    public function test_a_platform_admin_creates_a_brand_site_with_listings_from_two_clients(): void
    {
        $site = $this->create(['kind' => 'brand', 'name' => 'Stay With Us', 'slug' => 'stay-with-us'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $sea = $this->listing($this->harbour, 'Sea View Flat');
        $barn = $this->listing($this->hills, 'Old Barn');

        $this->asOperator()
            ->putJson("/api/v1/platform/websites/{$site}/listings", ['listings' => [
                ['listing_id' => $barn->getKey(), 'is_featured' => true],
                ['listing_id' => $sea->getKey(), 'is_featured' => false],
            ]])
            ->assertOk()
            ->assertJsonPath('data.listings.0.title', 'Old Barn')
            ->assertJsonPath('data.listings.0.organization_name', 'Hill Cottages')
            ->assertJsonPath('data.listings.0.is_featured', true)
            ->assertJsonPath('data.listings.1.title', 'Sea View Flat');

        $rows = Website::query()->findOrFail($site)->listings()->orderBy('position')->get();

        $this->assertSame([$this->hills->getKey(), $this->harbour->getKey()], $rows->pluck('organization_id')->all());
        $this->assertSame([0, 1], $rows->pluck('position')->all());
    }

    public function test_a_client_site_takes_only_its_own_clients_listings(): void
    {
        $site = $this->create([
            'kind' => 'client',
            'organization_id' => $this->harbour->getKey(),
            'name' => 'Harbour Homes',
            'slug' => 'harbour-homes',
        ])->assertCreated()->json('data.id');

        // Somebody else's flat on Harbour's own site would be a booking taken
        // in the wrong account.
        $this->asOperator()
            ->putJson("/api/v1/platform/websites/{$site}/listings", ['listings' => [
                ['listing_id' => $this->listing($this->hills, 'Old Barn')->getKey()],
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('listings.0.listing_id');
    }

    public function test_only_bookable_listings_can_be_added(): void
    {
        $site = $this->create(['kind' => 'brand', 'name' => 'Stay With Us', 'slug' => 'stay-here'])->json('data.id');

        $this->actingForOrganization($this->harbour);
        $property = Property::factory()->active()->create(['organization_id' => $this->harbour->getKey()]);
        $draft = Listing::factory()->create([
            'organization_id' => $this->harbour->getKey(),
            'property_id' => $property->getKey(),
        ]);

        $this->asOperator()
            ->putJson("/api/v1/platform/websites/{$site}/listings", ['listings' => [['listing_id' => $draft->getKey()]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('listings.0.listing_id');
    }

    public function test_the_picker_offers_every_property_and_says_what_each_still_needs(): void
    {
        $ready = $this->listing($this->harbour, 'Sea View Flat');
        $publishable = $this->draft($this->harbour, 'Garden Studio');
        $unfinished = $this->draft($this->harbour, 'Old Mill', property: ['status' => 'draft', 'address_line_1' => null, 'base_rate' => 0], photo: false);
        $archived = $this->draft($this->harbour, 'Gone Cottage');
        $archived->forceFill(['status' => 'archived'])->save();

        $options = collect($this->asOperator()
            ->getJson('/api/v1/platform/website-listing-options?organization_id='.$this->harbour->getKey())
            ->assertOk()
            ->json('data'))->keyBy('title');

        // Every home the client has, not just the ones already on sale.
        $this->assertSame(['Garden Studio', 'Old Mill', 'Sea View Flat'], $options->keys()->sort()->values()->all());

        $this->assertTrue($options['Sea View Flat']['ready']);
        $this->assertSame([], $options['Sea View Flat']['needs']);

        $this->assertFalse($options['Garden Studio']['ready']);
        $this->assertTrue($options['Garden Studio']['can_publish']);
        $this->assertSame(['Not published yet.'], $options['Garden Studio']['needs']);

        $this->assertFalse($options['Old Mill']['ready']);
        $this->assertFalse($options['Old Mill']['can_publish']);
        $this->assertFalse($options['Old Mill']['property_active']);
        $this->assertContains('The property needs a complete address.', $options['Old Mill']['needs']);
        $this->assertContains('The property needs a base nightly rate.', $options['Old Mill']['needs']);
        $this->assertContains('At least one photo is required.', $options['Old Mill']['needs']);
    }

    public function test_a_property_ready_to_go_is_published_from_the_website_screen(): void
    {
        $site = $this->create(['kind' => 'client', 'organization_id' => $this->harbour->getKey(), 'name' => 'Harbour Stays', 'slug' => 'harbour'])->json('data.id');
        $draft = $this->draft($this->harbour, 'Garden Studio');

        $this->asOperator()
            ->postJson("/api/v1/platform/website-listing-options/{$draft->getKey()}/publish")
            ->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.needs', []);

        $this->assertSame('published', Listing::query()->withoutGlobalScopes()->findOrFail($draft->getKey())->status->value);

        $this->asOperator()
            ->putJson("/api/v1/platform/websites/{$site}/listings", ['listings' => [['listing_id' => $draft->getKey()]]])
            ->assertOk()
            ->assertJsonPath('data.listings.0.title', 'Garden Studio');
    }

    public function test_a_property_whose_only_gap_is_being_inactive_is_activated_and_published(): void
    {
        $draft = $this->draft($this->harbour, 'Garden Studio', property: ['status' => 'draft']);

        $option = $this->asOperator()
            ->getJson('/api/v1/platform/website-listing-options?organization_id='.$this->harbour->getKey())
            ->json('data.0');
        $this->assertFalse($option['property_active']);
        $this->assertTrue($option['can_publish']);

        $this->asOperator()
            ->postJson("/api/v1/platform/website-listing-options/{$draft->getKey()}/publish")
            ->assertOk()
            ->assertJsonPath('data.ready', true);

        $this->assertSame('active', Property::query()->withoutGlobalScopes()->findOrFail($draft->property_id)->status->value);
    }

    public function test_a_property_that_still_needs_work_is_not_published_and_says_why(): void
    {
        $unfinished = $this->draft($this->harbour, 'Old Mill', photo: false);

        $this->asOperator()
            ->postJson("/api/v1/platform/website-listing-options/{$unfinished->getKey()}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Old Mill is not ready to publish: At least one photo is required.');

        $this->assertSame('draft', Listing::query()->withoutGlobalScopes()->findOrFail($unfinished->getKey())->status->value);
    }

    public function test_only_the_platform_publishes_from_the_website_screen(): void
    {
        $draft = $this->draft($this->harbour, 'Garden Studio');
        $manager = $this->createUser($this->harbour, [RoleRegistry::PROPERTY_MANAGER]);

        // Not found, as everywhere in the platform console.
        $this->actingAsUser($manager, $this->harbour)
            ->postJson("/api/v1/platform/website-listing-options/{$draft->getKey()}/publish")
            ->assertNotFound();

        $this->assertSame('draft', Listing::query()->withoutGlobalScopes()->findOrFail($draft->getKey())->status->value);
    }

    public function test_slugs_are_lowercase_unique_and_not_reserved(): void
    {
        $this->create(['kind' => 'brand', 'name' => 'Stay', 'slug' => 'sea-view'])->assertCreated();

        foreach (['Sea View', 'api', 'sea-view', 'stay--twice', '-edge'] as $slug) {
            $this->create(['kind' => 'brand', 'name' => 'Another', 'slug' => $slug])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('slug');
        }

        $this->create(['kind' => 'brand', 'name' => 'Another', 'slug' => 'sea-view-2'])->assertCreated();
    }

    public function test_someone_who_is_not_a_platform_admin_cannot_manage_sites(): void
    {
        $this->actingForOrganization($this->harbour);
        $admin = $this->createUser($this->harbour, [RoleRegistry::ORGANIZATION_ADMIN]);

        // Not found rather than forbidden, as everywhere in the platform
        // console: its existence is not something to confirm to a client.
        $this->actingAsUser($admin, $this->harbour)->getJson('/api/v1/platform/websites')->assertNotFound();
        $this->actingAsUser($admin, $this->harbour)
            ->postJson('/api/v1/platform/websites', ['kind' => 'brand', 'name' => 'Mine', 'slug' => 'mine'])
            ->assertNotFound();
        $this->assertSame(0, Website::query()->count());
    }

    public function test_publishing_needs_at_least_one_listing(): void
    {
        $site = $this->create(['kind' => 'brand', 'name' => 'Stay', 'slug' => 'stay-here'])->json('data.id');

        $this->asOperator()
            ->postJson("/api/v1/platform/websites/{$site}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Add at least one property before publishing.');

        $this->asOperator()->putJson("/api/v1/platform/websites/{$site}/listings", ['listings' => [
            ['listing_id' => $this->listing($this->harbour, 'Sea View Flat')->getKey()],
        ]]);

        $this->asOperator()
            ->postJson("/api/v1/platform/websites/{$site}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'live');
        $this->assertNotNull(Website::query()->findOrFail($site)->published_at);

        $this->asOperator()
            ->postJson("/api/v1/platform/websites/{$site}/unpublish")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_the_contact_details_are_saved_and_the_look_is_left_to_the_page_builder(): void
    {
        $site = $this->create(['kind' => 'brand', 'name' => 'Stay', 'slug' => 'stay-here'])->json('data.id');

        $this->asOperator()
            ->patchJson("/api/v1/platform/websites/{$site}", ['settings' => [
                'heading' => 'Homes by the sea',
                'primary_colour' => '#0F4C5C',
                'contact_email' => 'hello@stay.test',
                'instagram_url' => 'https://instagram.test/stay',
            ]])
            ->assertOk()
            ->assertJsonPath('data.settings.contact_email', 'hello@stay.test')
            ->assertJsonPath('data.settings.instagram_url', 'https://instagram.test/stay')
            ->assertJsonMissingPath('data.settings.heading')
            ->assertJsonMissingPath('data.settings.primary_colour');

        // The heading and colours belong to the page builder's document now.
        $this->assertNull(Website::query()->findOrFail($site)->setting('heading'));

        $this->asOperator()
            ->patchJson("/api/v1/platform/websites/{$site}", ['settings' => ['contact_email' => 'not an email']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('settings.contact_email');
    }

    public function test_a_logo_upload_is_stored_and_reported(): void
    {
        Storage::fake('media');

        $site = $this->create(['kind' => 'brand', 'name' => 'Stay', 'slug' => 'stay-here'])->json('data.id');

        $this->asOperator()
            ->post("/api/v1/platform/websites/{$site}/media", [
                'kind' => 'logo',
                'file' => UploadedFile::fake()->image('logo.png', 400, 120),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.settings.logo_url', fn (?string $url): bool => $url !== null);

        $path = Website::query()->findOrFail($site)->settings['logo_path'] ?? null;
        $this->assertNotNull($path);
        Storage::disk('media')->assertExists($path);
    }

    // ------------------------------------------------------------------

    private function asOperator(): static
    {
        return $this->actingAs($this->operator->fresh(), 'sanctum');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(array $attributes): TestResponse
    {
        return $this->asOperator()->postJson('/api/v1/platform/websites', $attributes);
    }

    private function listing(Organization $organization, string $title): Listing
    {
        $this->actingForOrganization($organization);

        $property = Property::factory()->active()->create(['organization_id' => $organization->getKey()]);

        return Listing::factory()->published()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $property->getKey(),
            'title' => $title,
        ]);
    }

    /**
     * A draft listing on an active property, ready to publish unless told otherwise.
     *
     * @param  array<string, mixed>  $property
     */
    private function draft(Organization $organization, string $title, array $property = [], bool $photo = true): Listing
    {
        $this->actingForOrganization($organization);

        $home = Property::factory()->active()->create(array_merge([
            'organization_id' => $organization->getKey(),
            'name' => $title,
            'description' => 'A quiet home by the water.',
            'address_line_1' => '1 Harbour Road',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'base_rate' => 12000,
            'max_occupancy' => 4,
        ], $property));

        if ($photo) {
            PropertyPhoto::query()->create([
                'organization_id' => $organization->getKey(),
                'property_id' => $home->getKey(),
                'disk' => 'local',
                'path' => 'photos/'.$home->getKey().'.jpg',
                'mime_type' => 'image/jpeg',
            ]);
        }

        $listing = Listing::factory()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $home->getKey(),
            'title' => $title,
        ]);

        $this->app->make(TenantContext::class)->clear();

        return $listing;
    }
}
