<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Agents\Services\ScriptedAIProvider;
use App\Domain\Integrations\Registries\AIProviderRegistry;
use App\Domain\Listings\Models\Listing;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConversationService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Drafting a reply to a real thread.
 *
 * Two claims: the draft is built from the thread's own history and booking, and
 * producing it is not sending it. The second is asserted by counting messages
 * before and after, because "the agent replied to a guest during a test" is the
 * failure nobody notices until a guest answers.
 */
class ConversationAgentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private Property $property;

    private Listing $listing;

    private ConversationService $conversations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conversations = $this->app->make(ConversationService::class);
        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);
        $this->admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'max_occupancy' => 4,
            'wifi_network' => 'Casa-Verde',
            'wifi_password' => 'sunshine-42',
            'door_code' => '558122',
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'base_rate' => 10000,
            'max_occupancy' => 4,
        ]);

        $this->app->make(AgentBriefStore::class)->save($this->property, [
            'enabled' => true,
            'auto_send' => [AgentBrief::INTENT_AMENITY],
        ]);
    }

    public function test_the_agent_drafts_from_the_threads_own_history(): void
    {
        $provider = $this->scripted()
            ->classifyAs(AgentBrief::INTENT_AMENITY, 0.96)
            ->reply('The network is Casa-Verde.');

        $conversation = $this->thread([
            'Hello! We are looking forward to the stay.',
            'What is the wifi called?',
        ]);

        $before = Message::query()->count();

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/conversations/{$conversation->getKey()}/agent-draft")
            ->assertOk();

        $response->assertJsonPath('data.answer.reply', 'The network is Casa-Verde.');
        $response->assertJsonPath('data.answer.intent', AgentBrief::INTENT_AMENITY);
        $response->assertJsonPath('was_sent', false);

        // Nothing was written to the thread.
        $this->assertSame($before, Message::query()->count());

        // The earlier message is context, and the latest one is the question.
        $seen = $provider->seen[0];
        $this->assertSame('What is the wifi called?', $seen->messages[count($seen->messages) - 1]['body']);
        $this->assertCount(2, $seen->messages);
    }

    public function test_a_thread_with_nothing_from_the_guest_is_explained_rather_than_refused(): void
    {
        $this->scripted();
        $conversation = $this->thread([]);

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/conversations/{$conversation->getKey()}/agent-draft")
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('reason', 'There is no guest message on this conversation to answer yet.');
    }

    public function test_drafting_needs_only_permission_to_read_the_inbox(): void
    {
        $this->scripted()->classifyAs(AgentBrief::INTENT_AMENITY, 0.96);
        $conversation = $this->thread(['What is the wifi called?']);

        // A reservations agent may read and send; a cleaner may do neither, and
        // the draft endpoint must not be the way around that.
        $cleaner = $this->createUser($this->organization, [RoleRegistry::CLEANER]);

        $this->actingAsUser($cleaner, $this->organization)
            ->postJson("/api/v1/conversations/{$conversation->getKey()}/agent-draft")
            ->assertForbidden();
    }

    /**
     * A thread on a confirmed booking, with the given messages from the guest.
     *
     * @param  list<string>  $guestMessages
     */
    private function thread(array $guestMessages): Conversation
    {
        $conversation = $this->conversations->forReservation($this->booking());

        foreach ($guestMessages as $body) {
            $this->conversations->recordInbound($conversation, ['body' => $body]);
        }

        return $conversation->fresh();
    }

    private function booking(): Reservation
    {
        return $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $this->listing,
            checkIn: CarbonImmutable::now($this->property->timezone)->startOfDay()->addDay(),
            checkOut: CarbonImmutable::now($this->property->timezone)->startOfDay()->addDays(4),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'Marta',
                'last_name' => 'Silva',
                'email' => 'guest-'.uniqid().'@example.test',
            ],
            bookedAt: CarbonImmutable::now(),
        ));
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
