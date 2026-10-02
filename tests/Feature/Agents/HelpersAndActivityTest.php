<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Domain\Agents\Enums\AgentAudience;
use App\Domain\Agents\Models\AgentActivity;
use App\Domain\Agents\Models\AgentAsk;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Agents\Services\DeferredAgent;
use App\Domain\Agents\Support\CallbackToken;
use App\Domain\Listings\Models\Listing;
use App\Domain\Operations\Models\Vendor;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The two things an agent needs around it.
 *
 * **Who it calls.** An agent that cannot fix a boiler has to escalate to a
 * person, and a list of roles with nobody behind them is worse than no list: it
 * reads as somebody to call until the night it is needed.
 *
 * **What it did.** Everything the agent does is recorded, and the field that
 * matters is whether a reply could have reached a guest unread. That is never
 * inferred from the others — it is the whole safety story of the feature.
 */
class HelpersAndActivityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    public function test_a_helper_can_be_a_free_standing_contact(): void
    {
        $property = $this->property();

        $this->postJson("/api/v1/properties/{$property->getKey()}/helpers", [
            'role' => 'electrician',
            'name' => 'Rui',
            'phone' => '+351 912 000 111',
            'is_primary' => true,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Rui')
            ->assertJsonPath('data.phone', '+351 912 000 111')
            // Nothing maintains this contact but the row itself, which the
            // screen needs to know before it offers to edit the details.
            ->assertJsonPath('data.is_linked', false);
    }

    public function test_a_helper_pointing_at_a_vendor_reads_its_details_from_there(): void
    {
        $property = $this->property();

        $vendor = Vendor::query()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'Lisboa Electrics',
            'contact_name' => 'Rui Ferreira',
            'phone' => '+351 912 345 678',
            'category' => 'maintenance',
        ]);

        $helper = $this->postJson("/api/v1/properties/{$property->getKey()}/helpers", [
            'role' => 'electrician',
            'vendor_id' => $vendor->getKey(),
            // Deliberately wrong, and deliberately ignored: one phone number,
            // one place to correct it.
            'phone' => '+351 000 000 000',
        ])->assertStatus(201);

        $helper->assertJsonPath('data.name', 'Rui Ferreira')
            ->assertJsonPath('data.phone', '+351 912 345 678')
            ->assertJsonPath('data.is_linked', true);

        // Corrected on the vendor, correct everywhere.
        $vendor->update(['phone' => '+351 999 999 999']);

        $this->getJson("/api/v1/properties/{$property->getKey()}/helpers")
            ->assertOk()
            ->assertJsonPath('data.0.phone', '+351 999 999 999');
    }

    public function test_a_helper_with_nobody_behind_it_is_refused(): void
    {
        $property = $this->property();

        $this->postJson("/api/v1/properties/{$property->getKey()}/helpers", ['role' => 'cleaner'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_the_agent_is_told_who_to_call(): void
    {
        $property = $this->property();

        $this->postJson("/api/v1/properties/{$property->getKey()}/helpers", [
            'role' => 'electrician',
            'name' => 'Rui',
            'phone' => '+351 912 000 111',
        ])->assertStatus(201);

        Http::fake(['hooks.example.com/*' => Http::response([], 202)]);

        $this->app->make(DeferredAgent::class)->ask(
            property: $property->fresh(),
            question: 'The lights are out in the hallway. Who do I call?',
            asker: $this->user,
            audience: AgentAudience::Operator,
        );

        Http::assertSent(function ($request): bool {
            $helpers = $request->data()['facts']['helpers'] ?? [];

            // Knowing who to ring about a broken boiler is not privileged
            // information — it is the whole reason the list exists.
            return count($helpers) === 1
                && $helpers[0]['name'] === 'Rui'
                && $helpers[0]['phone'] === '+351 912 000 111';
        });
    }

    public function test_asking_and_answering_both_land_in_the_activity_log(): void
    {
        $property = $this->property();

        Http::fake(['hooks.example.com/*' => Http::response([], 202)]);

        $ask = $this->app->make(DeferredAgent::class)->ask(
            property: $property,
            question: 'Is there a lift?',
            asker: $this->user,
        );

        $this->assertDatabaseHas('agent_activities', [
            'property_id' => $property->getKey(),
            'kind' => AgentActivity::KIND_ASKED,
            // Somebody pressed a button. The agent did not decide to do this.
            'is_autonomous' => false,
        ]);

        $this->postJson('/api/public/agent-callback/'.$this->tokenFor($ask->getKey()), [
            'reply' => 'Yes, from the lobby.',
            'intent' => 'amenity',
            'confidence' => 0.9,
        ])->assertOk();

        // Held, not answered: the kind is the record that a gate stopped a reply
        // going out on its own, which is what somebody checks afterwards.
        $this->assertDatabaseHas('agent_activities', [
            'agent_ask_id' => $ask->getKey(),
            'kind' => AgentActivity::KIND_HELD,
            'is_autonomous' => false,
        ]);

        $this->getJson("/api/v1/properties/{$property->getKey()}/agent/activity")
            ->assertOk()
            ->assertJsonPath('meta.autonomous_count', 0)
            ->assertJsonCount(2, 'data');
    }

    public function test_an_answer_that_could_have_gone_unread_is_marked_as_such(): void
    {
        $property = $this->property(['auto_send' => ['amenity'], 'confidence_floor' => 0.7]);

        Http::fake(['hooks.example.com/*' => Http::response([], 202)]);

        // Against a booking that is entitled to everything: with no booking the
        // agent has to refuse the arrival details, and a refusal is held for a
        // person to read however sure the bot was. That gate is tested
        // elsewhere; what is under test here is the flag on the log line.
        $ask = $this->app->make(DeferredAgent::class)->ask(
            property: $property,
            question: 'Is there a lift?',
            reservation: $this->entitledBooking($property),
        );

        $this->postJson('/api/public/agent-callback/'.$this->tokenFor($ask->getKey()), [
            'reply' => 'Yes, from the lobby.',
            'intent' => 'amenity',
            'confidence' => 0.95,
        ])->assertOk();

        // The one question an operator is really scanning this log for.
        $this->getJson("/api/v1/properties/{$property->getKey()}/agent/activity?autonomous=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', AgentActivity::KIND_ANSWERED)
            ->assertJsonPath('data.0.is_autonomous', true);
    }

    public function test_an_ask_nobody_answered_leaves_a_line_rather_than_vanishing(): void
    {
        $property = $this->property();

        Http::fake(['hooks.example.com/*' => Http::response([], 202)]);

        $ask = $this->app->make(DeferredAgent::class)->ask($property, 'Is there a lift?');
        $ask->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->artisan('agents:expire-asks')->assertSuccessful();

        // "Nothing ever came back" is exactly what somebody is looking for when
        // they go and read this log, so a silent bulk close would be the one
        // agent event that left no trace.
        $this->assertDatabaseHas('agent_activities', [
            'agent_ask_id' => $ask->getKey(),
            'kind' => AgentActivity::KIND_EXPIRED,
        ]);
    }

    /**
     * A booking confirmed, paid and arriving tomorrow.
     */
    private function entitledBooking(Property $property): Reservation
    {
        $listing = Listing::query()
            ->where('property_id', $property->getKey())
            ->firstOrFail();

        $reservation = $this->app->make(ReservationService::class)
            ->create(new ReservationRequest(
                listing: $listing,
                checkIn: CarbonImmutable::tomorrow(),
                checkOut: CarbonImmutable::tomorrow()->addDays(2),
                adults: 2,
                status: ReservationStatus::Confirmed,
                guestAttributes: [
                    'first_name' => 'Ana',
                    'last_name' => 'Costa',
                    'email' => 'guest-'.uniqid().'@example.test',
                ],
            ));

        $reservation->forceFill(['balance_due' => 0])->save();

        return $reservation->fresh(['property', 'guest']);
    }

    /**
     * The plain callback token for an ask, by standing in for the dispatch job.
     */
    private function tokenFor(string $askId): string
    {
        $plain = Str::random(64);

        AgentAsk::query()->whereKey($askId)->update([
            'callback_token_hash' => CallbackToken::hash($plain),
            'dispatched_at' => now(),
        ]);

        return $plain;
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    private function property(array $brief = []): Property
    {
        $this->organization = $this->createOrganization();
        $this->user = $this->createUser($this->organization);
        $this->actingAsUser($this->user, $this->organization);

        $property = $this->app->make(PropertyService::class)->create([
            'name' => 'Yellow Room',
            'property_type' => 'apartment',
            'address_line_1' => 'Rua dos Remédios 12',
            'postal_code' => '1100-513',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'max_occupancy' => 2,
            'base_rate' => 9000,
        ]);

        // Active, because a booking cannot be taken against a draft property
        // and one of these tests needs an entitled stay.
        $this->app->make(PropertyService::class)->activate($property);

        $this->app->make(AgentBriefStore::class)->save($property, $brief + [
            'enabled' => true,
            'bot_name' => 'Alex',
            'webhook_url' => 'https://hooks.example.com/alex',
            'webhook_token' => 'secret',
        ]);

        return $property->fresh();
    }
}
