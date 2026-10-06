<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Agents\Support\BotAvatar;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Enums\PropertyType;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Giving an agent a face.
 *
 * An operator running a bot per flat finds the right one by its face before
 * they read its name, and until now that meant hosting an image somewhere and
 * typing a URL — which almost nobody does, so every card fell back to a letter.
 *
 * The thing worth testing is the boundary. A chosen face is a key from a fixed
 * list, never a path or an address, and that is what keeps it out of the scheme
 * checking a typed-in URL needs: a value that cannot reach an `img src` cannot
 * carry a `javascript:` payload into a colleague's session. So the catalogue is
 * enforced twice — once at the request, once when the brief is read — and both
 * are asserted here.
 */
class BotAvatarTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    public function test_the_settings_screen_is_offered_every_face_there_is(): void
    {
        $property = $this->property();

        $response = $this->getJson("/api/v1/properties/{$property->getKey()}/agent")->assertOk();

        $avatars = collect($response->json('data.capabilities.avatars'));

        $this->assertCount(20, $avatars, 'Twenty faces ship with the platform.');
        $this->assertEqualsCanonicalizing(BotAvatar::keys(), $avatars->pluck('key')->all());
        // Each carries the name the picker prints under it, so the screen does
        // not keep its own copy of the list to drift from.
        $this->assertSame('Nova', $avatars->firstWhere('key', 'nova')['name']);
    }

    public function test_a_face_is_chosen_by_key_and_comes_back_on_the_property_card(): void
    {
        $property = $this->property();

        $this->patchJson("/api/v1/properties/{$property->getKey()}/agent", [
            'bot_name' => 'Alex',
            'bot_avatar' => 'cobalt',
        ])
            ->assertOk()
            ->assertJsonPath('data.brief.bot_avatar', 'cobalt');

        // The card reads it from the property list, where fifty rows cost no
        // extra queries because it lives in a column already loaded.
        $this->getJson('/api/v1/properties?per_page=5')
            ->assertOk()
            ->assertJsonPath('data.0.agent.avatar', 'cobalt');
    }

    public function test_a_key_that_is_not_a_face_is_refused(): void
    {
        $property = $this->property();

        $this->patchJson("/api/v1/properties/{$property->getKey()}/agent", [
            'bot_avatar' => 'https://example.test/alex.png',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bot_avatar']);

        $this->patchJson("/api/v1/properties/{$property->getKey()}/agent", [
            'bot_avatar' => 'not-a-face',
        ])->assertStatus(422);
    }

    public function test_a_face_that_no_longer_exists_is_dropped_when_the_brief_is_read(): void
    {
        $property = $this->property();

        /*
         * Written straight into the column, around the request rules — which is
         * how a value from an import or an older version of this platform would
         * arrive. The second check is what makes the first one a courtesy
         * rather than the control.
         */
        $property->forceFill([
            'settings' => ['agent' => ['bot_name' => 'Alex', 'bot_avatar' => 'retired_face']],
        ])->save();

        $brief = AgentBrief::fromSettings($property->fresh()->settings);

        // Null, not the stored string: a key with no drawing renders as nothing,
        // and a card showing an empty circle reads as an agent that is broken
        // rather than one that has no picture.
        $this->assertNull($brief->botAvatar);
        $this->assertSame('A', $brief->initial());
    }

    public function test_clearing_the_face_leaves_the_rest_of_the_brief_alone(): void
    {
        $property = $this->property();

        $this->app->make(AgentBriefStore::class)->save($property, [
            'bot_name' => 'Alex',
            'bot_avatar' => 'ember',
            'persona' => 'Warm and brief.',
        ]);

        $this->patchJson("/api/v1/properties/{$property->getKey()}/agent", ['bot_avatar' => null])
            ->assertOk()
            ->assertJsonPath('data.brief.bot_avatar', null)
            // A partial update changes what it names and nothing else.
            ->assertJsonPath('data.brief.bot_name', 'Alex')
            ->assertJsonPath('data.brief.persona', 'Warm and brief.');
    }

    public function test_a_chosen_face_and_a_linked_image_can_both_be_stored(): void
    {
        $property = $this->property();

        $this->patchJson("/api/v1/properties/{$property->getKey()}/agent", [
            'bot_avatar' => 'frost',
            'bot_avatar_url' => 'https://images.example.test/alex.png',
        ])->assertOk();

        $brief = AgentBrief::fromSettings($property->fresh()->settings);

        /*
         * Both kept. The screen prefers the chosen face, but a property set up
         * before the picker existed put that URL there on purpose, and dropping
         * it on the first save afterwards would lose somebody's work.
         */
        $this->assertSame('frost', $brief->botAvatar);
        $this->assertSame('https://images.example.test/alex.png', $brief->botAvatarUrl);
    }

    public function test_a_dangerous_link_is_still_refused_next_to_a_chosen_face(): void
    {
        $property = $this->property();

        /*
         * The new field must not become a way around the scheme check.
         *
         * `bot_avatar_url` ends up in an `img src` that a colleague's browser
         * acts on, so a `javascript:` value there is stored cross-site
         * scripting. Sending a valid face alongside it must not make the request
         * look benign enough to let the link through.
         */
        $this->patchJson("/api/v1/properties/{$property->getKey()}/agent", [
            'bot_avatar' => 'moss',
            'bot_avatar_url' => 'javascript:alert(document.cookie)',
        ])->assertStatus(422);

        $brief = AgentBrief::fromSettings($property->fresh()->settings);

        // The whole save is refused, so the face is not stored either. Better
        // than keeping half of it: a partial write would leave somebody
        // believing the rest of the form had been accepted.
        $this->assertNull($brief->botAvatarUrl);
        $this->assertNull($brief->botAvatar);
    }

    private function property(): Property
    {
        $this->organization = $this->createOrganization();
        $this->user = $this->createUser($this->organization);
        $this->actingAsUser($this->user, $this->organization);

        return $this->app->make(PropertyService::class)->create([
            'name' => 'Yellow Room '.Str::random(5),
            'property_type' => PropertyType::Apartment,
            'address_line_1' => 'Rua dos Remédios 12',
            'postal_code' => '1100-513',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'max_occupancy' => 2,
            'base_rate' => 9000,
        ]);
    }
}
