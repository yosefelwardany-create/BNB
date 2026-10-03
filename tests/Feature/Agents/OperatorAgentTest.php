<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Domain\Agents\Enums\AgentAudience;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Agents\Services\DeferredAgent;
use App\Domain\Agents\Services\GuestAgent;
use App\Domain\Agents\Services\OperatorKnowledge;
use App\Domain\Listings\Models\Listing;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The agent answering the person who owns the place, not a guest.
 *
 * The guest agent is given what a guest may be told: the address, the check-in
 * window, the house rules, a door code where the booking earns one. It holds no
 * revenue and no bookings, so an owner asking how last month went gets an agent
 * with nothing to answer from — and a model with no facts and a direct question
 * invents an occupancy rate.
 *
 * So the audience decides the whole body of facts, and the gate changes shape
 * with it. For a guest it is the booking. Here there is no booking: the asker is
 * a signed-in member, and what they may know is what their role already lets
 * them read elsewhere in the product. These tests hold that line in both
 * directions — the figures reach somebody entitled to them, and do not reach
 * somebody who is not.
 */
class OperatorAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_snapshot_is_scoped_to_property_and_message_permission(): void
    {
        $property = $this->tradingProperty();
        $thread = Conversation::query()->create([
            'organization_id' => $property->organization_id, 'property_id' => $property->id,
            'channel' => 'hostex', 'participant_type' => 'guest', 'status' => 'open',
        ]);
        $thread->forceFill(['last_inbound_at' => now(), 'last_message_at' => now()])->save();
        foreach (['Earlier question', 'Middle question', 'Latest question', 'Newest question'] as $body) {
            $thread->messages()->create(['organization_id' => $property->organization_id, 'author_type' => 'guest', 'transport' => 'channel', 'direction' => 'inbound', 'body' => $body, 'status' => 'received', 'is_internal_note' => false]);
        }
        $thread->messages()->create(['organization_id' => $property->organization_id, 'author_type' => 'user', 'transport' => 'internal', 'direction' => 'outbound', 'body' => 'Private internal note', 'status' => 'sent', 'is_internal_note' => true]);
        $other = Conversation::query()->create([
            'organization_id' => $property->organization_id, 'property_id' => Property::factory()->create(['organization_id' => $property->organization_id])->id,
            'channel' => 'hostex', 'participant_type' => 'guest', 'status' => 'open', 'subject' => 'Other property secret',
        ]);
        $knowledge = app(OperatorKnowledge::class);
        $facts = $knowledge->about($property, $this->asker)['facts'];
        $this->assertSame(1, $facts['inbox']['conversation_count']);
        $this->assertCount(3, $facts['inbox']['conversations'][0]['recent_messages']);
        $this->assertStringNotContainsString('Private internal note', json_encode($facts['inbox']));
        $this->assertStringNotContainsString('Other property secret', json_encode($facts['inbox']));
        $this->assertArrayNotHasKey('inbox', $knowledge->about($property, null)['facts']);
    }

    public function test_an_owner_asking_about_performance_is_given_the_figures(): void
    {
        $property = $this->tradingProperty();

        Http::fake(['hooks.example.com/*' => Http::response([], 202)]);

        $this->app->make(DeferredAgent::class)->ask(
            property: $property,
            question: 'How did this flat do last month?',
            asker: $this->asker,
            audience: AgentAudience::Operator,
        );

        Http::assertSent(function ($request): bool {
            $facts = $request->data()['facts'];

            return isset($facts['performance']['last_30_days']['occupancy_percent'])
                && isset($facts['performance']['last_90_days'])
                && isset($facts['performance']['next_30_days_on_the_books'])
                && isset($facts['bookings']['next_arrivals']);
        });
    }

    public function test_the_figures_carry_their_currency(): void
    {
        $property = $this->tradingProperty();

        Http::fake(['hooks.example.com/*' => Http::response([], 202)]);

        $this->app->make(DeferredAgent::class)->ask(
            property: $property,
            question: 'What did it earn?',
            asker: $this->asker,
            audience: AgentAudience::Operator,
        );

        Http::assertSent(function ($request): bool {
            $window = $request->data()['facts']['performance']['last_30_days'];

            // A model handed "1450.00" picks a currency symbol out of the air,
            // and for an owner reading their own numbers the symbol is not a
            // detail. Handed 145000 it reports a hundred and forty-five thousand.
            return str_contains((string) $window['accommodation_revenue'], 'EUR')
                && str_contains((string) $window['adr'], 'EUR');
        });
    }

    public function test_somebody_who_may_not_see_revenue_is_not_sent_it(): void
    {
        $property = $this->tradingProperty();

        // A cleaner. They can see the property and its tasks; the money is not
        // theirs to read on any screen, and a prompt is a screen whose contents
        // can be read back out of the answer.
        $cleaner = $this->createUser($this->organization, [RoleRegistry::CLEANER]);

        Http::fake(['hooks.example.com/*' => Http::response([], 202)]);

        $this->app->make(DeferredAgent::class)->ask(
            property: $property,
            question: 'How much did this flat earn last month?',
            asker: $cleaner,
            audience: AgentAudience::Operator,
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ! isset($body['facts']['performance'])
                // Named rather than silently absent, so the agent says it cannot
                // see the numbers instead of inventing a plausible one.
                && $body['withheld'] !== []
                && str_contains(implode(' ', $body['withheld']), 'cannot view revenue');
        });
    }

    public function test_an_operator_question_is_never_treated_as_sendable_to_a_guest(): void
    {
        $property = $this->tradingProperty();

        $answer = $this->app->make(GuestAgent::class)->answer(
            property: $property,
            question: 'How did this flat do last month?',
            audience: AgentAudience::Operator,
            asker: $this->asker,
        );

        // The auto-send gates exist to stop an answer reaching a guest unread.
        // This answer has no second party, so they have nothing to decide — and
        // a "held for review" line about a review that is the act of reading the
        // screen teaches people to ignore the real ones.
        $this->assertFalse($answer->wouldAutoSend);
        $this->assertNull($answer->heldBecause);
    }

    public function test_a_guest_asking_the_same_thing_still_gets_the_guest_facts(): void
    {
        $property = $this->tradingProperty();

        Http::fake(['hooks.example.com/*' => Http::response([], 202)]);

        $this->app->make(DeferredAgent::class)->ask(
            property: $property,
            question: 'How much does this flat earn?',
            asker: $this->asker,
        );

        Http::assertSent(function ($request): bool {
            $facts = $request->data()['facts'];

            // The default is unchanged and the two sets stay apart. A single
            // widened fact set would put last month's revenue one mistake away
            // from somebody asking about the wifi.
            return ! isset($facts['performance'])
                && ! isset($facts['bookings'])
                && isset($facts['name']);
        });
    }

    public function test_the_prompt_tells_a_bot_it_is_answering_the_manager(): void
    {
        $property = $this->tradingProperty();

        Http::fake(['hooks.example.com/*' => Http::response([], 202)]);

        $this->app->make(DeferredAgent::class)->ask(
            property: $property,
            question: 'How did this flat do?',
            asker: $this->asker,
            audience: AgentAudience::Operator,
        );

        Http::assertSent(function ($request): bool {
            $prompt = $request->data()['prompt'];

            // An agent that thinks it is talking to a guest hedges, apologises
            // and declines to quote a figure, which is the opposite of useful to
            // the person who owns the flat.
            return str_contains($prompt, 'NOT a guest')
                && str_contains($prompt, 'do not round them into vagueness')
                && str_contains($prompt, 'curl -X POST');
        });
    }

    public function test_the_api_takes_an_audience_and_records_it(): void
    {
        $property = $this->tradingProperty();

        Http::fake(['hooks.example.com/*' => Http::response([], 202)]);

        $this->postJson("/api/v1/properties/{$property->getKey()}/agent/ask-later", [
            'question' => 'How did this flat do last month?',
            'audience' => 'operator',
        ])
            ->assertStatus(202)
            ->assertJsonPath('data.audience', 'operator');
    }

    private Organization $organization;

    private User $asker;

    /**
     * A property with a month of trading behind it and a booking ahead.
     */
    private function tradingProperty(): Property
    {
        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);
        $this->asker = $this->createUser($this->organization);
        $this->actingAsUser($this->asker, $this->organization);

        $property = $this->app->make(PropertyService::class)->create([
            'name' => 'Yellow Room',
            'property_type' => 'apartment',
            'address_line_1' => 'Rua dos Remédios 12',
            'postal_code' => '1100-513',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'currency' => 'EUR',
            'max_occupancy' => 2,
            'base_rate' => 9000,
        ]);

        $this->app->make(PropertyService::class)->activate($property);

        $this->app->make(AgentBriefStore::class)->save($property, [
            'enabled' => true,
            'webhook_url' => 'https://hooks.example.com/yellow',
            'webhook_token' => 'webhook-secret-456',
        ]);

        $listing = Listing::query()->where('property_id', $property->getKey())->firstOrFail();

        // One stay behind, one ahead, so every window has something in it.
        $this->stay($listing, CarbonImmutable::today()->subDays(10), CarbonImmutable::today()->subDays(6), true);
        $this->stay($listing, CarbonImmutable::today()->addDays(5), CarbonImmutable::today()->addDays(9), false);

        return $property->fresh();
    }

    private function stay(Listing $listing, CarbonImmutable $in, CarbonImmutable $out, bool $past): void
    {
        $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $listing,
            checkIn: $in,
            checkOut: $out,
            adults: 2,
            status: $past ? ReservationStatus::CheckedOut : ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'Ana',
                'last_name' => 'Costa',
                'email' => 'guest-'.uniqid().'@example.test',
            ],
            bookedAt: $in->subDays(7),
            recordsExistingStay: $past,
        ));
    }
}
