<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Webhooks\Models\WebhookDelivery;
use App\Domain\Webhooks\Models\WebhookEndpoint;
use App\Domain\Webhooks\Services\WebhookDispatcher;
use App\Http\Controllers\Controller;
use App\Http\Resources\WebhookDeliveryResource;
use App\Http\Resources\WebhookEndpointResource;
use App\Support\Validation\PublicHttpsUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Where the platform sends domain events.
 *
 * The signing secret is shown exactly once, in the response to the request
 * that created or rotated it. It is encrypted at rest and never returned
 * again: a secret that can be read back from an API is a secret that somebody
 * else can read back too.
 *
 * Endpoints are disabled rather than deleted, and their delivery history
 * survives. "It didn't arrive" is the most common integration complaint there
 * is, and the only useful answer is the response the receiver actually gave —
 * which is gone if the endpoint was removed.
 */
class WebhookEndpointController extends Controller
{
    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WebhookEndpoint::class);

        $query = WebhookEndpoint::query()->withCount('deliveries');

        if ($request->boolean('active_only')) {
            $query->active();
        }

        if ($request->filled('event')) {
            $query->subscribedTo($request->string('event')->toString());
        }

        return WebhookEndpointResource::collection(
            $query->orderBy('name')->paginate($this->perPage()),
        );
    }

    /**
     * Register an endpoint.
     *
     * The response carries the signing secret. This is the only time it is
     * ever returned.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', WebhookEndpoint::class);

        $data = $request->validate($this->rules());

        $endpoint = new WebhookEndpoint;
        $endpoint->fill(collect($data)->except('headers')->all());
        $endpoint->organization_id = $this->organization()->getKey();
        $endpoint->created_by_id = auth()->id();

        if (isset($data['headers'])) {
            $endpoint->headers = $data['headers'];
        }

        $endpoint->save();

        return (new WebhookEndpointResource($endpoint))
            ->additional([
                'meta' => [
                    'signing_secret' => $endpoint->signing_secret,
                    'signing_notice' => 'Store this now. It is never shown again, and it cannot be recovered — only rotated.',
                ],
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function show(WebhookEndpoint $endpoint): WebhookEndpointResource
    {
        $this->authorize('view', $endpoint);

        return new WebhookEndpointResource($endpoint->loadCount('deliveries'));
    }

    public function update(Request $request, WebhookEndpoint $endpoint): WebhookEndpointResource
    {
        $this->authorize('update', $endpoint);

        $data = $request->validate($this->rules($endpoint));

        $endpoint->fill(collect($data)->except('headers')->all());

        if (array_key_exists('headers', $data)) {
            $endpoint->headers = $data['headers'];
        }

        // Re-enabling a disabled endpoint clears the failure count. Otherwise
        // an endpoint that was switched off after twenty failures would be
        // switched off again by its twenty-first.
        if (($data['status'] ?? null) === WebhookEndpoint::ACTIVE) {
            $endpoint->consecutive_failures = 0;
            $endpoint->last_error = null;
        }

        $endpoint->save();

        return new WebhookEndpointResource($endpoint->fresh());
    }

    /**
     * Issue a new signing secret.
     *
     * The old one stops working immediately. There is deliberately no overlap
     * period: a rotation is usually a response to a suspected leak, and a
     * window in which the leaked secret still verifies would defeat the point.
     */
    public function rotateSecret(WebhookEndpoint $endpoint): JsonResponse
    {
        $this->authorize('update', $endpoint);

        $endpoint->signing_secret = WebhookEndpoint::freshSecret();
        $endpoint->save();

        return response()->json([
            'data' => new WebhookEndpointResource($endpoint->fresh()),
            'meta' => [
                'signing_secret' => $endpoint->signing_secret,
                'signing_notice' => 'The previous secret stopped working immediately. Store this now; it is never shown again.',
            ],
        ]);
    }

    /**
     * Send a test event.
     *
     * Bypasses the subscription list on purpose: the point is to prove the
     * endpoint works before deciding what it should receive.
     */
    public function test(WebhookEndpoint $endpoint): JsonResponse
    {
        $this->authorize('update', $endpoint);

        $delivery = $this->dispatcher->test($endpoint);

        return response()->json([
            'data' => new WebhookDeliveryResource($delivery),
            'meta' => [
                'delivered' => $delivery->succeeded(),
            ],
        ]);
    }

    /**
     * Every attempt made against this endpoint.
     */
    public function deliveries(Request $request, WebhookEndpoint $endpoint): AnonymousResourceCollection
    {
        $this->authorize('view', $endpoint);

        $query = $endpoint->deliveries()->getQuery();

        if ($request->filled('event')) {
            $query->where('event_name', $request->string('event')->toString());
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }

        if ($request->boolean('failed_only')) {
            $query->failed();
        }

        return WebhookDeliveryResource::collection(
            $query->latest()->paginate($this->perPage()),
        );
    }

    /**
     * Try a failed delivery again, now.
     *
     * A new attempt row rather than a rewrite of the failed one: the failure
     * is evidence, and overwriting it would erase the response that explains
     * what went wrong.
     */
    public function redeliver(WebhookDelivery $delivery): JsonResponse
    {
        $this->authorize('update', $delivery->endpoint ?? WebhookEndpoint::class);

        $replay = WebhookDelivery::query()->create([
            'organization_id' => $delivery->organization_id,
            'webhook_endpoint_id' => $delivery->webhook_endpoint_id,
            // Deliberately not carried over: the unique index is on
            // (endpoint, event, attempt), and a manual replay is a new
            // sequence rather than a continuation of the old one.
            'domain_event_id' => null,
            'event_name' => $delivery->event_name,
            'payload' => $delivery->payload,
            'attempt' => 1,
        ]);

        return response()->json([
            'data' => new WebhookDeliveryResource($this->dispatcher->send($replay)),
        ]);
    }

    /**
     * Stop sending to an endpoint.
     *
     * Disabled, not deleted: its delivery history is the record of what this
     * platform told an integrator and when.
     */
    public function destroy(WebhookEndpoint $endpoint): JsonResponse
    {
        $this->authorize('delete', $endpoint);

        $endpoint->forceFill(['status' => WebhookEndpoint::DISABLED])->save();

        return response()->json([
            'message' => 'The endpoint has been disabled. Its delivery history is unchanged.',
            'data' => new WebhookEndpointResource($endpoint->fresh()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?WebhookEndpoint $endpoint = null): array
    {
        $creating = $endpoint === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            // https only, and no address inside the infrastructure: a webhook
            // URL is supplied by a user and fetched by our server, which is
            // exactly the shape of a server-side request forgery. The rule
            // relaxes only where a test or local environment asks it to.
            'url' => [
                $creating ? 'required' : 'sometimes',
                'url',
                'max:500',
                new PublicHttpsUrl(allowLocal: (bool) config('pms.webhooks.allow_local_endpoints', false)),
            ],

            'events' => ['sometimes', 'nullable', 'array'],
            'events.*' => ['string', 'max:128'],

            'status' => ['sometimes', Rule::in([
                WebhookEndpoint::ACTIVE,
                WebhookEndpoint::PAUSED,
                WebhookEndpoint::DISABLED,
            ])],

            'headers' => ['sometimes', 'nullable', 'array'],
            'headers.*' => ['string', 'max:500'],

            'failure_threshold' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'max_attempts' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }
}
