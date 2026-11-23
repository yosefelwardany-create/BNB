<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Integrations\Providers\Channels\ChannexChannelAdapter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Which Channex a connection talks to.
 *
 * Certification is done on staging.channex.io; live properties are on
 * app.channex.io, and a key from one is refused by the other. A connection
 * made now talks to production unless it is set up for staging, and the
 * connections made during certification stay on staging, where their keys
 * work.
 */
class ChannexServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_connection_that_does_not_say_talks_to_production(): void
    {
        $this->assertSame('https://app.channex.io/api/v1/', $this->firstCallOf($this->connection([])));
    }

    public function test_a_staging_connection_talks_to_staging(): void
    {
        $this->assertSame(
            'https://staging.channex.io/api/v1/',
            $this->firstCallOf($this->connection(['base_url' => ChannexChannelAdapter::SERVERS['staging']])),
        );
    }

    public function test_only_channexs_own_servers_can_be_chosen(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        Http::fake();

        // Anywhere else would be handed the connection's API key.
        $this->actingAsUser($user, $organization)
            ->postJson('/api/v1/channels', ['channel' => 'channex', 'name' => 'Channex', 'credentials' => [
                'access_token' => 'key', 'base_url' => 'https://channex.example.net/api/v1',
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credentials');

        $id = $this->actingAsUser($user, $organization)
            ->postJson('/api/v1/channels', ['channel' => 'channex', 'name' => 'Channex staging', 'credentials' => [
                'access_token' => 'key', 'base_url' => ChannexChannelAdapter::SERVERS['staging'],
            ]])
            ->assertCreated()
            ->json('data.id');

        // Both kept: the server, and the key it is for.
        $account = $this->withoutScope(fn () => ChannelAccount::query()->findOrFail($id));
        $this->assertSame('key', $account->credential('access_token'));
        $this->assertSame(ChannexChannelAdapter::SERVERS['staging'], $account->credential('base_url'));
    }

    public function test_a_new_key_keeps_the_connections_server(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $account = $this->connection(['base_url' => ChannexChannelAdapter::SERVERS['staging']], $organization->getKey());

        $this->actingAsUser($user, $organization)
            ->patchJson('/api/v1/channels/'.$account->getKey(), ['credentials' => ['access_token' => 'new-key']])
            ->assertOk();

        $account = $this->withoutScope(fn () => ChannelAccount::query()->findOrFail($account->getKey()));
        $this->assertSame('new-key', $account->credential('access_token'));
        $this->assertSame(ChannexChannelAdapter::SERVERS['staging'], $account->credential('base_url'));
    }

    public function test_the_connections_made_during_certification_stay_on_staging(): void
    {
        $channex = $this->connection([]);
        $hostex = $this->connection([], channel: 'hostex');

        (require database_path('migrations/2026_11_19_000100_pin_existing_channex_connections_to_staging.php'))->up();

        $this->assertSame(ChannexChannelAdapter::SERVERS['staging'], $this->withoutScope(fn () => $channex->fresh())->credential('base_url'));
        $this->assertSame('key', $this->withoutScope(fn () => $channex->fresh())->credential('access_token'));
        $this->assertNull($this->withoutScope(fn () => $hostex->fresh())->credential('base_url'));
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function connection(array $credentials, ?string $organizationId = null, string $channel = 'channex'): ChannelAccount
    {
        $organizationId ??= $this->createOrganization()->getKey();

        $account = ChannelAccount::query()->create([
            'organization_id' => $organizationId,
            'channel' => $channel,
            'name' => ucfirst($channel),
            'status' => ChannelAccount::STATUS_CONNECTED,
            'credentials' => ['access_token' => 'key'] + $credentials,
        ]);
        $this->app->make(TenantContext::class)->clear();

        return $account;
    }

    /**
     * Where the connection's first request to Channex went: the start of the
     * address, up to and including the API's root.
     */
    private function firstCallOf(ChannelAccount $account): string
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

        $this->app->make(ChannexChannelAdapter::class)->importConversations($account);

        $url = Http::recorded()->first()[0] ?? null;
        $this->assertInstanceOf(Request::class, $url);

        return (string) preg_replace('#^(https://[^/]+/api/v1/).*$#', '$1', $url->url());
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function withoutScope(callable $read): mixed
    {
        return $this->app->make(TenantContext::class)->withoutScope($read);
    }
}
