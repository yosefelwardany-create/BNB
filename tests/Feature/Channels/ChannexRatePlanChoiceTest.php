<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelListingImporter;
use App\Domain\Integrations\DataObjects\ChannelRateUpdate;
use App\Domain\Integrations\Providers\Channels\ChannexChannelAdapter;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Which Channex rate plan a room type's prices are published under.
 *
 * A room type with one plan has no question to answer. A room type with
 * several — Channex's own certification setup gives every room a Best
 * Available Rate and a Bed & Breakfast Rate — does, and the import used to
 * answer it by taking whichever sorted first. Prices then went to a plan
 * nobody chose while the other kept whatever it had, and nothing on any
 * screen said so.
 */
class ChannexRatePlanChoiceTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY = '716305c4-561a-4561-a187-7f5b8aeb5920';

    private const ROOM_TYPE = 'aaaa0000-0000-4000-8000-000000000001';

    private const BAR = 'bbbb0000-0000-4000-8000-000000000001';

    private const BED_AND_BREAKFAST = 'bbbb0000-0000-4000-8000-000000000002';

    private Organization $organization;

    private ChannelAccount $account;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'USD']);
        $this->admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);

        $this->account = ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'channex',
            'name' => 'Channex — staging',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'credentials' => ['api_key' => 'test-key'],
            'sync_availability' => true,
            'sync_rates' => true,
        ]);
    }

    public function test_a_room_type_with_several_plans_waits_for_a_choice(): void
    {
        $this->fakeCatalogue([
            $this->plan(self::BAR, 'Best Available Rate', 'USD'),
            $this->plan(self::BED_AND_BREAKFAST, 'Bed & Breakfast Rate', 'USD'),
        ]);

        $mapping = $this->import();

        $meta = $mapping->metadata['channex'];

        $this->assertArrayNotHasKey('rate_plan_id', $meta, 'Nobody chose one, so none is chosen.');
        $this->assertSame(2, $meta['rate_plan_count']);
        $this->assertSame(
            [
                ['id' => self::BAR, 'title' => 'Best Available Rate', 'currency' => 'USD'],
                ['id' => self::BED_AND_BREAKFAST, 'title' => 'Bed & Breakfast Rate', 'currency' => 'USD'],
            ],
            $meta['rate_plans'],
        );
    }

    public function test_choosing_a_plan_survives_the_next_import(): void
    {
        $this->fakeCatalogue([
            $this->plan(self::BAR, 'Best Available Rate', 'USD'),
            $this->plan(self::BED_AND_BREAKFAST, 'Bed & Breakfast Rate', 'USD'),
        ]);

        $mapping = $this->import();
        $this->choose($mapping, self::BED_AND_BREAKFAST)->assertOk();

        // The catalogue is read again, as it is whenever somebody pulls. The
        // operator's answer must still be there afterwards.
        $mapping = $this->import();

        $this->assertSame(self::BED_AND_BREAKFAST, $mapping->metadata['channex']['rate_plan_id'] ?? null);
    }

    public function test_the_choice_is_made_through_the_mapping_and_reported_back(): void
    {
        $this->fakeCatalogue([
            $this->plan(self::BAR, 'Best Available Rate', 'USD'),
            $this->plan(self::BED_AND_BREAKFAST, 'Bed & Breakfast Rate', 'GBP'),
        ]);

        $mapping = $this->import();
        $mapping->forceFill(['rates_dirty' => false])->save();

        $this->choose($mapping, self::BED_AND_BREAKFAST)
            ->assertOk()
            ->assertJsonPath('data.external_rate_plan_id', self::BED_AND_BREAKFAST)
            ->assertJsonCount(2, 'data.external_rate_plans')
            ->assertJsonPath('data.external_rate_plans.1.title', 'Bed & Breakfast Rate');

        $mapping->refresh();
        $this->assertSame(self::BED_AND_BREAKFAST, $mapping->metadata['channex']['rate_plan_id']);
        $this->assertSame('GBP', $mapping->metadata['channex']['currency'], 'The currency is the chosen plan\'s.');

        // The plan just chosen has never been sent a price.
        $this->assertTrue($mapping->rates_dirty);
    }

    public function test_only_a_plan_the_room_type_has_can_be_chosen(): void
    {
        $this->fakeCatalogue([
            $this->plan(self::BAR, 'Best Available Rate', 'USD'),
            $this->plan(self::BED_AND_BREAKFAST, 'Bed & Breakfast Rate', 'USD'),
        ]);

        $mapping = $this->import();

        // Channex would refuse an id that is not this room type's, inside a
        // 200, on every push from then on.
        $this->choose($mapping, 'cccc0000-0000-4000-8000-000000000009')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('external_rate_plan_id');
    }

    public function test_a_plan_that_has_gone_from_channex_is_refused_by_name(): void
    {
        Http::fake();

        $mapping = $this->mappingWith([
            'property_id' => self::PROPERTY,
            'rate_plan_id' => self::BAR,
            // Re-imported since: the chosen plan is no longer offered.
            'rate_plans' => [['id' => self::BED_AND_BREAKFAST, 'title' => 'Bed & Breakfast Rate', 'currency' => 'USD']],
        ]);

        $result = (new ChannexChannelAdapter)->pushRates($mapping, $this->rates('USD'));

        $this->assertFalse($result->successful);
        $this->assertStringContainsString('no longer', (string) $result->errorMessage);
        Http::assertNothingSent();
    }

    public function test_the_currency_checked_is_the_chosen_plans(): void
    {
        Http::fake();

        $mapping = $this->mappingWith([
            'property_id' => self::PROPERTY,
            'rate_plan_id' => self::BED_AND_BREAKFAST,
            'rate_plans' => [
                ['id' => self::BAR, 'title' => 'Best Available Rate', 'currency' => 'USD'],
                ['id' => self::BED_AND_BREAKFAST, 'title' => 'Bed & Breakfast Rate', 'currency' => 'GBP'],
            ],
        ]);

        $result = (new ChannexChannelAdapter)->pushRates($mapping, $this->rates('USD'));

        $this->assertSame('currency_mismatch', $result->errorCode);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $plans
     */
    private function fakeCatalogue(array $plans): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/properties*' => Http::response($this->page([])),
            '*/room_types*' => Http::response($this->page([[
                'type' => 'room_type',
                'id' => self::ROOM_TYPE,
                'attributes' => ['id' => self::ROOM_TYPE, 'title' => 'Twin Room', 'occ_adults' => 2],
                'relationships' => ['property' => ['data' => ['id' => self::PROPERTY, 'type' => 'property']]],
            ]])),
            '*/rate_plans*' => Http::response($this->page($plans)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(string $id, string $title, string $currency): array
    {
        return [
            'type' => 'rate_plan',
            'id' => $id,
            'attributes' => ['id' => $id, 'title' => $title, 'currency' => $currency, 'sell_mode' => 'per_room'],
            'relationships' => [
                'property' => ['data' => ['id' => self::PROPERTY, 'type' => 'property']],
                'room_type' => ['data' => ['id' => self::ROOM_TYPE, 'type' => 'room_type']],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function page(array $rows): array
    {
        return ['data' => $rows, 'meta' => ['total' => count($rows), 'limit' => 100, 'page' => 1]];
    }

    private function import(): ChannelListing
    {
        $this->app->make(ChannelListingImporter::class)->importFor($this->account);

        return ChannelListing::query()
            ->where('channel_account_id', $this->account->getKey())
            ->where('external_listing_id', self::ROOM_TYPE)
            ->firstOrFail();
    }

    private function choose(ChannelListing $mapping, string $ratePlanId): TestResponse
    {
        return $this->actingAsUser($this->admin, $this->organization)
            ->patchJson("/api/v1/channel-listings/{$mapping->getKey()}", ['external_rate_plan_id' => $ratePlanId]);
    }

    /**
     * @param  array<string, mixed>  $channex
     */
    private function mappingWith(array $channex): ChannelListing
    {
        $property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'USD',
        ]);

        $listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $property->getKey(),
            'currency' => 'USD',
        ]);

        return ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $this->account->getKey(),
            'listing_id' => $listing->getKey(),
            'property_id' => $property->getKey(),
            'external_listing_id' => self::ROOM_TYPE,
            'metadata' => ['channex' => $channex],
        ])->load('account');
    }

    private function rates(string $currency): ChannelRateUpdate
    {
        return new ChannelRateUpdate(
            from: new DateTimeImmutable('2027-07-01'),
            to: new DateTimeImmutable('2027-07-01'),
            currency: $currency,
            nightlyRates: ['2027-07-01' => 20000],
        );
    }
}
