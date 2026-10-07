<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Agents\Services\OperatorActions;
use App\Domain\Agents\Services\OperatorKnowledge;
use App\Domain\Agents\Services\PropertyKnowledge;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The property's own knowledge base, which the agent adds to and corrects.
 *
 * The case that prompted it: a manager told the agent who the cleaner and the
 * maintenance man are, the agent had nowhere to keep that but a conversation
 * note, and it failed for want of a conversation.
 */
class TeamKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization();
        $this->manager = $this->createUser($this->organization, attributes: ['first_name' => 'Yosef', 'last_name' => 'E']);
        $this->actingAsUser($this->manager, $this->organization);
        $this->property = $this->makeProperty('Blue Room');
    }

    private function makeProperty(string $name): Property
    {
        $property = $this->app->make(PropertyService::class)->create([
            'name' => $name,
            'property_type' => 'apartment',
            'address_line_1' => '12 King St',
            'postal_code' => 'M5H 1A1',
            'city' => 'Toronto',
            'country_code' => 'CA',
            'max_occupancy' => 2,
            'base_rate' => 9000,
        ]);
        $this->app->make(AgentBriefStore::class)->save($property, ['enabled' => true, 'bot_name' => 'Baily']);

        return $property->fresh();
    }

    private function reply(array $payload, ?User $user = null, bool $live = true): string
    {
        return app(OperatorActions::class)->respond($this->property, $user ?? $this->manager, (string) json_encode($payload), $live);
    }

    public function test_the_agent_saves_what_the_manager_told_it_for_every_later_chat(): void
    {
        $reply = $this->reply(['knowledge' => [
            ['topic' => 'Maintenance', 'content' => 'Abu Nafi, +1 (416) 939-0128, speaks Arabic. No email.'],
            ['topic' => 'Cleaner', 'content' => 'Irish, 416 576 4750. Schedule 3 days before cleaning.'],
        ]]);

        $this->assertStringContainsString('**Maintenance** — added', $reply);
        $this->assertStringContainsString('**Cleaner** — added', $reply);
        $this->assertStringNotContainsString('ULID', $reply);
        $this->assertDatabaseCount('property_knowledge_entries', 2);
        $this->assertDatabaseHas('agent_activities', ['property_id' => $this->property->id, 'summary' => 'Added to the knowledge base: Cleaner']);

        // The next chat, by any colleague, is told.
        $colleague = $this->createUser($this->organization, [RoleRegistry::RESERVATIONS_AGENT]);
        $facts = app(OperatorKnowledge::class)->about($this->property, $colleague, 'who is the cleaner?')['facts'];

        $this->assertSame(
            ['Cleaner', 'Maintenance'],
            array_column($facts['team_knowledge'], 'topic'),
        );
        $this->assertStringContainsString('416 576 4750', $facts['team_knowledge'][0]['fact']);
    }

    public function test_saving_under_the_same_topic_corrects_it_instead_of_adding_a_second(): void
    {
        $this->reply(['knowledge' => ['topic' => 'Cleaner', 'content' => 'Irish, 416 576 4750']]);

        $reply = $this->reply(['knowledge' => ['topic' => ' cleaner ', 'content' => 'Maria, 416 000 1111 (from November)']]);

        $this->assertStringContainsString('updated', $reply);
        $this->assertDatabaseCount('property_knowledge_entries', 1);
        $this->assertDatabaseHas('property_knowledge_entries', ['topic' => 'cleaner', 'content' => 'Maria, 416 000 1111 (from November)']);

        $this->assertStringContainsString('nothing changed', $this->reply(['knowledge' => ['topic' => 'cleaner', 'content' => 'Maria, 416 000 1111 (from November)']]));
    }

    public function test_nothing_is_saved_by_a_simulation_or_without_permission_to_edit_the_property(): void
    {
        $payload = ['knowledge' => ['topic' => 'Cleaner', 'content' => 'Irish']];

        $this->assertStringContainsString('simulated', $this->reply($payload, live: false));
        $this->assertStringContainsString('permission', $this->reply($payload, $this->createUser($this->organization, [RoleRegistry::READ_ONLY])));
        $this->assertStringContainsString('permission', app(OperatorActions::class)->respond($this->property, null, (string) json_encode($payload)));

        $this->assertDatabaseCount('property_knowledge_entries', 0);
    }

    public function test_a_malformed_fact_is_refused_without_saving_it(): void
    {
        $reply = $this->reply(['knowledge' => [
            ['topic' => '', 'content' => 'Irish'],
            ['topic' => 'Cleaner', 'content' => 'Irish', 'property_id' => 'x'],
            ['topic' => 'Wi-Fi router', 'content' => 'In the hall cupboard'],
        ]]);

        $this->assertSame(2, substr_count($reply, 'Not saved'));
        $this->assertDatabaseCount('property_knowledge_entries', 1);
    }

    public function test_a_note_with_no_conversation_points_to_the_knowledge_base_rather_than_an_identifier(): void
    {
        $this->app->make(AgentBriefStore::class)->save($this->property, ['enabled' => true, 'may_do' => ['add_note'], 'may_do_alone' => ['add_note']]);
        $this->property->refresh();

        $reply = $this->reply(['action' => ['capability' => 'add_note', 'arguments' => ['body' => 'Cleaner: Irish']]]);

        $this->assertStringContainsString('knowledge base', $reply);
        $this->assertStringNotContainsString('ULID', $reply);
    }

    public function test_the_agent_is_told_how_to_save_and_guests_are_never_given_it(): void
    {
        $this->reply(['knowledge' => ['topic' => 'Cleaner', 'content' => 'Irish, 416 576 4750']]);

        $this->assertStringContainsString('{"knowledge":', OperatorActions::instruction());
        $this->assertStringNotContainsString('416 576 4750', (string) json_encode(app(PropertyKnowledge::class)->public($this->property)));
    }

    public function test_people_can_read_add_edit_and_delete_entries(): void
    {
        $base = "/api/v1/properties/{$this->property->id}/agent/knowledge";

        $id = $this->postJson($base, ['topic' => 'Spare key', 'content' => 'With the concierge'])
            ->assertCreated()
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.updated_by', 'Yosef E')
            ->json('data.id');
        $this->postJson($base, ['topic' => 'Cleaner', 'content' => 'Irish'])->assertCreated();

        $this->patchJson("{$base}/{$id}", ['topic' => 'Spare key', 'content' => 'In the lockbox, code with the manager'])
            ->assertOk()
            ->assertJsonPath('data.content', 'In the lockbox, code with the manager');

        // Renaming onto another entry would merge two facts silently.
        $this->patchJson("{$base}/{$id}", ['topic' => 'cleaner', 'content' => 'x'])->assertStatus(422);

        $this->getJson($base)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.topic', 'Cleaner');

        $this->deleteJson("{$base}/{$id}")->assertOk();
        $this->assertDatabaseCount('property_knowledge_entries', 1);
    }

    public function test_reading_is_open_to_the_team_but_changing_needs_edit_rights_and_the_right_property(): void
    {
        $base = "/api/v1/properties/{$this->property->id}/agent/knowledge";
        $id = $this->postJson($base, ['topic' => 'Cleaner', 'content' => 'Irish'])->json('data.id');

        $other = $this->makeProperty('Green Room');
        $this->deleteJson("/api/v1/properties/{$other->id}/agent/knowledge/{$id}")->assertNotFound();

        $viewer = $this->createUser($this->organization, [RoleRegistry::READ_ONLY]);
        $this->actingAsUser($viewer, $this->organization);

        $this->getJson($base)->assertOk()->assertJsonCount(1, 'data');
        $this->postJson($base, ['topic' => 'Plumber', 'content' => 'Bob'])->assertForbidden();
        $this->deleteJson("{$base}/{$id}")->assertForbidden();
        $this->assertDatabaseCount('property_knowledge_entries', 1);
    }
}
