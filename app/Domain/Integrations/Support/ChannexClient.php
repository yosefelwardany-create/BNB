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

    /**
     * The largest page Channex will serve. It answers 422 above this
     * ("must be less than or equal to 100"), checked against staging.
     */
    private const PAGE_LIMIT = 100;

    /**
     * How many pages {@see all()} will walk before it stops.
     *
     * Ten thousand records is far past any real catalogue. The cap exists so
     * that a `meta` block that never reports the end cannot hold a worker in
     * a loop.
     */
    private const MAX_PAGES = 100;

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
     * Every record in a collection, across as many pages as it takes.
     *
     * Ordered by id, because Channex otherwise orders by title and titles
     * repeat — the staging demo has three rate plans all called "Demo Rate
     * Plan" — so a page boundary between equal titles could show one record
     * twice and another never.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>> flattened as {@see records()} does
     *
     * @throws ChannexRequestException
     */
    public function all(string $path, array $query = []): array
    {
        $records = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $body = $this->get($path, $query + [
                'order' => ['id' => 'asc'],
                'pagination' => ['page' => $page, 'limit' => self::PAGE_LIMIT],
            ]);

            $rows = self::records($body);
            array_push($records, ...$rows);

            $total = $body['meta']['total'] ?? null;

            if (count($rows) < self::PAGE_LIMIT || (is_int($total) && count($records) >= $total)) {
                break;
            }
        }

        return $records;
    }

    /**
     * Channex's `{type, id, attributes, relationships}` records, flattened.
     *
     * The parents are the part that matters. A room type's property and a
     * rate plan's room type are not attributes: a real account returns them
     * only as `relationships.property.data.id` and
     * `relationships.room_type.data.id`. Each single-record relationship is
     * therefore lifted out as `<name>_id`, so a reader asks for `property_id`
     * without knowing where Channex keeps it. An attribute of the same name
     * wins, should Channex ever send both.
     *
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    public static function records(array $body): array
    {
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $rows = [];

        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }

            $attributes = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];

            $rows[] = $attributes
                + array_intersect_key($row, array_flip(['id', 'type']))
                + self::parents($row);
        }

        return $rows;
    }

    /**
     * `<name>_id` for each relationship that points at exactly one record.
     *
     * To-many relationships (a property's users, a room type's facilities)
     * are lists and are left alone; nothing here addresses a write by them.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private static function parents(array $row): array
    {
        $relationships = is_array($row['relationships'] ?? null) ? $row['relationships'] : [];
        $parents = [];

        foreach ($relationships as $name => $relationship) {
            $id = is_array($relationship) && is_array($relationship['data'] ?? null)
                ? ($relationship['data']['id'] ?? null)
                : null;

            if (is_string($name) && is_string($id) && $id !== '') {
                $parents[$name.'_id'] = $id;
            }
        }

        return $parents;
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

            $details = $this->errorDetails($body);

            throw new ChannexRequestException(
                sprintf(
                    'Channex answered %d on %s %s: %s%s',
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
                    $details === [] ? '' : ' ('.implode('; ', array_slice($details, 0, 5)).')',
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
            $flat[] = self::describeWarning($warning);
        }

        return array_values(array_filter($flat, fn (string $w): bool => $w !== '' && $w !== 'null'));
    }

    /**
     * One rejection from a 200's `meta.warnings`, as a person would say it.
     *
     * Channex echoes the whole entry it refused — every id, every field,
     * mostly nulls — with the reasons under `warning`. A real one from
     * staging is four hundred characters of JSON for "min_stay is not
     * supported on this property". What an operator needs is the night and
     * the reason, so that is what this keeps; the raw entry stays in the
     * exception's body for anyone who wants it.
     */
    public static function describeWarning(mixed $warning): string
    {
        if (is_string($warning)) {
            return $warning;
        }

        $reasons = is_array($warning) && is_array($warning['warning'] ?? null) ? $warning['warning'] : null;

        if ($reasons === null) {
            // Shapes vary between endpoints, so an unfamiliar one is rendered
            // whole rather than assumed to be something it is not.
            return (string) json_encode($warning, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $parts = [];

        foreach ($reasons as $field => $messages) {
            foreach ((array) $messages as $message) {
                if (is_scalar($message)) {
                    $parts[] = trim($field.' '.$message);
                }
            }
        }

        $when = match (true) {
            is_string($warning['date'] ?? null) => $warning['date'],
            is_string($warning['date_from'] ?? null) => $warning['date_from'].' to '.($warning['date_to'] ?? '?'),
            default => null,
        };

        return ($when === null ? '' : $when.': ').implode('; ', $parts);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function errorTitle(array $body): ?string
    {
        $title = $body['errors']['title'] ?? null;

        return is_string($title) && $title !== '' ? $title : null;
    }

    /**
     * What Channex said was wrong, as `field.path: rule` entries.
     *
     * A rejected request carries `errors.details` nested the way the request
     * was — staging answers an oversized page with
     * `{"pagination": {"limit": ["must be less than or equal to 100"]}}` — and
     * without it the message says "invalid" and nothing about what.
     *
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function errorDetails(array $body): array
    {
        $details = $body['errors']['details'] ?? null;

        if (is_string($details) && $details !== '') {
            return [$details];
        }

        if (! is_array($details)) {
            return [];
        }

        $lines = [];
        $walk = function (array $node, string $path) use (&$walk, &$lines): void {
            foreach ($node as $key => $value) {
                $here = is_int($key) ? $path : ltrim($path.'.'.$key, '.');

                if (is_array($value)) {
                    $walk($value, $here);
                } elseif (is_scalar($value) && (string) $value !== '') {
                    $lines[] = $here === '' ? (string) $value : $here.': '.$value;
                }
            }
        };

        $walk($details, '');

        return $lines;
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(1, (int) $header) : null;
    }
}
