<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Api\Models\ApiKey;
use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\Permission;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use App\Domain\Users\Support\RoleRegistry;
use App\Domain\Webhooks\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The platform's outward-facing edges over HTTP.
 *
 * Everything here is about credentials, and the rules are the ones that make a
 * leaked credential survivable:
 *
 *  - A secret is shown once and never again, because a system that can show it
 *    to you can show it to somebody else.
 *  - A key can never be more powerful than the person who created it.
 *  - A webhook URL is user-supplied and fetched by our server, so it cannot be
 *    pointed at the private network.
 *  - Nothing is deleted; a revoked key's history is the investigation.
 */
class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization();
        $this->admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);
    }

    // ------------------------------------------------------------------
    // API keys
    // ------------------------------------------------------------------

    public function test_a_key_is_returned_once_and_stored_only_as_a_hash(): void
    {
        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/api-keys', [
                'name' => 'Reporting integration',
                'abilities' => ['reservations.view', 'reports.view'],
            ]);

        $response->assertCreated();

        $token = $response->json('meta.token');

        $this->assertStringStartsWith(ApiKey::TOKEN_PREFIX, $token);

        // Not in the record, and not recoverable from it.
        $this->assertArrayNotHasKey('token', $response->json('data'));
        $this->assertArrayNotHasKey('token_hash', $response->json('data'));

        $stored = DB::table('api_keys')->where('id', $response->json('data.id'))->first();

        $this->assertSame(hash('sha256', $token), $stored->token_hash);
        $this->assertStringNotContainsString($token, json_encode($stored));

        // Reading it back afterwards gives the prefix, which identifies the
        // key without being usable as one.
        $this->actingAsUser($this->admin, $this->organization)
            ->getJson("/api/v1/api-keys/{$response->json('data.id')}")
            ->assertOk()
            ->assertJsonPath('data.prefix', substr($token, 0, 12))
            ->assertJsonMissingPath('data.token');
    }

    public function test_a_key_cannot_be_more_powerful_than_its_creator(): void
    {
        // A role built for this test rather than a stock one, so the
        // permissions in play are stated here instead of depending on what a
        // role definition happens to contain today: it can mint keys and see
        // tasks, and it cannot refund anything.
        $manager = $this->userWithPermissions(['api_keys.manage', 'tasks.view']);

        $access = $this->app->make(AccessControl::class);

        $this->assertTrue($access->allows($manager, 'tasks.view'));
        $this->assertFalse($access->allows($manager, 'payments.refund'));

        $response = $this->actingAsUser($manager, $this->organization)
            ->postJson('/api/v1/api-keys', [
                'name' => 'Overreaching key',
                'abilities' => ['tasks.view', 'payments.refund'],
            ]);

        $response->assertCreated();

        // Without this intersection an API key is a privilege escalation with
        // an audit trail that says somebody else did it.
        $this->assertSame(['tasks.view'], $response->json('data.abilities'));

        // And said plainly, rather than left for somebody to discover when a
        // call 403s in production.
        $this->assertSame(['payments.refund'], $response->json('meta.abilities_refused'));
    }

    public function test_a_wildcard_key_is_refused_to_anybody_but_a_platform_administrator(): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/api-keys', [
                'name' => 'Everything',
                'abilities' => ['*'],
            ])
            ->assertStatus(422);
    }

    public function test_a_revoked_key_stops_working_but_keeps_its_history(): void
    {
        $created = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/api-keys', [
                'name' => 'Retired integration',
                'abilities' => ['reservations.view'],
            ])->assertCreated();

        $id = $created->json('data.id');

        $this->actingAsUser($this->admin, $this->organization)
            ->deleteJson("/api/v1/api-keys/{$id}", ['reason' => 'Rotated after an incident.'])
            ->assertOk()
            ->assertJsonPath('data.is_usable', false);

        // The row survives: what the key did before it was revoked is the
        // whole of the investigation.
        $this->assertDatabaseHas('api_keys', ['id' => $id]);
        $this->assertSame('Rotated after an incident.', ApiKey::query()->find($id)->revoked_reason);

        // ...and it no longer resolves.
        $this->assertNull(ApiKey::findByToken($created->json('meta.token')));
    }

    public function test_a_key_with_no_abilities_can_do_nothing(): void
    {
        ['key' => $key] = ApiKey::issue([
            'organization_id' => $this->organization->getKey(),
            'name' => 'Unscoped',
            'abilities' => [],
        ]);

        // Fails closed: forgetting to set scopes must not produce a key that
        // can do everything.
        $this->assertFalse($key->hasAbility('reservations.view'));
    }

    public function test_api_keys_need_their_own_permission_to_be_listed(): void
    {
        $agent = $this->createUser($this->organization, [RoleRegistry::RESERVATIONS_AGENT]);

        // Knowing which integrations exist and what they are scoped to is
        // reconnaissance, not general settings.
        $this->actingAsUser($agent, $this->organization)
            ->getJson('/api/v1/api-keys')
            ->assertForbidden();
    }

    /**
     * A user holding exactly the permissions named, and nothing else.
     *
     * @param  list<string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::query()->create([
            'organization_id' => $this->organization->getKey(),
            'slug' => 'test-role-'.Str::lower(Str::random(8)),
            'name' => 'Test role',
        ]);

        $role->permissions()->sync(
            Permission::query()->whereIn('name', $permissions)->pluck('id'),
        );

        $user = $this->createUser($this->organization, [$role->slug]);

        $this->app->make(AccessControl::class)->flushMemo();

        return $user->fresh();
    }

    // ------------------------------------------------------------------
    // Webhook endpoints
    // ------------------------------------------------------------------

    public function test_a_signing_secret_is_shown_once_and_never_returned(): void
    {
        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/webhook-endpoints', [
                'name' => 'CRM',
                'url' => 'https://receiver.example.com/hooks',
                'events' => ['reservation.confirmed'],
            ]);

        $response->assertCreated();

        $secret = $response->json('meta.signing_secret');

        $this->assertNotEmpty($secret);
        $this->assertArrayNotHasKey('signing_secret', $response->json('data'));

        // Encrypted at rest, so a database disclosure does not let anybody
        // forge payloads this platform appears to have sent.
        $raw = DB::table('webhook_endpoints')->where('id', $response->json('data.id'))->value('signing_secret');
        $this->assertStringNotContainsString($secret, (string) $raw);

        // And absent from every later read.
        $this->actingAsUser($this->admin, $this->organization)
            ->getJson("/api/v1/webhook-endpoints/{$response->json('data.id')}")
            ->assertOk()
            ->assertJsonMissingPath('data.signing_secret');
    }

    public function test_a_webhook_url_cannot_point_inside_the_infrastructure(): void
    {
        foreach ([
            'http://receiver.example.com/hooks',        // not https
            'https://127.0.0.1/hooks',                  // loopback
            'https://169.254.169.254/latest/meta-data', // cloud metadata
            'https://10.0.0.5/hooks',                   // private network
        ] as $url) {
            $this->actingAsUser($this->admin, $this->organization)
                ->postJson('/api/v1/webhook-endpoints', [
                    'name' => 'Attempted SSRF',
                    'url' => $url,
                ])
                ->assertStatus(422, "URL should have been refused: {$url}");
        }
    }

    public function test_rotating_a_secret_invalidates_the_old_one_immediately(): void
    {
        $created = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/webhook-endpoints', [
                'name' => 'CRM',
                'url' => 'https://receiver.example.com/hooks',
            ])->assertCreated();

        $first = $created->json('meta.signing_secret');

        $rotated = $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/webhook-endpoints/{$created->json('data.id')}/rotate-secret")
            ->assertOk();

        $second = $rotated->json('meta.signing_secret');

        // No overlap window: a rotation is usually a response to a suspected
        // leak, and a period in which the leaked secret still verifies would
        // defeat the point.
        $this->assertNotSame($first, $second);
        $this->assertSame(
            $second,
            WebhookEndpoint::query()->find($created->json('data.id'))->signing_secret,
        );
    }

    public function test_a_test_delivery_reports_what_the_receiver_actually_said(): void
    {
        Http::fake(['*' => Http::response('accepted', 202)]);

        $endpoint = WebhookEndpoint::query()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'CRM',
            'url' => 'https://receiver.example.com/hooks',
            // Subscribed to nothing in particular, to prove the test endpoint
            // ignores the subscription list.
            'events' => ['reservation.cancelled'],
        ]);

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/webhook-endpoints/{$endpoint->getKey()}/test")
            ->assertOk()
            ->assertJsonPath('meta.delivered', true)
            ->assertJsonPath('data.response_status', 202)
            ->assertJsonPath('data.event_name', 'webhook.test');
    }

    public function test_disabling_an_endpoint_keeps_its_delivery_history(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $endpoint = WebhookEndpoint::query()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'CRM',
            'url' => 'https://receiver.example.com/hooks',
        ]);

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/webhook-endpoints/{$endpoint->getKey()}/test")
            ->assertOk();

        $this->actingAsUser($this->admin, $this->organization)
            ->deleteJson("/api/v1/webhook-endpoints/{$endpoint->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.status', WebhookEndpoint::DISABLED);

        // The history is the record of what this platform told an integrator
        // and when.
        $this->assertDatabaseHas('webhook_endpoints', ['id' => $endpoint->getKey()]);

        $this->actingAsUser($this->admin, $this->organization)
            ->getJson("/api/v1/webhook-endpoints/{$endpoint->getKey()}/deliveries")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_re_enabling_an_endpoint_clears_its_failure_count(): void
    {
        $endpoint = WebhookEndpoint::query()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'CRM',
            'url' => 'https://receiver.example.com/hooks',
            'status' => WebhookEndpoint::DISABLED,
        ]);

        $endpoint->forceFill(['consecutive_failures' => 20, 'last_error' => 'gone'])->save();

        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson("/api/v1/webhook-endpoints/{$endpoint->getKey()}", [
                'status' => WebhookEndpoint::ACTIVE,
            ])
            ->assertOk()
            ->assertJsonPath('data.consecutive_failures', 0)
            ->assertJsonPath('data.is_healthy', true);

        // Otherwise an endpoint switched off after twenty failures would be
        // switched off again by its twenty-first.
        $this->assertNull($endpoint->fresh()->last_error);
    }

    public function test_registering_a_webhook_needs_its_own_permission(): void
    {
        $agent = $this->createUser($this->organization, [RoleRegistry::RESERVATIONS_AGENT]);

        // Registering an endpoint causes booking and guest data to be sent to
        // an address of somebody's choosing. That is a data-export decision,
        // not a configuration one.
        $this->actingAsUser($agent, $this->organization)
            ->postJson('/api/v1/webhook-endpoints', [
                'name' => 'Exfiltration',
                'url' => 'https://elsewhere.example.com/hooks',
            ])
            ->assertForbidden();
    }
}
