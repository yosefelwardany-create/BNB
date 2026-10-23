<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

use App\Domain\Integrations\Exceptions\ChannexRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The HTTP half of the Channex integration.
 *
 * Separated from the adapter so that the two things most likely to be wrong —
 * how a request is authenticated, and how an error is recognised — live in one
 * small file with their own tests rather than being repeated at a dozen call
 * sites.
 *
 * ## The error rule, which is not the usual one
 *
 * Channex answers **200 OK for a message it only partly accepted**. Their own
 * documentation is explicit about it: "this response will be marked at Success
 * and will have header 200 OK. It is happened, because one message can be
 * rejected, but another will be successfully produced."
 *
 * So a client written the normal way sees 200, calls the whole push a success,
 * clears the dirty flag and stores a content hash — and the nights Channex
 * refused stay wrong on every connected OTA until something else happens to
 * touch them. That is worse than an outright failure, because an outright
 * failure is visible.
 *
 * Success here is therefore "2xx **and** `meta.warnings` is empty". Anything
 * else raises, carrying the warnings so they reach the sync job rather than
 * the void.
 *
 * ## The task id
 *
 * Every accepted ARI write returns `data[].id` with `type: "task"`. That id is
 * how Channex's support team identifies a push, and it is what their
 * certification form asks to be quoted per test, so it is lifted out here and
 * handed back to the caller rather than left in the body.
 */
class ChannexClient
{
    /**
     * Statuses worth trying again.
     *
     * 429 is the ARI rate limit (10 availability and 10 restriction requests
     * per minute, per property); the rest are the ordinary transient server
     * failures. Everything else — a bad key, an unknown rate plan, a past date
     * — will fail again in exactly the same way, and retrying it only delays
     * telling somebody.
     *
     * @var list<int>
     */
    private const RETRYABLE = [429, 500, 502, 503, 504];

    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $baseUrl = null,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws ChannexRequestException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws ChannexRequestException
     */
    public function post(string $path, array $body = []): array
    {
        return $this->send('post', $path, $body);
    }

    /**
     * The id Channex gave the accepted write, if it gave one.
     *
     * Reads `data[].id` where `type` is `task`. Returns null rather than
     * guessing when the shape is not what is expected: a made-up reference
     * would be worse than none, because it would be quoted on a support ticket
     * and match nothing.
     *
     * @param  array<string, mixed>  $body
     */
    public static function taskId(array $body): ?string
    {
        $data = $body['data'] ?? null;

        if (! is_array($data)) {
            return null;
        }

        // A single object rather than a list, which some endpoints return.
        if (isset($data['id']) && is_string($data['id'])) {
            return $data['id'];
        }

        foreach ($data as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'task' && is_string($entry['id'] ?? null)) {
                return $entry['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ChannexRequestException
     */
    private function send(string $method, string $path, array $payload): array
    {
        // Everything that is not a GET changes something on Channex, and a
        // deployment with outbound side effects switched off — a test
        // environment, or a staging copy pointed at a real key by mistake —
        // refuses it here, at the one place every Channex write passes through.
        if ($method !== 'get' && ! config('pms.outbound.enabled', true)) {
            throw new ChannexRequestException(
                'Outbound Channex changes are disabled in this environment (OUTBOUND_INTEGRATIONS_ENABLED=false).',
                retryable: false,
            );
        }

        $url = rtrim($this->baseUrl ?? (string) config('pms.channels.channex.base_url'), '/')
            .'/'.ltrim($path, '/');

        try {
            $request = Http::asJson()
                ->acceptJson()
                ->withHeaders(['user-api-key' => $this->apiKey])
                ->timeout((int) config('pms.channels.channex.timeout', 30));

            $response = $method === 'get'
                ? $request->get($url, $payload)
                : $request->post($url, $payload);
        } catch (ConnectionException) {
            throw new ChannexRequestException(
                'Channex could not be reached. The update stays queued.',
                retryable: true,
            );
        }

        return $this->interpret($response, $method, $path);
    }

    /**
     * What came back, or an exception saying why it is not usable.
     *
     * @return array<string, mixed>
     *
     * @throws ChannexRequestException
     */
    private function interpret(Response $response, string $method, string $path): array
    {
        $decoded = $response->json();
        $body = is_array($decoded) ? $decoded : [];

        if ($response->failed()) {
            $status = $response->status();

            throw new ChannexRequestException(
                sprintf(
                    'Channex answered %d on %s %s: %s',
                    $status,
                    mb_strtoupper($method),
                    $path,
                    match ($status) {
                        401 => 'the API key was rejected',
                        404 => 'the property, room type or rate plan does not exist',
                        422 => 'the request was rejected as invalid',
                        429 => 'the ARI rate limit for this property is spent',
                        500, 502, 503, 504 => 'Channex is temporarily unavailable',
                        default => $this->errorTitle($body) ?? 'the request was rejected',
                    },
                ),
                errorCode: $status,
                retryable: in_array($status, self::RETRYABLE, true),
                retryAfter: $this->retryAfter($response),
                body: $body,
            );
        }

        /*
         * The warnings in a 200 outrank the status line.
         *
         * This is the whole point of the class. A push that Channex only
         * partly accepted must not clear a dirty flag and store a content
         * hash, because the nights it refused would then look synchronised
         * forever.
         */
        $warnings = $this->warnings($body);

        if ($warnings !== []) {
            throw new ChannexRequestException(
                sprintf(
                    'Channex accepted %s %s with %d rejection(s): %s',
                    mb_strtoupper($method),
                    $path,
                    count($warnings),
                    implode('; ', array_slice($warnings, 0, 5)),
                ),
                errorCode: 422,
                // A rejection is about the content, and the same content will
                // be rejected again. Somebody has to look at it.
                retryable: false,
                warnings: $warnings,
                body: $body,
            );
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function warnings(array $body): array
    {
        $warnings = $body['meta']['warnings'] ?? null;

        if (! is_array($warnings)) {
            return [];
        }

        $flat = [];

        foreach ($warnings as $warning) {
            // Shapes vary between endpoints, so each is rendered rather than
            // assumed to be a string.
            $flat[] = is_string($warning)
                ? $warning
                : (string) json_encode($warning, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return array_values(array_filter($flat, fn (string $w): bool => $w !== '' && $w !== 'null'));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function errorTitle(array $body): ?string
    {
        $title = $body['errors']['title'] ?? null;

        return is_string($title) && $title !== '' ? $title : null;
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(1, (int) $header) : null;
    }
}
