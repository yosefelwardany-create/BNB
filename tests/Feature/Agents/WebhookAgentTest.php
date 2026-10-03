<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Domain\Agents\Jobs\DispatchAgentAsk;
use App\Domain\Agents\Models\AgentAsk;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Agents\Services\DeferredAgent;
use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Asking a property's bot something it answers minutes later.
 *
 * The synchronous path holds a request open while a bot thinks, which is right
 * for a bot that answers in two seconds and useless for an agent run that takes
 * two minutes. This is the other shape: Habitat writes the question down, fires
 * a webhook with a callback URL, and stops waiting.
 *
 * What these tests hold:
 *
 * **The gates do not relax because the answer arrived late.** The same four
 * apply, and the one that cannot run late — entitlement — runs early, when the
 * facts are assembled for the outbound request. A bot with no booking attached
 * is sent no door code, exactly as in the synchronous path.
 *
 * **The callback is a key to one write and nothing else.** One ask, one use,
 * half an hour, stored as a hash. A token that is spent, expired or never issued
 * gets the same answer, because telling them apart tells a guesser which half
 * they have right.
 *
 * **A pending ask is visibly pending.** The failure mode of every asynchronous
 * feature is a screen that looks like nothing happened, so a row that has gone
 * out and not come back says so, with the time it goes stale.
 */
class WebhookAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_callback_executes_once_under_the_asks_tenant(): void
    {
        $property = $this->propertyWithWebhook(['may_do' => ['block_dates'], 'may_do_alone' => ['block_dates']]);
        [$ask, $token] = $this->dispatchedAsk($property, 'Block March 6 2027 locally');
        $ask->update(['audience' => 'operator', 'asked_by_id' => auth()->id()]);
        app(TenantContext::class)->clear();
        $payload = ['reply' => json_encode(['action' => ['capability' => 'block_dates', 'arguments' => ['from' => '2027-03-06', 'to' => '2027-03-06']]])];
        $answer = app(DeferredAgent::class)->receive($token, $payload);
        $this->assertStringContainsString('Completed:', $answer->reply);
        $this->assertNull(app(DeferredAgent::class)->receive($token, $payload));
        $this->assertDatabaseCount('calendar_blocks', 1);
        $this->assertFalse(app(TenantContext::class)->hasTenant());
    }

    public function test_asking_records_the_question_and_returns_without_waiting(): void
    {
        Queue::fake();

        $property = $this->propertyWithWebhook();

        $ask = $this->app->make(DeferredAgent::class)->ask($property, 'Is there a lift?');

        $this->assertSame(AgentAsk::STATUS_PENDING, $ask->status);
        $this->assertSame('Is there a lift?', $ask->question);
        $this->assertSame('hooks.example.com', $ask->endpoint_host);

        // Nothing has gone out yet and nothing has come back: the row is the
        // whole of what the caller gets, and it says so.
        $this->assertNull($ask->reply);
        $this->assertNull($ask->dispatched_at);

        Queue::assertPushed(DispatchAgentAsk::class);
    }

    public function test_the_token_is_never_stored_in_a_form_that_could_be_used(): void
    {
        Queue::fake();

        $property = $this->propertyWithWebhook();
        $ask = $this->app->make(DeferredAgent::class)->ask($property, 'Is there a lift?');

        $pushed = null;
        Queue::assertPushed(DispatchAgentAsk::class, function (DispatchAgentAsk $job) use (&$pushed): bool {
            $pushed = $job;

            return true;
        });

        $stored = (string) AgentAsk::query()->whereKey($ask->getKey())->value('callback_token_hash');

        // The row holds a hash. Somebody reading a database dump, a replica or a
        // stray `select *` gets nothing they can answer with.
        $this->assertNotSame($pushed->callbackToken, $stored);
        $this->assertSame(hash('sha256', $pushed->callbackToken), $stored);
        $this->assertSame(64, mb_strlen($stored));

        // And it is not serialised back out either.
        $this->assertArrayNotHasKey('callback_token_hash', $ask->fresh()->toArray());
    }

    public function test_the_webhook_carries_the_question_the_facts_and_a_way_to_answer(): void
    {
        $property = $this->propertyWithWebhook();

        Http::fake(['hooks.example.com/*' => Http::response(['accepted' => true], 202)]);

        $ask = $this->app->make(DeferredAgent::class)->ask($property, 'Is there a lift?');

        Http::assertSent(function ($request) use ($property): bool {
            $body = $request->data();

            return $request->url() === 'https://hooks.example.com/yellow'
                // Its own credential, not the bot's: they are issued by
                // different systems and rotated on different days.
                && $request->hasHeader('Authorization', 'Bearer webhook-secret-456')
                && $request->hasHeader('X-Habitat-Property', (string) $property->getKey())
                && $body['question'] === 'Is there a lift?'
                && is_array($body['facts'])
                && str_contains($body['callback']['url'], '/api/public/agent-callback/')
                && $body['callback']['method'] === 'POST';
        });

        $ask->refresh();

        $this->assertNotNull($ask->dispatched_at);
        // Names, not values. The row records that the property's facts went out
        // without writing a second copy of them down.
        $this->assertContains('name', $ask->sent_fact_keys);
        $this->assertNotContains('hunter2', $ask->sent_fact_keys);
    }

    public function test_the_webhook_carries_a_prompt_a_general_agent_can_act_on(): void
    {
        /*
         * The endpoint on the other side is usually not code somebody wrote for
         * Habitat — it is an automation platform that takes a webhook and starts
         * an agent from it, and those look for a prompt rather than parsing an
         * unfamiliar schema. A callback URL nobody reads is a feature that
         * silently never answers, so the instruction has to be in the prose.
         */
        $property = $this->propertyWithWebhook();

        Http::fake(['hooks.example.com/*' => Http::response([], 202)]);

        $this->app->make(DeferredAgent::class)->ask($property, 'Is there a lift?');

        Http::assertSent(function ($request): bool {
            $prompt = $request->data()['prompt'];

            return str_contains($prompt, 'Is there a lift?')
                // The exact call that delivers the answer, not a field name.
                && str_contains($prompt, 'curl -X POST')
                && str_contains($prompt, '/api/public/agent-callback/')
                && str_contains($prompt, '"confidence"')
                // And the way to report a failure, so a bot that cannot answer
                // says so instead of leaving somebody watching a spinner.
                && str_contains($prompt, '"error"');
        });
    }

    public function test_the_prompt_leaks_nothing_the_structured_payload_withholds(): void
    {
        // The prompt restates the facts in prose. Restating them from a
        // different source would be a second place for entitlement to be
        // decided, and the second place is always the one that gets it wrong.
        $property = $this->propertyWithWebhook();

        Http::fake(['hooks.example.com/*' => Http::response([], 202)]);

        $this->app->make(DeferredAgent::class)->ask($property, 'What is the door code?');

        Http::assertSent(function ($request): bool {
            $prompt = $request->data()['prompt'];

            return ! str_contains($prompt, '4821')
                && ! str_contains($prompt, 'hunter2')
                // And it says why, so the answer can explain the gap instead of
                // filling it.
                && str_contains($prompt, 'ARRIVAL DETAILS ARE DELIBERATELY NOT INCLUDED');
        });
    }

    public function test_a_question_with_no_booking_is_sent_no_arrival_secrets(): void
    {
        $property = $this->propertyWithWebhook();

        Http::fake(['hooks.example.com/*' => Http::response([], 202)]);

        $ask = $this->app->make(DeferredAgent::class)->ask($property, 'What is the door code?');

        Http::assertSent(function ($request): bool {
            $facts = json_encode($request->data()['facts']);

            // The entitlement gate runs before the question is read and decides
            // what leaves the building. The bot cannot leak what it was never
            // given, wherever it runs and however long it takes.
            return ! str_contains($facts, '4821')
                && ! str_contains($facts, 'hunter2')
                && ! str_contains($facts, 'BashaGuest');
        });

        $this->assertNotEmpty($ask->fresh()->withheld);
    }

    public function test_an_entitled_booking_is_sent_the_arrival_details(): void
    {
        $property = $this->propertyWithWebhook();
        $reservation = $this->bookingArrivingTomorrow($property);

        Http::fake(['hooks.example.com/*' => Http::response([], 202)]);

        $ask = $this->app->make(DeferredAgent::class)
            ->ask($property, 'What is the door code?', $reservation);

        Http::assertSent(function ($request): bool {
            $facts = $request->data()['facts'];

            return isset($facts['arrival']['door_code'])
                && $facts['arrival']['door_code'] === '4821';
        });

        $this->assertSame([], $ask->fresh()->withheld);
    }

    public function test_the_callback_records_the_answer_and_runs_the_gates(): void
    {
        $property = $this->propertyWithWebhook(['auto_send' => ['amenity'], 'confidence_floor' => 0.7]);

        [$ask, $token] = $this->dispatchedAsk($property, 'Is there a lift?');

        $this->postJson("/api/public/agent-callback/{$token}", [
            'reply' => 'Yes, there is a lift from the lobby.',
            'intent' => 'amenity',
            'confidence' => 0.91,
        ])
            ->assertOk()
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.would_auto_send', true)
            // Nothing has gone to a guest. Stated in the payload, not only in
            // the docs, so a client cannot render this as a sent reply.
            ->assertJsonPath('data.was_sent', false);

        $ask->refresh();

        $this->assertSame(AgentAsk::STATUS_ANSWERED, $ask->status);
        $this->assertSame('Yes, there is a lift from the lobby.', $ask->reply);
        $this->assertSame('amenity', $ask->intent);
        $this->assertEqualsWithDelta(0.91, $ask->confidence, 0.001);
        $this->assertNotNull($ask->answered_at);
    }

    public function test_a_bot_cannot_make_a_refund_question_answer_itself(): void
    {
        // Auto-send is a property setting, and `payment` is not one of the
        // things it can be set to. A bot claiming otherwise is claiming, and the
        // claim is checked against a constant rather than a setting.
        $property = $this->propertyWithWebhook(['auto_send' => ['amenity']]);

        [$ask, $token] = $this->dispatchedAsk($property, 'Can I have a refund for the second night?');

        $this->postJson("/api/public/agent-callback/{$token}", [
            'reply' => 'Of course, I have refunded it.',
            'intent' => 'payment',
            'confidence' => 1.0,
        ])->assertOk()->assertJsonPath('data.would_auto_send', false);

        $this->assertStringContainsString('read by a person first', (string) $ask->fresh()->held_because);
    }

    public function test_an_answer_with_no_stated_confidence_waits_for_a_person(): void
    {
        $property = $this->propertyWithWebhook(['auto_send' => ['amenity']]);

        [$ask, $token] = $this->dispatchedAsk($property, 'Is there a lift?');

        $this->postJson("/api/public/agent-callback/{$token}", [
            'reply' => 'Yes.',
            'intent' => 'amenity',
        ])->assertOk();

        $ask->refresh();

        // Absent is zero, never a guess. An answer whose certainty nobody stated
        // has not been established to be certain.
        $this->assertSame(0.0, $ask->confidence);
        $this->assertFalse($ask->would_auto_send);
    }

    public function test_an_escalation_keyword_holds_the_answer_however_sure_the_bot_is(): void
    {
        $property = $this->propertyWithWebhook([
            'auto_send' => ['amenity'],
            'escalate' => ['neighbour'],
        ]);

        [$ask, $token] = $this->dispatchedAsk($property, 'The neighbour is complaining about noise.');

        $this->postJson("/api/public/agent-callback/{$token}", [
            'reply' => 'Nothing to worry about.',
            'intent' => 'amenity',
            'confidence' => 1.0,
        ])->assertOk();

        // Matched in Habitat's code against the question as asked, not asked of
        // the bot. It has to hold even when the bot disagrees.
        $this->assertStringContainsString('neighbour', (string) $ask->fresh()->held_because);
    }

    public function test_the_callback_works_once(): void
    {
        $property = $this->propertyWithWebhook();
        [$ask, $token] = $this->dispatchedAsk($property, 'Is there a lift?');

        $this->postJson("/api/public/agent-callback/{$token}", ['reply' => 'Yes.'])->assertOk();

        $this->postJson("/api/public/agent-callback/{$token}", ['reply' => 'Actually, no.'])
            ->assertStatus(404);

        // The second answer changed nothing.
        $this->assertSame('Yes.', $ask->fresh()->reply);
    }

    public function test_an_expired_callback_is_refused_and_reads_the_same_as_a_wrong_one(): void
    {
        $property = $this->propertyWithWebhook();
        [$ask, $token] = $this->dispatchedAsk($property, 'Is there a lift?');

        $ask->forceFill(['expires_at' => CarbonImmutable::now()->subMinute()])->save();

        $expired = $this->postJson("/api/public/agent-callback/{$token}", ['reply' => 'Yes.'])
            ->assertStatus(404);

        $nonsense = $this->postJson('/api/public/agent-callback/'.str_repeat('a', 64), ['reply' => 'Yes.'])
            ->assertStatus(404);

        // Identical. Distinguishing "wrong token" from "right token, too late"
        // tells somebody guessing which half of the problem they have solved.
        $this->assertSame($expired->json('message'), $nonsense->json('message'));
    }

    public function test_a_bot_reporting_a_failure_is_recorded_rather_than_rejected(): void
    {
        $property = $this->propertyWithWebhook();
        [$ask, $token] = $this->dispatchedAsk($property, 'Is there a lift?');

        $this->postJson("/api/public/agent-callback/{$token}", [
            'error' => 'The model timed out after three attempts.',
        ])->assertOk()->assertJsonPath('data.accepted', false);

        $ask->refresh();

        $this->assertSame(AgentAsk::STATUS_FAILED, $ask->status);
        $this->assertStringContainsString('timed out', (string) $ask->failure);
    }

    public function test_an_ask_that_was_never_sent_cannot_be_answered(): void
    {
        Queue::fake();

        $property = $this->propertyWithWebhook();
        $plain = null;

        $this->app->make(DeferredAgent::class)->ask($property, 'Is there a lift?');

        Queue::assertPushed(DispatchAgentAsk::class, function (DispatchAgentAsk $job) use (&$plain): bool {
            $plain = $job->callbackToken;

            return true;
        });

        // The row exists and the token is valid, but the question has not left
        // the building. Nothing can legitimately be answering it yet.
        $this->postJson("/api/public/agent-callback/{$plain}", ['reply' => 'Yes.'])
            ->assertStatus(404);
    }

    public function test_a_webhook_that_refuses_the_question_closes_the_ask_with_its_own_words(): void
    {
        $property = $this->propertyWithWebhook();

        Http::fake(['hooks.example.com/*' => Http::response('Unauthorized: bad sender key', 401)]);

        $ask = $this->app->make(DeferredAgent::class)->ask($property, 'Is there a lift?')->fresh();

        $this->assertSame(AgentAsk::STATUS_FAILED, $ask->status);
        // A 401 here almost always means the sender key is wrong, and saying so
        // beats "the webhook failed".
        $this->assertStringContainsString('401', (string) $ask->failure);
        $this->assertStringContainsString('bad sender key', (string) $ask->failure);
    }

    public function test_a_property_with_no_webhook_says_so_rather_than_failing_quietly(): void
    {
        $property = $this->propertyWithWebhook(['webhook_url' => null]);

        $this->expectExceptionMessage('This property has no webhook');

        $this->app->make(DeferredAgent::class)->ask($property, 'Is there a lift?');
    }

    public function test_the_sweep_closes_asks_nobody_answered_and_keeps_the_question(): void
    {
        $property = $this->propertyWithWebhook();

        $lapsed = AgentAsk::factory()->lapsed()->create([
            'property_id' => $property->getKey(),
            'question' => 'Is there a lift?',
        ]);
        $live = AgentAsk::factory()->create(['property_id' => $property->getKey()]);

        $this->artisan('agents:expire-asks')->assertSuccessful();

        $lapsed->refresh();

        $this->assertSame(AgentAsk::STATUS_EXPIRED, $lapsed->status);
        // Nothing is deleted. "The bot never answered" is exactly the thing
        // somebody will want to look up later.
        $this->assertSame('Is there a lift?', $lapsed->question);
        $this->assertNotNull($lapsed->failure);

        $this->assertSame(AgentAsk::STATUS_PENDING, $live->fresh()->status);
    }

    public function test_the_screen_can_see_a_pending_ask_as_pending(): void
    {
        $property = $this->propertyWithWebhook();

        AgentAsk::factory()->create([
            'property_id' => $property->getKey(),
            'question' => 'Is there a lift?',
        ]);

        $this->getJson("/api/v1/properties/{$property->getKey()}/agent/asks")
            ->assertOk()
            ->assertJsonPath('data.0.status', AgentAsk::STATUS_PENDING)
            // The field that stops an asynchronous feature from looking like
            // nothing happened.
            ->assertJsonPath('data.0.is_waiting', true)
            ->assertJsonPath('data.0.reply', null)
            ->assertJsonStructure(['data' => [['expires_at', 'asked_at']], 'meta' => ['window_minutes']]);
    }

    public function test_the_endpoint_returns_a_pending_row_rather_than_waiting(): void
    {
        Queue::fake();

        $property = $this->propertyWithWebhook();

        $this->postJson("/api/v1/properties/{$property->getKey()}/agent/ask-later", [
            'question' => 'Is there a lift?',
        ])
            // 202: taken, not done.
            ->assertStatus(202)
            ->assertJsonPath('data.status', AgentAsk::STATUS_PENDING)
            ->assertJsonPath('data.was_sent', false);
    }

    public function test_one_property_cannot_read_another_tenants_asks(): void
    {
        $property = $this->propertyWithWebhook();
        AgentAsk::factory()->create(['property_id' => $property->getKey()]);

        // A second organization, acting as its own user.
        $this->propertyWithWebhook();

        $this->getJson("/api/v1/properties/{$property->getKey()}/agent/asks")
            ->assertStatus(404);
    }

    /**
     * An ask that has gone out, with the token that answers it.
     *
     * @return array{0: AgentAsk, 1: string}
     */
    private function dispatchedAsk(Property $property, string $question): array
    {
        Queue::fake();

        $ask = $this->app->make(DeferredAgent::class)->ask($property, $question);

        $plain = null;
        Queue::assertPushed(DispatchAgentAsk::class, function (DispatchAgentAsk $job) use (&$plain): bool {
            $plain = $job->callbackToken;

            return true;
        });

        // Standing in for the job, which is faked here: these tests are about
        // what happens when the answer comes back, and the sending half has its
        // own tests above.
        $ask->forceFill(['dispatched_at' => CarbonImmutable::now()])->save();

        return [$ask, (string) $plain];
    }

    private function bookingArrivingTomorrow(Property $property): Reservation
    {
        $listing = Listing::query()->where('property_id', $property->getKey())->firstOrFail();

        $reservation = $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $listing,
            checkIn: CarbonImmutable::tomorrow(),
            checkOut: CarbonImmutable::tomorrow()->addDays(3),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'Ana',
                'last_name' => 'Costa',
                'email' => 'guest-'.uniqid().'@example.test',
            ],
        ));

        // Entitlement wants the booking paid as well as confirmed: an unpaid
        // guest asking for the door code is either confused or not a guest.
        $reservation->forceFill(['balance_due' => 0])->save();

        return $reservation->fresh(['property', 'guest']);
    }

    /**
     * A property whose agent fires a webhook and takes the answer later.
     *
     * @param  array<string, mixed>  $brief
     */
    private function propertyWithWebhook(array $brief = []): Property
    {
        $organization = $this->createOrganization();
        $this->actingAsUser($this->createUser($organization), $organization);

        $property = $this->app->make(PropertyService::class)->create([
            'name' => 'Yellow Room',
            'property_type' => 'apartment',
            'address_line_1' => 'Rua dos Remédios 12',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'max_occupancy' => 2,
            'base_rate' => 9000,
            'wifi_network' => 'BashaGuest',
            'wifi_password' => 'hunter2',
            'door_code' => '4821',
        ]);

        $this->app->make(AgentBriefStore::class)->save($property, $brief + [
            'enabled' => true,
            'bot_name' => 'Yellow',
            'webhook_url' => 'https://hooks.example.com/yellow',
            'webhook_token' => 'webhook-secret-456',
        ]);

        $this->app->make(PropertyService::class)->activate($property);

        return $property->fresh();
    }
}
