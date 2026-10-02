<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelWebhookEvent;
use App\Domain\Integrations\Providers\Channels\HostexChannelAdapter;
use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Two companies, two Hostex accounts, no leaks.
 *
 * This platform is sold to several companies at once, and each brings its own
 * Hostex with its own properties in it. That makes the connection a per-company
 * record rather than a deployment setting, and it makes a handful of things that
 * would otherwise be conveniences into isolation boundaries:
 *
 *  - The token lives on the channel account, encrypted, not in configuration.
 *    A token in `config/` would be one company's Hostex serving everybody.
 *  - Every webhook has its own URL, carrying that account's id. A shared
 *    endpoint would have to work out whose booking it was holding from the
 *    payload, which is exactly the guess that puts one company's reservation on
 *    another's calendar.
 *  - Every webhook has its own secret. One company's secret must not open
 *    another company's endpoint, or a customer could inject bookings into a
 *    competitor sharing the deployment.
 *
 * None of that is visible in ordinary use, which is why it is tested rather than
 * assumed.
 */
class HostexMultiTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_company_authenticates_with_its_own_token(): void
    {
        [$first, $second] = $this->twoCompanies();

        Http::fake(['api.hostex.io/*' => Http::response(['data' => ['properties' => []]], 200)]);

        $adapter = $this->app->make(HostexChannelAdapter::class);

        $adapter->importListings($first['account']);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Hostex-Access-Token', 'token-first'));

        $adapter->importListings($second['account']);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Hostex-Access-Token', 'token-second'));
    }

    public function test_one_company_cannot_see_another_connection(): void
    {
        [$first, $second] = $this->twoCompanies();

        $this->actingAsUser($first['user'], $first['organization']);

        $this->getJson('/api/v1/channels')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first['account']->getKey());

        // Not merely absent from the list: asking for it by name is a 404, the
        // same answer as an id that never existed.
        $this->getJson("/api/v1/channels/{$second['account']->getKey()}")->assertStatus(404);
    }

    public function test_each_connection_gets_its_own_webhook_url_and_secret(): void
    {
        [$first, $second] = $this->twoCompanies();

        $this->actingAsUser($first['user'], $first['organization']);
        $firstSecret = $this->postJson("/api/v1/channels/{$first['account']->getKey()}/webhook")
            ->assertOk()
            ->json('data');

        $this->actingAsUser($second['user'], $second['organization']);
        $secondSecret = $this->postJson("/api/v1/channels/{$second['account']->getKey()}/webhook")
            ->assertOk()
            ->json('data');

        $this->assertNotSame($firstSecret['secret'], $secondSecret['secret']);
        $this->assertNotSame($firstSecret['url'], $secondSecret['url']);
        $this->assertStringContainsString($first['account']->getKey(), $firstSecret['url']);
    }

    public function test_one_company_secret_does_not_open_another_company_endpoint(): void
    {
        Queue::fake();
        [$first, $second] = $this->twoCompanies();

        $this->actingAsUser($first['user'], $first['organization']);
        $firstSecret = $this->postJson("/api/v1/channels/{$first['account']->getKey()}/webhook")
            ->json('data.secret');

        $this->actingAsUser($second['user'], $second['organization']);
        $this->postJson("/api/v1/channels/{$second['account']->getKey()}/webhook");

        // The crux. A customer on this deployment holds a working secret; it
        // must not let them push a booking into somebody else's calendar.
        $this->postJson(
            "/webhooks/channels/{$second['account']->getKey()}",
            ['event' => 'reservation_created', 'id' => 'evt-x', 'data' => []],
            ['Hostex-Webhook-Secret-Token' => $firstSecret],
        )->assertStatus(404);

        $this->assertSame(0, ChannelWebhookEvent::query()->withoutGlobalScope('organization')->count());
    }

    public function test_an_event_is_recorded_against_the_company_whose_endpoint_took_it(): void
    {
        Queue::fake();
        [$first, $second] = $this->twoCompanies();

        $this->actingAsUser($second['user'], $second['organization']);
        $secret = $this->postJson("/api/v1/channels/{$second['account']->getKey()}/webhook")
            ->json('data.secret');

        $this->postJson(
            "/webhooks/channels/{$second['account']->getKey()}",
            ['event' => 'reservation_created', 'id' => 'evt-y', 'data' => []],
            ['Hostex-Webhook-Secret-Token' => $secret],
        )->assertStatus(202);

        $event = ChannelWebhookEvent::query()->withoutGlobalScope('organization')->firstOrFail();

        $this->assertSame($second['organization']->getKey(), $event->organization_id);
        $this->assertNotSame($first['organization']->getKey(), $event->organization_id);
    }

    public function test_rotating_a_secret_stops_the_one_it_replaced(): void
    {
        Queue::fake();
        [$first] = $this->twoCompanies();

        $this->actingAsUser($first['user'], $first['organization']);

        $old = $this->postJson("/api/v1/channels/{$first['account']->getKey()}/webhook")->json('data.secret');
        $new = $this->postJson("/api/v1/channels/{$first['account']->getKey()}/webhook")
            ->assertJsonPath('data.was_rotated', true)
            ->json('data.secret');

        $this->assertNotSame($old, $new);

        $send = fn (string $secret, string $id) => $this->postJson(
            "/webhooks/channels/{$first['account']->getKey()}",
            ['event' => 'message_created', 'id' => $id, 'data' => []],
            ['Hostex-Webhook-Secret-Token' => $secret],
        );

        // Immediately, which is the point of rotating — and the reason the
        // response warns that events are refused until the new one is in the
        // channel's settings.
        $send($old, 'evt-old')->assertStatus(404);
        $send($new, 'evt-new')->assertStatus(202);
    }

    /**
     * Two companies on one deployment, each with its own Hostex.
     *
     * @return array{0: array{organization: Organization, user: User, account: ChannelAccount}, 1: array{organization: Organization, user: User, account: ChannelAccount}}
     */
    private function twoCompanies(): array
    {
        return [
            $this->company('Harbour Stays', 'token-first'),
            $this->company('Lakeview Lets', 'token-second'),
        ];
    }

    /**
     * @return array{organization: Organization, user: User, account: ChannelAccount}
     */
    private function company(string $name, string $token): array
    {
        $organization = $this->createOrganization(['name' => $name]);
        $user = $this->createUser($organization);

        $this->actingAsUser($user, $organization);

        $account = ChannelAccount::query()->create([
            'organization_id' => $organization->getKey(),
            'channel' => 'hostex',
            'name' => 'Hostex',
            'credentials' => ['access_token' => $token],
            'status' => ChannelAccount::STATUS_CONNECTED,
        ]);

        return ['organization' => $organization, 'user' => $user, 'account' => $account];
    }
}
