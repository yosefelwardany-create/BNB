<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Agents\Services\ScriptedAIProvider;
use App\Domain\Integrations\Registries\AIProviderRegistry;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The agent as the property list shows it.
 *
 * The people running these flats think in names before addresses — Alex on the
 * third floor, David in the annexe — so the card leads with who manages the
 * place. That means the property list has to carry its agent, and carry it
 * without a query per card.
 */
class PropertyCardAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_property_list_names_the_agent_without_leaking_its_credentials(): void
    {
        $property = $this->propertyWithAgent([
            'bot_name' => 'Alex',
            'provider' => 'bot',
            'bot_url' => 'https://bots.example.com/alex',
            'bot_token' => 'secret-token-123',
            'knowledge_base_url' => 'https://docs.example.com/alex',
        ]);

        $response = $this->getJson('/api/v1/properties')
            ->assertOk()
            ->assertJsonPath('data.0.agent.name', 'Alex')
            // The badge when there is no picture.
            ->assertJsonPath('data.0.agent.initial', 'A')
            ->assertJsonPath('data.0.agent.can_answer', true)
            ->assertJsonPath('data.0.agent.knowledge_base_url', 'https://docs.example.com/alex');

        // A property list is read by anybody who may view properties. Whether a
        // token is stored belongs on the screen that configures one.
        $this->assertStringNotContainsString('secret-token-123', $response->getContent());
        $this->assertSame($property->getKey(), $response->json('data.0.id'));
    }

    public function test_a_property_with_no_agent_says_so_rather_than_inventing_one(): void
    {
        $this->propertyWithAgent([]);

        $this->getJson('/api/v1/properties')
            ->assertOk()
            ->assertJsonPath('data.0.agent.name', null)
            // A stray letter over an unnamed agent would imply one is there.
            ->assertJsonPath('data.0.agent.initial', null)
            ->assertJsonPath('data.0.agent.can_answer', false);
    }

    public function test_an_enabled_agent_inherits_the_live_account_provider_for_both_card_and_chat(): void
    {
        $provider = new ScriptedAIProvider('Inherited provider answered.');
        app(AIProviderRegistry::class)->register('scripted', fn () => $provider);
        config(['pms.providers.ai' => 'scripted']);
        $property = $this->propertyWithAgent(['bot_name' => 'Alex']);
        $this->getJson('/api/v1/properties')->assertOk()
            ->assertJsonPath('data.0.agent.can_answer', true)
            ->assertJsonPath('data.0.agent.provider', 'scripted')
            ->assertJsonPath('data.0.agent.is_simulated', false);
        $this->postJson('/api/v1/properties/'.$property->id.'/agent/ask', ['question' => 'How is the property doing?', 'audience' => 'operator'])
            ->assertOk()->assertJsonPath('data.answer.reply', 'Inherited provider answered.');
    }

    public function test_an_enabled_default_demo_agent_is_identified_as_simulated_instead_of_disconnected(): void
    {
        config(['pms.providers.ai' => 'echo']);
        $this->propertyWithAgent(['bot_name' => 'Alex']);
        $this->getJson('/api/v1/properties')->assertOk()
            ->assertJsonPath('data.0.agent.can_answer', true)
            ->assertJsonPath('data.0.agent.is_simulated', true)
            ->assertJsonPath('data.0.agent.connection_message', 'Demo mode: replies are simulated. Choose a live AI provider in agent settings.');
    }

    public function test_enabling_an_agent_without_provider_credentials_does_not_claim_a_live_connection(): void
    {
        config(['pms.providers.ai' => 'claude', 'services.anthropic.key' => null]);
        $this->propertyWithAgent(['bot_name' => 'Alex']);
        $this->getJson('/api/v1/properties')->assertOk()
            ->assertJsonPath('data.0.agent.enabled', true)
            ->assertJsonPath('data.0.agent.can_answer', false)
            ->assertJsonPath('data.0.agent.connection_message', 'The agent is enabled, but its AI provider or bot connection still needs configuration.');
    }

    public function test_the_account_demo_provider_does_not_intercept_a_configured_webhook_bot(): void
    {
        config(['pms.providers.ai' => 'echo']);
        $this->propertyWithAgent(['bot_name' => 'Alex', 'webhook_url' => 'https://bots.example.com/deferred']);
        $this->getJson('/api/v1/properties')->assertOk()
            ->assertJsonPath('data.0.agent.can_answer', false)
            ->assertJsonPath('data.0.agent.can_be_asked_later', true)
            ->assertJsonPath('data.0.agent.is_simulated', false)
            ->assertJsonPath('data.0.agent.connection_message', null);
    }

    public function test_a_link_the_card_renders_cannot_carry_a_script(): void
    {
        $property = $this->propertyWithAgent([]);

        /*
         * These two are rendered into an `href` and an `img src`. An unchecked
         * scheme makes this stored cross-site scripting: one member of staff
         * types it, another clicks it, and it runs in the second one's session.
         */
        foreach (['knowledge_base_url', 'bot_avatar_url'] as $field) {
            $this->patchJson("/api/v1/properties/{$property->getKey()}/agent", [
                $field => 'javascript:alert(document.cookie)',
            ])->assertStatus(422);
        }

        $this->patchJson("/api/v1/properties/{$property->getKey()}/agent", [
            'knowledge_base_url' => 'https://docs.example.com/alex',
        ])->assertOk()->assertJsonPath('data.brief.knowledge_base_url', 'https://docs.example.com/alex');
    }

    public function test_the_list_costs_no_extra_queries_per_property(): void
    {
        // Three properties, because an N+1 is invisible at one row: Eloquent
        // suppresses the lazy-loading violation for a single-model query, so a
        // relation here would pass a one-property test and fail in front of a
        // portfolio.
        $organization = $this->createOrganization();
        $this->actingAsUser($this->createUser($organization), $organization);

        foreach (['Alex', 'David', 'George'] as $name) {
            $this->propertyWithAgent(['bot_name' => $name], fresh: false);
        }

        $queries = 0;
        \DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->getJson('/api/v1/properties')->assertOk()->assertJsonCount(3, 'data');

        // The agent is read from a column already on the row. Whatever the list
        // costs, it does not grow with the number of agents named.
        $this->assertLessThan(20, $queries);
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    private function propertyWithAgent(array $brief, bool $fresh = true): Property
    {
        if ($fresh) {
            $organization = $this->createOrganization();
            $this->actingAsUser($this->createUser($organization), $organization);
        }

        $property = $this->app->make(PropertyService::class)->create([
            'name' => 'Yellow Room '.uniqid(),
            'property_type' => 'apartment',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'max_occupancy' => 2,
            'base_rate' => 9000,
        ]);

        if ($brief !== []) {
            $this->app->make(AgentBriefStore::class)->save($property, $brief + ['enabled' => true]);
        }

        return $property->fresh();
    }
}
