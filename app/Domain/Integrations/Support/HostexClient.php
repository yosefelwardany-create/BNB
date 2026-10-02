<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

use App\Domain\Integrations\Exceptions\HostexRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * The HTTP half of the Hostex integration.
 *
 * Separated from the adapter so that the two things most likely to be wrong —
 * how a request is authenticated, and how an error is recognised — live in one
 * small file with their own tests, rather than being repeated at a dozen call
 * sites.
 *
 * ## The error-handling rule, which is not the usual one
 *
 * Hostex answers **HTTP 200 with `error_code` in the body** for conditions other
 * APIs express in the status line — rate limiting among them. A client written
 * the normal way sees `200`, calls it a success and carries on with an empty
 * result. For an availability pull that is a quiet wrong number; for a guest
 * message it is a reply that silently never sent.
 *
 * So success here is not "2xx". It is "2xx **and** the body does not carry an
 * error code", and everything else raises. That single rule is the reason this
 * class exists.
 */
class HostexClient
{
    /**
     * Error codes worth trying again.
     *
     * 429 is the rate limit, which the account shares across every token it has
     * issued; the others are the ordinary transient server failures. Everything
     * else — a bad token, an unknown listing, a rejected date — will fail again
     * in exactly the same way, and retrying it only delays telling somebody.
     *
     * @var list<int>
     */
    private const RETRYABLE = [429, 500, 502, 503, 504];

    public function __construct(
        private readonly string $accessToken,
        private readonly ?string $baseUrl = null,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws HostexRequestException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->read('get', $path, $query);
    }

    /** Hostex documents POST /listings/calendar as a read-only query. */
    public function queryCalendar(array $query): array
    {
        return $this->read('post', 'listings/calendar', $query);
    }

    private function read(string $method, string $path, array $payload): array
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->send($method, $path, $payload);
            } catch (HostexRequestException $e) {
                $delay = $e->retryAfter ?? (1 << $attempt);
                // Long provider cooldowns are reported, not ignored or shortened.
                if (! $e->retryable || $attempt >= 2 || $delay > 5) {
                    throw $e;
                }
                Sleep::for($delay)->seconds();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws HostexRequestException
     */
    public function post(string $path, array $body = []): array
    {
        return $this->send('post', $path, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws HostexRequestException
     */
    public function delete(string $path, array $body = []): array
    {
        return $this->send('delete', $path, $body);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws HostexRequestException
     */
    private function send(string $method, string $path, array $payload): array
    {
        $url = rtrim($this->baseUrl ?? (string) config('pms.channels.hostex.base_url'), '/')
            .'/'.ltrim($path, '/');

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->withHeaders(['Hostex-Access-Token' => $this->accessToken])
                ->timeout((int) config('pms.channels.hostex.timeout', 20))
                ->{$method}($url, $payload);
        } catch (ConnectionException $e) {
            throw new HostexRequestException(
                'Hostex could not be reached. Retry the inbound pull.',
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
     * @throws HostexRequestException
     */
    private function interpret(Response $response, string $method, string $path): array
    {
        $decoded = $response->json();
        $body = is_array($decoded) ? $decoded : [];

        /*
         * The error code in the body outranks the status line.
         *
         * This is the whole point of the class. A 200 carrying `error_code: 429`
         * is a rate limit, not a success, and treating it as one is how an
         * integration loses a guest's reply without anybody noticing.
         */
        $code = $this->errorCode($body);

        if ($code !== null) {
            throw new HostexRequestException(
                sprintf(
                    'Hostex answered %d on %s %s: %s',
                    $code,
                    mb_strtoupper($method),
                    $path,
                    match ($code) {
                        429 => 'Too many requests', 401 => 'Invalid token', 403 => 'Access denied', default => 'Request rejected'
                    },
                ),
                errorCode: $code,
                retryable: in_array($code, self::RETRYABLE, true),
                // Seconds, when the rate limiter said so. Honoured by the
                // caller rather than slept on here: a worker that sleeps holds
                // a queue slot doing nothing.
                retryAfter: $this->retryAfter($response),
                body: ['error_code' => $code],
            );
        }

        if ($response->failed()) {
            throw new HostexRequestException(
                sprintf(
                    'Hostex answered %d on %s %s. %s',
                    $response->status(),
                    mb_strtoupper($method),
                    $path,
                    'Check connection permissions and retry.',
                ),
                errorCode: $response->status(),
                retryable: in_array($response->status(), self::RETRYABLE, true),
                retryAfter: $this->retryAfter($response),
                body: ['error_code' => $response->status()],
            );
        }

        /*
         * The payload, unwrapped one level where there is a wrapper.
         *
         * Hostex nests the useful part under `data` on most endpoints. Rather
         * than assume, this unwraps when the key is there and hands the whole
         * body back when it is not, so an endpoint shaped differently is
         * readable rather than empty.
         */
        if (! is_array($decoded)) {
            throw new HostexRequestException('Hostex returned an unreadable response.', retryable: true);
        }

        return is_array($body['data'] ?? null) ? $body['data'] : $body;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    /**
     * The error code in the body, or null when the body reports success.
     *
     * Hostex puts a code in every response, successful ones included, so "a code
     * is present" cannot mean "something went wrong". Two values mean fine:
     *
     *  - **0**, which is how several APIs spell "no error".
     *  - **Any 2xx**, because Hostex mirrors the HTTP status into the body. A
     *    real connection answers `{"error_code": 200, "error_msg": "Done"}` on a
     *    successful `GET /properties`.
     *
     * The second one was found against a live account after this client rejected
     * a perfectly good token, reporting "Hostex answered 200 on GET properties:
     * Done." — a sentence that is its own bug report. It is why
     * `php artisan hostex:probe` exists: the field shapes here were written
     * defensively from documentation this container cannot reach, and the real
     * API is the only thing that settles them.
     */
    private function errorCode(array $body): ?int
    {
        $code = $body['error_code'] ?? $body['errorCode'] ?? null;

        if (! is_numeric($code)) {
            return null;
        }

        $code = (int) $code;

        return $code === 0 || ($code >= 200 && $code < 300) ? null : $code;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function errorMessage(array $body): ?string
    {
        foreach (['error_msg', 'error_message', 'message', 'msg'] as $key) {
            if (is_string($body[$key] ?? null) && trim($body[$key]) !== '') {
                return trim($body[$key]);
            }
        }

        return null;
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(1, (int) $header) : null;
    }
}
