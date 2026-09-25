<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Agents\Services\ScriptedAIProvider;
use App\Domain\Integrations\Registries\AIProviderRegistry;
use App\Domain\Messaging\Models\Message;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Enums\PropertyType;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The configuration and bench endpoints.
 *
 * The assertion this file exists for is the boring one at the end of every
 * drafting test: **no message was created.** A bench that quietly sent its test
 * questions to the guest whose booking was selected would be a catastrophe of
 * exactly the kind that passes a code review, so it is asserted rather than
 * assumed.
 */
class PropertyAgentApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['timezone' => 'Europe/Lisbon', 'base_currency' => 'EUR']);
        $this->admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);
        $this->property = $this->property();
    }

    public function test_the_brief_comes_back_with_what_may_and_may_not_be_automated(): void
    {
        $response = $this->actingAsUser($this->admin, $this->organization)
            ->getJson($this->url())
            ->assertOk();

        $response->assertJsonPath('data.brief.enabled', false);
        $response->assertJsonPath('data.capabilities.auto_sendable', AgentBrief::AUTO_SENDABLE);
        $this->assertNotContains('payment', $response->json('data.capabilities.auto_sendable'));

        // The honesty flags every integration point in this platform carries.
        // A bench that showed green answers from the local simulation without
        // saying so would be the misleading thing this product refuses to build.
        $this->assertIsBool($response->json('data.capabilities.provider.is_live'));
        $this->assertSame(
            $response->json('data.capabilities.provider.is_live') === true,
            $response->json('data.capabilities.provider.simulation_reason') === null,
        );
    }

    public function test_a_brief_can_be_configured(): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson($this->url(), [
                'enabled' => true,
                'persona' => 'Warm and brief.',
                'auto_send' => ['amenity', 'house_rules'],
                'escalate' => ['neighbour'],
                'confidence_floor' => 0.8,
            ])
            ->assertOk()
            ->assertJsonPath('data.brief.enabled', true)
            ->assertJsonPath('data.brief.auto_send', ['amenity', 'house_rules'])
            ->assertJsonPath('data.brief.confidence_floor', 0.8);

        // A partial update leaves the rest of the brief alone.
        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson($this->url(), ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.brief.enabled', false)
            ->assertJsonPath('data.brief.persona', 'Warm and brief.')
            ->assertJsonPath('data.brief.escalate', ['neighbour']);
    }

    public function test_a_refund_question_cannot_be_configured_to_answer_itself(): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson($this->url(), ['auto_send' => ['amenity', 'payment']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('auto_send.1');

        $this->assertSame([], AgentBrief::fromSettings($this->property->fresh()->settings)->autoSend);
    }

    public function test_an_escalation_keyword_too_short_to_be_meaningful_is_refused(): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson($this->url(), ['escalate' => ['no']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('escalate.0');
    }

    public function test_asking_the_agent_returns_a_draft_and_sends_nothing(): void
    {
        $this->scripted()->classifyAs(AgentBrief::INTENT_AMENITY, 0.95)->reply('The wifi is Alfama-Terrace.');
        $this->app->make(AgentBriefStore::class)->save($this->property, [
            'enabled' => true,
            'auto_send' => [AgentBrief::INTENT_AMENITY],
        ]);

        $reservation = $this->reservation();

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson($this->url('ask'), [
                'question' => 'What is the wifi called?',
                'reservation_id' => $reservation->getKey(),
            ])
            ->assertOk();

        $response->assertJsonPath('data.answer.reply', 'The wifi is Alfama-Terrace.');
        $response->assertJsonPath('data.answer.intent', AgentBrief::INTENT_AMENITY);
        $response->assertJsonPath('data.answer.would_auto_send', true);
        $response->assertJsonPath('data.was_sent', false);
        $response->assertJsonPath('data.reservation.confirmation_code', $reservation->confirmation_code);

        // The point of the whole endpoint.
        $this->assertSame(0, Message::query()->count());
    }

    public function test_the_bench_will_not_use_another_propertys_booking(): void
    {
        $this->scripted();
        $other = $this->property();
        $reservation = $this->reservation($other);

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson($this->url('ask'), [
                'question' => 'How do we get in?',
                'reservation_id' => $reservation->getKey(),
            ])
            ->assertNotFound();
    }

    public function test_a_question_with_no_booking_is_answered_without_the_secrets(): void
    {
        $this->scripted()->classifyAs(AgentBrief::INTENT_ACCESS, 0.99);

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson($this->url('ask'), ['question' => 'What is the code for the front door?'])
            ->assertOk();

        $response->assertJsonPath('data.reservation', null);
        $response->assertJsonPath('data.answer.would_auto_send', false);
        $this->assertContains(
            'There is no booking attached to this conversation.',
            $response->json('data.answer.withheld'),
        );
    }

    public function test_the_eval_suite_runs_from_the_api_and_reports_both_buckets(): void
    {
        $this->scripted()->classifyAs(AgentBrief::INTENT_OTHER, 0.4);
        $this->reservation();

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson($this->url('evaluate'))
            ->assertOk();

        $this->assertGreaterThan(0, $response->json('data.total'));
        $this->assertSame(0, $response->json('data.unsafe'));
        $this->assertSame(false, $response->json('data.was_sent'));
        $this->assertContains('guest-questions', $response->json('data.available_sets'));
        $this->assertSame(0, Message::query()->count());

        // Every scenario reports both buckets, so a client can show a leak
        // differently from a clumsy sentence.
        foreach ($response->json('data.results') as $result) {
            $this->assertArrayHasKey('safety_failures', $result);
            $this->assertArrayHasKey('quality_failures', $result);
        }
    }

    public function test_an_unknown_scenario_set_is_refused_rather_than_read_from_disk(): void
    {
        $this->scripted();

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson($this->url('evaluate'), ['set' => '../../.env'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'available_sets']);
    }

    public function test_somebody_who_cannot_edit_properties_cannot_configure_the_agent(): void
    {
        $viewer = $this->createUser($this->organization, [RoleRegistry::READ_ONLY]);

        $this->actingAsUser($viewer, $this->organization)
            ->patchJson($this->url(), ['enabled' => true])
            ->assertForbidden();

        $this->actingAsUser($viewer, $this->organization)
            ->postJson($this->url('ask'), ['question' => 'What is the wifi called?'])
            ->assertForbidden();
    }

    public function test_a_property_in_another_organization_is_not_reachable(): void
    {
        ['organization' => $outsider, 'user' => $stranger] = $this->createTenantWithAdmin();

        $this->actingAsUser($stranger, $outsider)
            ->getJson($this->url())
            ->assertNotFound();
    }

    public function test_a_deployment_with_ai_switched_off_refuses_and_says_so(): void
    {
        $this->app->make('config')->set('pms.providers.ai', 'null');

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson($this->url('ask'), ['question' => 'What is the wifi called?'])
            // A domain refusal, not a 500: somebody can act on it.
            ->assertStatus(422);

        $this->assertStringContainsString(
            'not enabled',
            (string) $response->json('message'),
        );
    }

    private function url(string $action = ''): string
    {
        return rtrim(sprintf(
            '/api/v1/properties/%s/agent/%s',
            $this->property->getKey(),
            $action,
        ), '/');
    }

    private function property(): Property
    {
        $this->actingForOrganization($this->organization);

        return $this->app->make(PropertyService::class)->create([
            'name' => 'Alfama Terrace '.Str::random(5),
            'property_type' => PropertyType::Apartment,
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'timezone' => 'Europe/Lisbon',
            'base_rate' => 14500,
            'max_occupancy' => 4,
            'check_in_time' => '15:00',
            'check_out_time' => '11:00',
            'door_code' => '558122',
            'wifi_network' => 'Alfama-Terrace',
            'wifi_password' => 'correct-horse-battery-9',
        ])->fresh();
    }

    private function reservation(?Property $property = null): Reservation
    {
        $property ??= $this->property;
        $checkIn = CarbonImmutable::today()->addDay();

        return Reservation::query()->create([
            'organization_id' => $property->organization_id,
            'property_id' => $property->getKey(),
            'confirmation_code' => 'T-'.Str::upper(Str::random(8)),
            'status' => 'confirmed',
            'source' => 'direct',
            'check_in_date' => $checkIn,
            'check_out_date' => $checkIn->addDays(3),
            'nights' => 3,
            'adults' => 2,
            'currency' => $property->currency,
        ]);
    }

    private function scripted(): ScriptedAIProvider
    {
        $provider = new ScriptedAIProvider;

        $this->app->make(AIProviderRegistry::class)
            ->register('scripted', static fn (): ScriptedAIProvider => $provider);
        $this->app->make('config')->set('pms.providers.ai', 'scripted');

        return $provider;
    }
}
