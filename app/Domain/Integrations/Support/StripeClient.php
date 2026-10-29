<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

use App\Domain\Integrations\Exceptions\StripeRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The HTTP half of the Stripe integration.
 *
 * Stripe's API is form-encoded, not JSON, with nested fields written as
 * `metadata[checkout_id]`; every write carries an idempotency key so a retry
 * after a timeout cannot charge a guest twice; and the API version is pinned
 * here, so a change to the account's default version in Stripe's dashboard
 * cannot change what Habitat receives.
 *
 * A webhook is Stripe's when its `Stripe-Signature` header holds an HMAC of
 * `{timestamp}.{payload}` under the endpoint's secret, made within the last
 * five minutes; {@see verifySignature()}.
 */
class StripeClient
{
    /** The Stripe API version every request is made against. */
    public const API_VERSION = '2024-06-20';

    /** How old a webhook signature may be, in seconds. */
    public const SIGNATURE_TOLERANCE = 300;

    /**
     * Statuses worth trying again: the rate limit, a conflict on a key still
     * in flight, and Stripe's own faults. A decline or a bad request fails the
     * same way every time.
     *
     * @var list<int>
     */
    private const RETRYABLE = [409, 429, 500, 502, 503, 504];

    public function __construct(
        private readonly string $secretKey,
        private readonly ?string $baseUrl = null,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws StripeRequestException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query, null);
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     *
     * @throws StripeRequestException
     */
    public function post(string $path, array $form = [], ?string $idempotencyKey = null): array
    {
        return $this->send('post', $path, $form, $idempotencyKey);
    }

    /**
     * Whether a webhook body was signed by Stripe with $secret, recently.
     *
     * Every `v1` signature in the header is tried: while a secret is being
     * rolled Stripe signs with the old and the new one.
     */
    public static function verifySignature(string $payload, string $header, string $secret, int $tolerance = self::SIGNATURE_TOLERANCE): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === [] || abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws StripeRequestException
     */
    private function send(string $method, string $path, array $payload, ?string $idempotencyKey): array
    {
        // A deployment with outbound side effects switched off — a test
        // environment, a staging copy holding a live key by mistake — refuses
        // every write here, the one place they all pass through.
        if ($method !== 'get' && ! config('pms.outbound.enabled', true)) {
            throw new StripeRequestException(
                'Outbound Stripe changes are disabled in this environment (OUTBOUND_INTEGRATIONS_ENABLED=false).',
            );
        }

        $url = rtrim($this->baseUrl ?? 'https://api.stripe.com', '/').'/'.ltrim($path, '/');

        $headers = ['Stripe-Version' => self::API_VERSION];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        try {
            $request = Http::withToken($this->secretKey)
                ->acceptJson()
                ->withHeaders($headers)
                ->timeout(30);

            $response = $method === 'get'
                ? $request->get($url, $payload)
                : $request->asForm()->post($url, $payload);
        } catch (ConnectionException) {
            throw new StripeRequestException('Stripe could not be reached.', retryable: true);
        }

        return $this->interpret($response);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws StripeRequestException
     */
    private function interpret(Response $response): array
    {
        $decoded = $response->json();
        $body = is_array($decoded) ? $decoded : [];

        if ($response->successful()) {
            return $body;
        }

        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $status = $response->status();
        // Stripe says outright when a retry can work; otherwise the status decides.
        $shouldRetry = $response->header('Stripe-Should-Retry');

        throw new StripeRequestException(
            is_string($error['message'] ?? null) && $error['message'] !== ''
                ? $error['message']
                : sprintf('Stripe answered %d.', $status),
            status: $status,
            errorCode: is_string($error['code'] ?? null) ? $error['code'] : null,
            declineCode: is_string($error['decline_code'] ?? null) ? $error['decline_code'] : null,
            errorType: is_string($error['type'] ?? null) ? $error['type'] : null,
            retryable: $shouldRetry !== '' ? $shouldRetry === 'true' : in_array($status, self::RETRYABLE, true),
            body: $body,
        );
    }
}
