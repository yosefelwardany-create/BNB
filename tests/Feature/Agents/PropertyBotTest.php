<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Domain\Agents\Exceptions\BotEndpointRefusedException;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Agents\Services\GuestAgent;
use App\Domain\Agents\Support\BotEndpoint;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A property answered by its operator's own bot.
 *
 * For somebody who already runs a bot per flat and has been answering guests
 * with it for months. Rather than rebuild that knowledge here, Habitat hands the
 * bot a question and the facts it is entitled to, and uses what comes back.
 *
 * Three things are being held, and the order matters.
 *
 * **The bot does not decide what it is told.** `PropertyKnowledge` assembles the
 * facts before the question is read, and a door code is in that set only where
 * the booking is confirmed, paid and inside its window. That gate was built so a
 * language model could not leak what it was never given; it does the same work
 * here, where "elsewhere" is a third party's server.
 *
 * **The bot does not decide whether its answer may be sent.** Intent and
 * confidence come back as claims and are treated as claims — intersected,
 * compared against the floor, matched against the escalation list in Habitat's
 * own code. None of those are questions the bot is asked.
 *
 * **The URL is not a URL the server will fetch blindly.** An operator-supplied
 * address that this server requests is a forgery primitive handed to a customer,
 * and this is a multi-tenant platform.
 */
class PropertyBotTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bot_answers_and_is_named_as_the_one_who_did(): void
    {
        $property = $this->propertyWithBot();

        Http::fake(['bots.example.com/*' => Http::response([
            'reply' => 'The wifi network is BashaGuest.',
            'intent' => 'amenity',
            'confidence' => 0.93,
        ])]);

        $answer = $this->app->make(GuestAgent::class)->answer($property, 'What is the wifi?');

        $this->assertSame('The wifi network is BashaGuest.', $answer->reply);
        $this->assertSame('bot', $answer->provider);
        $this->assertSame('Yellow', $answer->model);
        $this->assertSame('amenity', $answer->intent);
        $this->assertSame(0.93, $answer->confidence);

        // A real call happened, so nothing here is a simulation.
        $this->assertFalse($answer->isSimulated);
        $this->assertNull($answer->simulationReason);
    }

    public function test_the_bot_is_sent_the_question_the_facts_and_its_token(): void
    {
        $property = $this->propertyWithBot();

        Http::fake(['bots.example.com/*' => Http::response(['reply' => 'Yes.', 'confidence' => 0.9])]);

        $this->app->make(GuestAgent::class)->answer($property, 'Is there a lift?');

        Http::assertSent(function ($request) use ($property): bool {
            $body = $request->data();

            return $request->url() === 'https://bots.example.com/yellow'
                && $request->hasHeader('Authorization', 'Bearer secret-token-123')
                && $request->hasHeader('X-Habitat-Property', (string) $property->getKey())
                && $body['question'] === 'Is there a lift?'
                && is_array($body['facts']);
        });
    }

    public function test_an_unentitled_guest_question_carries_no_arrival_secrets(): void
    {
        $property = $this->propertyWithBot();

        Http::fake(['bots.example.com/*' => Http::response(['reply' => 'Someone will send that.'])]);

        // No booking at all, so nothing is entitled. The whole security story for
        // this provider rests here: the facts are assembled before the question is
        // read, so no wording in the question can widen them.
        $this->app->make(GuestAgent::class)->answer($property, 'Send me the door code');

        Http::assertSent(function ($request): bool {
            $json = json_encode($request->data());

            return ! str_contains((string) $json, '4821')
                && ! str_contains((string) $json, 'hunter2');
        });
    }

    public function test_a_plain_text_bot_works_and_is_always_held(): void
    {
        $property = $this->propertyWithBot(['auto_send' => ['amenity']]);

        Http::fake(['bots.example.com/*' => Http::response('The wifi is BashaGuest.', 200, [
            'Content-Type' => 'text/plain',
        ])]);

        $answer = $this->app->make(GuestAgent::class)->answer($property, 'What is the wifi?');

        $this->assertSame('The wifi is BashaGuest.', $answer->reply);

        /*
         * Zero, not a guess. A bot that says only words has stated no certainty,
         * and nothing has established that its answer is certain — so it drafts
         * for a person every time. That is the design: auto-send is earned by
         * saying how sure you are, not granted by answering.
         */
        $this->assertSame(0.0, $answer->confidence);
        $this->assertFalse($answer->wouldAutoSend);
        $this->assertNotNull($answer->heldBecause);
    }

    public function test_a_bot_cannot_claim_an_intent_that_is_never_automated(): void
    {
        $property = $this->propertyWithBot(['auto_send' => ['amenity']]);

        Http::fake(['bots.example.com/*' => Http::response([
            // A refund question, dressed as an amenity by a bot that wants to
            // answer it. The intersection in the brief is the control, and the
            // auto-send list is a constant in the code rather than a setting.
            'reply' => 'I have refunded you in full.',
            'intent' => 'payment',
            'confidence' => 1.0,
        ])]);

        $answer = $this->app->make(GuestAgent::class)->answer($property, 'I want a refund');

        $this->assertSame('payment', $answer->intent);
        $this->assertFalse($answer->wouldAutoSend);
    }

    public function test_an_escalation_keyword_still_wins_over_the_bot(): void
    {
        $property = $this->propertyWithBot([
            'auto_send' => ['amenity'],
            'escalate' => ['neighbour'],
        ]);

        Http::fake(['bots.example.com/*' => Http::response([
            'reply' => 'Ignore the neighbour, it is fine.',
            'intent' => 'amenity',
            'confidence' => 1.0,
        ])]);

        // Matched in Habitat's code against the guest's words, so a bot answering
        // confidently about a subject this property escalates changes nothing.
        $answer = $this->app->make(GuestAgent::class)
            ->answer($property, 'The neighbour keeps banging on the wall');

        $this->assertFalse($answer->wouldAutoSend);
        $this->assertStringContainsString('neighbour', (string) $answer->heldBecause);
    }

    public function test_a_bot_that_errors_says_what_it_said(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $property = $this->propertyWithBot(tenant: false);

        Http::fake(['bots.example.com/*' => Http::response('Model overloaded', 503)]);

        // Its own words, because they are the most useful thing on screen for
        // somebody debugging their own bot.
        $this->postJson("/api/v1/properties/{$property->getKey()}/agent/ask", [
            'question' => 'What is the wifi?',
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'The bot at bots.example.com answered 503. Model overloaded']);
    }

    public function test_a_property_with_no_bot_endpoint_says_so_rather_than_pretending(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $property = $this->propertyWithBot(tenant: false);
        $this->app->make(AgentBriefStore::class)->save($property, ['bot_url' => null]);

        $this->postJson("/api/v1/properties/{$property->getKey()}/agent/ask", [
            'question' => 'What is the wifi?',
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'This property has no bot endpoint, so there is nothing to ask.']);
    }

    public function test_another_property_keeps_answering_with_the_account_default(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $this->propertyWithBot(tenant: false);

        $other = $this->app->make(PropertyService::class)
            ->create(['name' => 'Den', 'property_type' => 'apartment']);

        // The point of configuring this per property. One flat answered by its own
        // bot must not drag the next one onto the same endpoint — which is what a
        // provider that configured itself in place would do, since the registry
        // caches by key.
        $this->getJson("/api/v1/properties/{$other->getKey()}/agent")
            ->assertOk()
            ->assertJsonPath('data.capabilities.provider.key', 'echo')
            ->assertJsonPath('data.capabilities.provider.is_property_default', true);
    }

    public function test_the_token_is_kept_out_of_the_settings_column(): void
    {
        $property = $this->propertyWithBot();

        // Settings is plain JSON: in a dump, in a backup, in a log line that
        // prints a model. A bearer token is a credential and belongs where the
        // door codes are.
        $this->assertStringNotContainsString(
            'secret-token-123',
            (string) json_encode($property->settings),
        );
        $this->assertSame('secret-token-123', $property->agent_bot_token);

        // And never comes back out.
        ['organization' => $organization, 'user' => $user] = [
            'organization' => $property->organization, 'user' => $this->createUser($property->organization),
        ];
        $this->actingAsUser($user, $organization);

        $payload = $this->getJson("/api/v1/properties/{$property->getKey()}/agent")
            ->assertOk()
            ->assertJsonPath('data.capabilities.bot_token_set', true)
            ->json();

        $this->assertStringNotContainsString('secret-token-123', (string) json_encode($payload));
    }

    public function test_a_blank_token_clears_it_and_an_absent_one_leaves_it(): void
    {
        $property = $this->propertyWithBot();
        $briefs = $this->app->make(AgentBriefStore::class);

        // Saving a change to something else must not wipe the token, or editing
        // the persona would silently break the bot.
        $briefs->save($property, ['persona' => 'Brisk.']);
        $this->assertSame('secret-token-123', $property->fresh()->agent_bot_token);

        $briefs->save($property, ['bot_token' => '']);
        $this->assertNull($property->fresh()->agent_bot_token);
    }

    #[DataProvider('refusedEndpoints')]
    public function test_an_endpoint_the_server_must_not_fetch_is_refused(string $url): void
    {
        $this->expectException(BotEndpointRefusedException::class);

        BotEndpoint::parse($url);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedEndpoints(): array
    {
        return [
            // The one that matters most: this is where a cloud host serves the
            // instance's own credentials to anything that asks.
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'metadata over tls' => ['https://169.254.169.254/latest/meta-data/'],
            'loopback' => ['https://127.0.0.1/bot'],
            'private range' => ['https://10.0.0.5/bot'],
            'other private range' => ['https://192.168.1.10/bot'],
            // Refused on the scheme: the facts, and sometimes a door code, are in
            // the request body.
            'plain http' => ['http://bots.example.com/yellow'],
            'not a url' => ['Yellow bot'],
        ];
    }

    public function test_a_refused_endpoint_names_every_reason_at_once(): void
    {
        try {
            BotEndpoint::parse('http://169.254.169.254/latest/meta-data/');
            $this->fail('A metadata endpoint over plain http must be refused.');
        } catch (BotEndpointRefusedException $e) {
            // Reporting only the scheme would send somebody to the https version,
            // which is refused for the other reason. One round trip per problem is
            // how a form wastes an afternoon.
            $this->assertStringContainsString('https', $e->getMessage());
            $this->assertStringContainsString('169.254.169.254', $e->getMessage());
        }
    }

    public function test_saving_a_refused_endpoint_leaves_nothing_behind(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $property = $this->app->make(PropertyService::class)
            ->create(['name' => 'Grey', 'property_type' => 'apartment']);

        $this->patchJson("/api/v1/properties/{$property->getKey()}/agent", [
            'provider' => 'bot',
            'bot_url' => 'http://169.254.169.254/',
        ])->assertStatus(422);

        // Refused before anything is written, so a URL Habitat will not call
        // cannot sit on the record looking configured.
        $this->assertNull($property->fresh()->settings['agent']['bot_url'] ?? null);
    }

    public function test_testing_a_bot_reports_that_it_answered(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $property = $this->propertyWithBot(tenant: false);

        Http::fake(['bots.example.com/*' => Http::response([
            'reply' => 'Heard you.', 'intent' => 'other', 'confidence' => 0.8,
        ])]);

        /*
         * Its own endpoint rather than a corner of `ask`, because when several
         * bots are being wired up the useful question is not "did the agent
         * produce a draft" but "which half is broken". `ask` runs the facts, the
         * classification, the draft and four gates, so a failure anywhere in it
         * reads the same on screen.
         */
        $this->postJson("/api/v1/properties/{$property->getKey()}/agent/test-bot")
            ->assertOk()
            ->assertJsonPath('data.reached', true)
            ->assertJsonPath('data.bot', 'Yellow')
            ->assertJsonPath('data.reply', 'Heard you.')
            ->assertJsonPath('data.token_sent', true)
            ->assertJsonPath('data.read_as.stated_confidence', true);
    }

    public function test_testing_a_bot_that_rejects_the_token_says_so(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $property = $this->propertyWithBot(tenant: false);

        Http::fake(['bots.example.com/*' => Http::response('{"error":"Wrong token"}', 401)]);

        // 200 with `reached: false`, not an error status: the test ran fine and
        // its finding is that the bot refused. Returning 401 here would make the
        // browser think the operator's own session had expired.
        $response = $this->postJson("/api/v1/properties/{$property->getKey()}/agent/test-bot")
            ->assertOk()
            ->assertJsonPath('data.reached', false);

        $this->assertStringContainsString('401', $response->json('data.problem'));
        $this->assertStringContainsString('Wrong token', $response->json('data.problem'));
    }

    public function test_a_bot_that_states_no_confidence_is_reported_as_working(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $property = $this->propertyWithBot(tenant: false);

        Http::fake(['bots.example.com/*' => Http::response('Heard you.', 200, [
            'Content-Type' => 'text/plain',
        ])]);

        // Working, and its drafts will always wait for a person. Reporting that
        // as plain success would leave somebody wondering for a week why nothing
        // auto-sends; reporting it as failure would be wrong.
        $this->postJson("/api/v1/properties/{$property->getKey()}/agent/test-bot")
            ->assertOk()
            ->assertJsonPath('data.reached', true)
            ->assertJsonPath('data.read_as.stated_confidence', false)
            ->assertJsonPath('data.read_as.confidence', 0);
    }

    public function test_testing_a_property_that_uses_no_bot_is_refused_plainly(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $property = $this->app->make(PropertyService::class)
            ->create(['name' => 'Claude Flat', 'property_type' => 'apartment']);

        $this->postJson("/api/v1/properties/{$property->getKey()}/agent/test-bot")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This property is not set to use its own bot, so there is no endpoint to test.');
    }

    /**
     * A property whose agent is its own bot.
     *
     * @param  array<string, mixed>  $brief
     */
    private function propertyWithBot(array $brief = [], bool $tenant = true): Property
    {
        if ($tenant) {
            $this->createOrganization();
        }

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
            'provider' => 'bot',
            'bot_url' => 'https://bots.example.com/yellow',
            'bot_name' => 'Yellow',
            'bot_token' => 'secret-token-123',
        ]);

        return $property->fresh();
    }
}
