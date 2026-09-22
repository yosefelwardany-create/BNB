<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Services\ChannelSynchroniser;
use App\Domain\Integrations\Registries\ChannelAdapterRegistry;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelAccountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Connections to booking channels.
 *
 * Credentials go in and never come back out: they are encrypted at rest,
 * hidden on the model, and reported by the API only as "present" or "absent".
 * A settings screen needs to know whether a connection is configured, not what
 * it was configured with.
 *
 * The `available` endpoint is where this platform is honest about what it can
 * actually do. Every major OTA requires a commercial partner agreement before
 * its API can be used, so until credentials exist those channels are served by
 * a simulated adapter — and that endpoint says so, per channel, in a field the
 * interface is expected to show.
 */
class ChannelAccountController extends Controller
{
    public function __construct(
        private readonly ChannelSynchroniser $sync,
        private readonly ChannelAdapterRegistry $adapters,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ChannelAccount::class);

        $query = ChannelAccount::query()->withCount('listings');

        if ($request->filled('channel')) {
            $query->forChannel($request->string('channel')->toString());
        }

        if ($request->boolean('connected_only')) {
            $query->connected();
        }

        return ChannelAccountResource::collection(
            $query->orderBy('channel')->paginate($this->perPage()),
        );
    }

    /**
     * What the platform can connect to, and which of them are real.
     *
     * Deliberately not filtered down to the live ones. An operator choosing a
     * channel is entitled to know that connecting Airbnb here exercises the
     * whole synchronisation path against a local simulation rather than
     * against Airbnb.
     */
    public function available(): JsonResponse
    {
        $this->authorize('viewAny', ChannelAccount::class);

        $channels = [];

        foreach (ChannelAdapterRegistry::KNOWN_CHANNELS as $key => $label) {
            $adapter = $this->adapters->make($key);

            $channels[] = [
                'channel' => $key,
                'name' => $label,
                'is_live' => $adapter->isLive(),
                // Present exactly when the adapter is not live, and phrased for
                // a human: "no partner agreement", not "not implemented".
                'simulation_reason' => $adapter->isLive() ? null : $adapter->simulationReason(),
                'capabilities' => $adapter->capabilities(),
                'connected' => ChannelAccount::query()->forChannel($key)->connected()->exists(),
            ];
        }

        return response()->json(['data' => $channels]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ChannelAccount::class);

        $data = $request->validate($this->rules());

        $account = new ChannelAccount;
        $account->fill(collect($data)->except('credentials')->all());
        $account->organization_id = $this->organization()->getKey();
        $account->created_by_id = auth()->id();

        if (isset($data['credentials'])) {
            $account->credentials = $data['credentials'];
        }

        $account->save();

        // Verify immediately rather than leaving the operator to wonder. A
        // connection that cannot be verified is marked as errored here, which
        // is far better than discovering it when the first booking fails to
        // import.
        $this->sync->verify($account);

        return (new ChannelAccountResource($account->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ChannelAccount $account): ChannelAccountResource
    {
        $this->authorize('view', $account);

        return new ChannelAccountResource($account->loadCount('listings'));
    }

    public function update(Request $request, ChannelAccount $account): ChannelAccountResource
    {
        $this->authorize('update', $account);

        $data = $request->validate($this->rules($account));

        $account->fill(collect($data)->except('credentials')->all());

        // Credentials are replaced only when supplied. An omitted field means
        // "leave them alone", never "clear them" — otherwise editing a display
        // name would silently disconnect the channel.
        if (isset($data['credentials'])) {
            $account->credentials = $data['credentials'];
        }

        $account->save();

        return new ChannelAccountResource($account->fresh());
    }

    /**
     * Ask the channel whether the credentials still work.
     */
    public function verify(ChannelAccount $account): JsonResponse
    {
        $this->authorize('verify', $account);

        $result = $this->sync->verify($account);
        $adapter = $this->adapters->make($account->channel);

        return response()->json([
            'data' => [
                'successful' => $result->successful,
                'message' => $result->errorMessage ?? 'The connection is working.',
                // Whether the answer came from the channel or from a local
                // simulation. Reported explicitly so a green tick can never be
                // read as "we reached Airbnb" when we did not.
                'is_simulated' => ! $adapter->isLive(),
                'simulation_reason' => $adapter->isLive() ? null : $adapter->simulationReason(),
                'account' => new ChannelAccountResource($account->fresh()),
            ],
        ]);
    }

    /**
     * Push everything outstanding on this connection now.
     */
    public function push(ChannelAccount $account): JsonResponse
    {
        $this->authorize('sync', $account);

        return response()->json(['data' => $this->sync->pushPending($account)]);
    }

    /**
     * Stop trading on a channel.
     *
     * Marked disconnected, never deleted. The account is referenced by every
     * booking that came through it and every mapping under it; removing it
     * would orphan real reservations to save a row.
     */
    public function disconnect(ChannelAccount $account): JsonResponse
    {
        $this->authorize('disconnect', $account);

        $account->forceFill([
            'status' => ChannelAccount::STATUS_DISCONNECTED,
            'last_error' => null,
        ])->save();

        // The mappings stop syncing with it. They too are kept, so the history
        // of what was published where survives.
        $account->listings()->update(['is_active' => false]);

        return response()->json([
            'message' => 'The channel has been disconnected. Its bookings and mappings are unchanged.',
            'data' => new ChannelAccountResource($account->fresh()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?ChannelAccount $account = null): array
    {
        $creating = $account === null;

        return [
            'channel' => [
                $creating ? 'required' : 'sometimes',
                'string',
                Rule::in(array_keys(ChannelAdapterRegistry::KNOWN_CHANNELS)),
            ],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],

            // Write-only. Never returned by any endpoint on this controller.
            'credentials' => ['sometimes', 'array'],

            'external_account_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'sync_availability' => ['sometimes', 'boolean'],
            'sync_rates' => ['sometimes', 'boolean'],
            'import_reservations' => ['sometimes', 'boolean'],
            'export_reservations' => ['sometimes', 'boolean'],
            'sync_messages' => ['sometimes', 'boolean'],
            // Basis points, not a percentage: 1500 is 15%. Integer arithmetic
            // all the way down, so a commission can never drift by a rounding.
            'commission_basis_points' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'collects_payment' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
