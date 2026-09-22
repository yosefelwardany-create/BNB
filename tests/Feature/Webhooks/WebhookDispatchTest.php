<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Domain\Events\Models\DomainEvent;
use App\Domain\Organization\Models\Organization;
use App\Domain\Webhooks\Models\WebhookDelivery;
use App\Domain\Webhooks\Models\WebhookEndpoint;
use App\Domain\Webhooks\Services\WebhookDispatcher;
use App\Domain\Webhooks\Services\WebhookSigner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Outbound webhooks.
 *
 * The properties being protected here are the ones that make an integration
 * trustworthy rather than merely functional:
 *
 *  - Payloads are signed in a way that cannot be replayed.
 *  - A receiver that rejects a request is not asked again; one that is merely
 *    down is.
 *  - Every attempt leaves a record of what was sent and what came back,
 *    because "it didn't arrive" is the only complaint integrators ever make.
 *  - A redelivered job cannot send the same event twice.
 */
class WebhookDispatchTest extends TestCase
{
    use RefreshDatabase;

    private WebhookDispatcher $dispatcher;

    private WebhookSigner $signer;

    private Organization $organization;

    /**
     * Event rows created by {@see event()}, keyed by the label the test used.
     *
     * Real rows rather than invented ids: deliveries carry a foreign key to
     * the event store, which is what lets a receiver's "I never got event X"
     * be answered against the event we actually recorded.
     *
     * @var array<string, string>
     */
    private array $eventIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = $this->app->make(WebhookDispatcher::class);
        $this->signer = $this->app->make(WebhookSigner::class);

        $this->organization = $this->createOrganization();
    }

    // ------------------------------------------------------------------
    // Signing
    // ------------------------------------------------------------------

    public function test_a_signature_verifies_only_against_the_body_it_covers(): void
    {
        $secret = WebhookEndpoint::freshSecret();
        $body = '{"event":"reservation.confirmed"}';

        $header = $this->signer->sign($body, $secret);

        $this->assertTrue($this->signer->verify($body, $header, $secret));

        // A single altered byte invalidates it. Without this the signature
        // would prove only that somebody once sent *something*.
        $this->assertFalse($this->signer->verify('{"event":"reservation.cancelled"}', $header, $secret));
        $this->assertFalse($this->signer->verify($body, $header, WebhookEndpoint::freshSecret()));
    }

    public function test_an_old_signature_is_refused_however_valid(): void
    {
        $secret = WebhookEndpoint::freshSecret();
        $body = '{"event":"test"}';

        $header = $this->signer->sign($body, $secret, time() - 3600);

        // Correctly signed, and still refused. Without the tolerance, anybody
        // who ever captured a valid request could replay it forever.
        $this->assertFalse($this->signer->verify($body, $header, $secret));

        // ...and accepted when the receiver chooses a wider window.
        $this->assertTrue($this->signer->verify($body, $header, $secret, toleranceSeconds: 7200));
    }

    public function test_the_timestamp_cannot_be_swapped_for_a_fresh_one(): void
    {
        $secret = WebhookEndpoint::freshSecret();
        $body = '{"event":"test"}';

        $old = $this->signer->sign($body, $secret, time() - 3600);

        // Take the old signature and pair it with a current timestamp — the
        // exact move the scheme exists to defeat. It fails because the
        // timestamp is inside the signed string, not merely beside it.
        preg_match('/v1=([a-f0-9]+)/', $old, $matches);
        $forged = sprintf('t=%d,v1=%s', time(), $matches[1]);

        $this->assertFalse($this->signer->verify($body, $forged, $secret));
    }

    public function test_a_malformed_header_is_refused_rather_than_crashing(): void
    {
        $secret = WebhookEndpoint::freshSecret();

        foreach (['', 'garbage', 't=notanumber,v1=abc', 'v1=abc', 't=123'] as $header) {
            $this->assertFalse($this->signer->verify('{}', $header, $secret));
        }
    }

    // ------------------------------------------------------------------
    // Delivery
    // ------------------------------------------------------------------

    public function test_a_successful_delivery_is_signed_and_recorded(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $endpoint = $this->endpoint();

        $delivery = $this->dispatcher->test($endpoint);

        $this->assertSame(WebhookDelivery::SUCCEEDED, $delivery->status);
        $this->assertSame(200, $delivery->response_status);
        $this->assertNotNull($delivery->completed_at);

        Http::assertSent(function (ClientRequest $request) use ($endpoint): bool {
            $header = $request->header(WebhookSigner::HEADER)[0] ?? '';

            return $this->signer->verify($request->body(), $header, $endpoint->signing_secret);
        });

        // A run of successes clears the failure count, so an endpoint that
        // recovers is not eventually disabled by history.
        $this->assertSame(0, (int) $endpoint->fresh()->consecutive_failures);
        $this->assertNotNull($endpoint->fresh()->last_success_at);
    }

    public function test_a_receiver_that_rejects_the_request_is_not_retried(): void
    {
        Http::fake(['*' => Http::response('unknown signature', 400)]);

        $delivery = $this->dispatcher->test($this->endpoint());

        // A 4xx means the receiver looked at this request and refused it.
        // Sending identical bytes again cannot change their answer.
        $this->assertSame(WebhookDelivery::ABANDONED, $delivery->status);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertSame(400, $delivery->response_status);
    }

    public function test_a_receiver_that_is_merely_down_is_retried(): void
    {
        Http::fake(['*' => Http::response('gateway error', 502)]);

        $delivery = $this->dispatcher->test($this->endpoint());

        $this->assertSame(WebhookDelivery::FAILED, $delivery->status);
        $this->assertNotNull($delivery->next_attempt_at);
    }

    public function test_a_rate_limited_or_timed_out_receiver_is_retried(): void
    {
        foreach ([408, 429] as $status) {
            Http::fake(['*' => Http::response('slow down', $status)]);

            $delivery = $this->dispatcher->test($this->endpoint());

            // Both are 4xx and both mean "try again", which is why the
            // permanence rule carves them out rather than treating the whole
            // 4xx range alike.
            $this->assertSame(WebhookDelivery::FAILED, $delivery->status, "status {$status}");
            $this->assertNotNull($delivery->next_attempt_at);
        }
    }

    public function test_the_response_body_is_kept_because_it_is_the_diagnosis(): void
    {
        Http::fake(['*' => Http::response('missing field: reservation_id', 422)]);

        $delivery = $this->dispatcher->test($this->endpoint());

        $this->assertStringContainsString('reservation_id', (string) $delivery->response_body);
    }

    public function test_an_endpoint_that_keeps_failing_is_eventually_switched_off(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);

        $endpoint = $this->endpoint(['failure_threshold' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->dispatcher->test($endpoint->fresh());
        }

        // Disabled rather than deleted: the operator has to be able to see
        // that it was switched off, and why.
        $this->assertSame(WebhookEndpoint::DISABLED, $endpoint->fresh()->status);
        $this->assertNotNull($endpoint->fresh()->last_error);
    }

    public function test_attempts_stop_at_the_endpoints_limit(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        $endpoint = $this->endpoint(['max_attempts' => 2, 'failure_threshold' => 100]);

        $first = WebhookDelivery::query()->create([
            'organization_id' => $this->organization->getKey(),
            'webhook_endpoint_id' => $endpoint->getKey(),
            'event_name' => 'reservation.confirmed',
            'payload' => ['event' => 'reservation.confirmed'],
            'attempt' => 2,
        ]);

        $settled = $this->dispatcher->send($first);

        // The last permitted attempt is abandoned rather than scheduled again,
        // so a permanently dead endpoint stops consuming queue capacity.
        $this->assertSame(WebhookDelivery::ABANDONED, $settled->status);
        $this->assertNull($settled->next_attempt_at);
    }

    // ------------------------------------------------------------------
    // Subscriptions and duplication
    // ------------------------------------------------------------------

    public function test_an_endpoint_with_no_subscription_list_receives_everything(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->endpoint(['events' => null]);

        $queued = $this->dispatcher->dispatch('reservation.confirmed', ['id' => 'x'], $this->event('evt-1'));

        // Too much rather than silence: an integrator who forgets to subscribe
        // notices extra traffic, and never notices an empty queue.
        $this->assertSame(1, $queued);
    }

    public function test_an_endpoint_only_receives_the_events_it_asked_for(): void
    {
        $this->endpoint(['events' => ['reservation.cancelled']]);

        $this->assertSame(0, $this->dispatcher->dispatch('reservation.confirmed', [], $this->event('evt-2')));
        $this->assertSame(1, $this->dispatcher->dispatch('reservation.cancelled', [], $this->event('evt-3')));
    }

    public function test_the_same_event_cannot_be_queued_twice_for_one_endpoint(): void
    {
        $this->endpoint();

        $this->assertSame(1, $this->dispatcher->dispatch('reservation.confirmed', [], $this->event('evt-4')));

        // A redelivered job, or two producers racing. The partial unique index
        // on (endpoint, event, attempt) is what makes this harmless.
        $this->assertSame(0, $this->dispatcher->dispatch('reservation.confirmed', [], $this->event('evt-4')));

        $this->assertSame(1, WebhookDelivery::query()->where('domain_event_id', $this->event('evt-4'))->count());
    }

    public function test_the_payload_carries_enough_to_route_and_deduplicate(): void
    {
        $this->endpoint();

        $this->dispatcher->dispatch('reservation.confirmed', ['confirmation_code' => 'ABC123'], $this->event('evt-5'));

        $payload = WebhookDelivery::query()->where('domain_event_id', $this->event('evt-5'))->first()->payload;

        // A receiver that logs only the body still has everything it needs.
        $this->assertSame($this->eventIds['evt-5'], $payload['id']);
        $this->assertSame('reservation.confirmed', $payload['event']);
        $this->assertSame($this->organization->getKey(), $payload['organization_id']);
        $this->assertSame('ABC123', $payload['data']['confirmation_code']);
        $this->assertNotEmpty($payload['occurred_at']);
    }

    // ------------------------------------------------------------------
    // Retries
    // ------------------------------------------------------------------

    public function test_a_due_retry_becomes_a_new_attempt_rather_than_a_rewrite(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);

        $endpoint = $this->endpoint(['failure_threshold' => 100]);

        $this->dispatcher->dispatch('reservation.confirmed', [], $this->event('evt-6'));

        $delivery = WebhookDelivery::query()->where('domain_event_id', $this->event('evt-6'))->firstOrFail();
        $this->dispatcher->send($delivery);

        // Pretend the backoff has elapsed.
        $delivery->fresh()->forceFill(['next_attempt_at' => CarbonImmutable::now()->subMinute()])->save();

        $result = $this->dispatcher->retryDue();

        $this->assertSame(1, $result['retried']);

        $attempts = WebhookDelivery::query()
            ->where('domain_event_id', $this->event('evt-6'))
            ->orderBy('attempt')
            ->pluck('attempt')
            ->all();

        // Two rows, not one overwritten: the first attempt's response is the
        // evidence of what went wrong.
        $this->assertSame([1, 2], $attempts);
    }

    public function test_a_settled_retry_is_not_swept_up_again(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);

        $endpoint = $this->endpoint(['failure_threshold' => 100]);

        $this->dispatcher->dispatch('reservation.confirmed', [], $this->event('evt-7'));

        $delivery = WebhookDelivery::query()->where('domain_event_id', $this->event('evt-7'))->firstOrFail();
        $this->dispatcher->send($delivery);
        $delivery->fresh()->forceFill(['next_attempt_at' => CarbonImmutable::now()->subMinute()])->save();

        $this->dispatcher->retryDue();

        // The second sweep finds nothing: the first cleared the schedule on
        // the row it acted on, so a slow sweep cannot fan out attempts.
        $this->assertSame(0, $this->dispatcher->retryDue()['retried']);
    }

    /**
     * Record a domain event and return its id.
     */
    private function event(string $label): string
    {
        return $this->eventIds[$label] ??= (string) DomainEvent::query()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'reservation.confirmed',
            'payload' => ['label' => $label],
            'occurred_at' => now(),
        ])->getKey();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function endpoint(array $attributes = []): WebhookEndpoint
    {
        return WebhookEndpoint::query()->create(array_merge([
            'organization_id' => $this->organization->getKey(),
            'name' => 'Integration',
            'url' => 'https://receiver.example.test/hooks',
            'status' => WebhookEndpoint::ACTIVE,
        ], $attributes));
    }
}
