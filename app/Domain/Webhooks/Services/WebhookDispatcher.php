<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Services;

use App\Domain\Webhooks\Jobs\DeliverWebhook;
use App\Domain\Webhooks\Models\WebhookDelivery;
use App\Domain\Webhooks\Models\WebhookEndpoint;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends domain events to the outside world.
 *
 * Queueing rather than sending inline is not an optimisation, it is a
 * correctness requirement: a webhook receiver that hangs must not be able to
 * hold open the transaction that confirmed a booking, and one that is down
 * must not be able to fail it.
 *
 * Retries are exponential and bounded, and they distinguish between an
 * endpoint that is broken and a request that is wrong. A 4xx means the
 * receiver has looked at this request and rejected it; sending the identical
 * bytes again cannot change their mind, so it is abandoned immediately.
 * Everything else — a timeout, a 500, a refused connection — is retried.
 *
 * Every attempt is recorded with what was sent and what came back. "It didn't
 * arrive" is the most common integration complaint there is, and the only
 * useful answer is the response the receiver actually gave.
 */
class WebhookDispatcher
{
    public function __construct(
        private readonly WebhookSigner $signer,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Queue an event to every endpoint that wants it.
     *
     * @param  array<string, mixed>  $payload
     * @return int the number of endpoints it was queued for
     */
    public function dispatch(string $eventName, array $payload, ?string $domainEventId = null): int
    {
        $endpoints = WebhookEndpoint::query()->subscribedTo($eventName)->get();

        $queued = 0;

        foreach ($endpoints as $endpoint) {
            $delivery = $this->record($endpoint, $eventName, $payload, $domainEventId, 1);

            if ($delivery === null) {
                // Already queued for this endpoint and event: a redelivered
                // job, or two producers racing. Sending twice is worse than
                // not sending again.
                continue;
            }

            DeliverWebhook::dispatch($delivery->getKey(), $this->tenancy->id());
            $queued++;
        }

        return $queued;
    }

    /**
     * Send one recorded attempt.
     *
     * Returns the delivery in its settled state. Never throws for a failing
     * receiver — a broken endpoint is an ordinary outcome here, recorded and
     * retried, not an exception that would mark the queue job failed and lose
     * the schedule.
     */
    public function send(WebhookDelivery $delivery): WebhookDelivery
    {
        $endpoint = $delivery->endpoint;

        if ($endpoint === null || $endpoint->status === WebhookEndpoint::DISABLED) {
            return $this->abandon($delivery, 'The endpoint no longer exists or has been disabled.');
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);
        $delivery->forceFill(['dispatched_at' => now()])->save();

        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders($this->headersFor($endpoint, $delivery, $body))
                ->timeout((int) $endpoint->timeout_seconds)
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $duration = (int) ((microtime(true) - $startedAt) * 1000);

            if ($response->successful()) {
                return $this->succeed($delivery, $endpoint, $response->status(), $response->body(), $duration);
            }

            return $this->fail(
                $delivery,
                $endpoint,
                $response->status(),
                $response->body(),
                $duration,
                sprintf('The endpoint answered %d.', $response->status()),
            );
        } catch (ConnectionException $exception) {
            // Unreachable, refused, timed out: nothing about the request is
            // wrong, so this is always worth retrying.
            return $this->fail(
                $delivery,
                $endpoint,
                null,
                null,
                (int) ((microtime(true) - $startedAt) * 1000),
                $exception->getMessage(),
            );
        } catch (\Throwable $exception) {
            Log::error('A webhook delivery raised an unexpected error.', [
                'webhook_delivery_id' => $delivery->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return $this->fail(
                $delivery,
                $endpoint,
                null,
                null,
                (int) ((microtime(true) - $startedAt) * 1000),
                $exception->getMessage(),
            );
        }
    }

    /**
     * Send one event to one endpoint immediately, ignoring subscriptions.
     *
     * What the "send a test event" button calls. It deliberately bypasses the
     * subscription list, because the point is to prove the endpoint works
     * before deciding what it should receive.
     *
     * @param  array<string, mixed>  $payload
     */
    public function test(WebhookEndpoint $endpoint, array $payload = []): WebhookDelivery
    {
        $delivery = WebhookDelivery::query()->create([
            'organization_id' => $endpoint->organization_id,
            'webhook_endpoint_id' => $endpoint->getKey(),
            'event_name' => 'webhook.test',
            'payload' => $this->envelope('webhook.test', $payload + [
                'message' => 'This is a test delivery from the platform.',
            ], null),
            'attempt' => 1,
        ]);

        return $this->send($delivery);
    }

    /**
     * Retry every attempt whose backoff has elapsed.
     *
     * @return array{retried: int}
     */
    public function retryDue(): array
    {
        $retried = 0;

        WebhookDelivery::query()
            ->dueForRetry()
            ->with('endpoint')
            ->chunkById(100, function ($deliveries) use (&$retried): void {
                foreach ($deliveries as $delivery) {
                    $next = $this->record(
                        $delivery->endpoint,
                        $delivery->event_name,
                        $delivery->payload,
                        $delivery->domain_event_id,
                        (int) $delivery->attempt + 1,
                    );

                    // The failed row is settled either way, so it is not
                    // picked up again on the next sweep.
                    $delivery->forceFill(['next_attempt_at' => null])->save();

                    if ($next === null) {
                        continue;
                    }

                    DeliverWebhook::dispatch($next->getKey(), $next->organization_id);
                    $retried++;
                }
            });

        return ['retried' => $retried];
    }

    /**
     * The standard envelope.
     *
     * Every payload carries its own event name and id rather than relying on
     * the URL or a header to identify it, so a receiver that logs the body
     * alone has everything it needs to deduplicate and route.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function envelope(string $eventName, array $data, ?string $domainEventId): array
    {
        return [
            'id' => $domainEventId,
            'event' => $eventName,
            'occurred_at' => now()->toIso8601String(),
            'organization_id' => $this->tenancy->id(),
            'data' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(
        ?WebhookEndpoint $endpoint,
        string $eventName,
        array $payload,
        ?string $domainEventId,
        int $attempt,
    ): ?WebhookDelivery {
        if ($endpoint === null) {
            return null;
        }

        try {
            // Its own transaction so that catching the violation below is
            // safe: PostgreSQL aborts the whole transaction on any error, so
            // a caught unique violation inside an outer transaction would
            // poison everything after it.
            return DB::transaction(fn (): WebhookDelivery => WebhookDelivery::query()->create([
                'organization_id' => $endpoint->organization_id,
                'webhook_endpoint_id' => $endpoint->getKey(),
                'domain_event_id' => $domainEventId,
                'event_name' => $eventName,
                'payload' => $domainEventId === null
                    ? $payload
                    : $this->envelope($eventName, $payload, $domainEventId),
                'attempt' => $attempt,
            ]));
        } catch (UniqueConstraintViolationException) {
            // This endpoint already has this attempt for this event. The
            // partial unique index is what makes a redelivered job harmless.
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(WebhookEndpoint $endpoint, WebhookDelivery $delivery, string $body): array
    {
        return array_merge($endpoint->headers, [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Habitat-Webhooks/1.0',
            WebhookSigner::HEADER => $this->signer->sign($body, (string) $endpoint->signing_secret),
            // Enough for a receiver to deduplicate without parsing the body,
            // and to quote back when reporting a problem.
            'X-Habitat-Event' => $delivery->event_name,
            'X-Habitat-Delivery' => (string) $delivery->getKey(),
            'X-Habitat-Attempt' => (string) $delivery->attempt,
        ]);
    }

    private function succeed(
        WebhookDelivery $delivery,
        WebhookEndpoint $endpoint,
        int $status,
        string $body,
        int $duration,
    ): WebhookDelivery {
        $delivery->forceFill([
            'status' => WebhookDelivery::SUCCEEDED,
            'response_status' => $status,
            'response_body' => $this->truncate($body),
            'duration_ms' => $duration,
            'completed_at' => now(),
            'next_attempt_at' => null,
            'error_message' => null,
        ])->save();

        $endpoint->forceFill([
            'consecutive_failures' => 0,
            'last_success_at' => now(),
            'last_error' => null,
        ])->save();

        return $delivery;
    }

    private function fail(
        WebhookDelivery $delivery,
        WebhookEndpoint $endpoint,
        ?int $status,
        ?string $body,
        int $duration,
        string $error,
    ): WebhookDelivery {
        $attempt = (int) $delivery->attempt;

        // A 4xx is the receiver having looked at this request and rejected
        // it. Sending the identical bytes again cannot change that answer, so
        // there is nothing to retry.
        $permanent = WebhookDelivery::isPermanent($status);
        $exhausted = $attempt >= (int) $endpoint->max_attempts;

        $delivery->forceFill([
            'status' => $permanent || $exhausted
                ? WebhookDelivery::ABANDONED
                : WebhookDelivery::FAILED,
            'response_status' => $status,
            'response_body' => $this->truncate($body),
            'duration_ms' => $duration,
            'error_message' => $this->truncate($error, 250),
            'completed_at' => now(),
            'next_attempt_at' => $permanent || $exhausted
                ? null
                : now()->addSeconds($endpoint->backoffSeconds($attempt)),
        ])->save();

        $failures = (int) $endpoint->consecutive_failures + 1;

        $endpoint->forceFill([
            'consecutive_failures' => $failures,
            'last_failure_at' => now(),
            'last_error' => $this->truncate($error, 250),
            // An endpoint that has been gone for days should stop consuming
            // queue capacity. Disabled rather than deleted: the operator needs
            // to see that it was switched off and why.
            'status' => $failures >= (int) $endpoint->failure_threshold
                ? WebhookEndpoint::DISABLED
                : $endpoint->status,
        ])->save();

        return $delivery;
    }

    private function abandon(WebhookDelivery $delivery, string $reason): WebhookDelivery
    {
        $delivery->forceFill([
            'status' => WebhookDelivery::ABANDONED,
            'error_message' => $reason,
            'completed_at' => now(),
            'next_attempt_at' => null,
        ])->save();

        return $delivery;
    }

    /**
     * Receivers return everything from an empty body to a megabyte of HTML.
     * Enough to diagnose, not enough to fill the table.
     */
    private function truncate(?string $value, int $length = 4000): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
