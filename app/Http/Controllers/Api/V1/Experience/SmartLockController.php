<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Experience;

use App\Domain\Integrations\Registries\LockProviderRegistry;
use App\Domain\Locks\Models\AccessCode;
use App\Domain\Locks\Models\SmartLock;
use App\Domain\Locks\Services\AccessCodeManager;
use App\Domain\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccessCodeResource;
use App\Http\Resources\SmartLockResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Doors and the codes issued to them.
 *
 * Every response here reports whether the connection is real. A code that
 * exists only in this database — because no live provider is configured, or
 * because the lock refused it — must never read as issued: sending a guest a
 * number that no door recognises produces somebody standing outside at
 * midnight, and it is the worst failure this module can have.
 *
 * The code itself is returned only where somebody is entitled to it, and
 * `locks.manage` is what "entitled" means. Listing codes shows the last four
 * digits, which is enough to identify one and useless for opening anything.
 */
class SmartLockController extends Controller
{
    public function __construct(
        private readonly AccessCodeManager $codes,
        private readonly LockProviderRegistry $providers,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SmartLock::class);

        $query = SmartLock::query()->with('property');

        if ($request->filled('property_id')) {
            $query->where('property_id', $request->string('property_id')->toString());
        }

        if ($request->boolean('active_only')) {
            $query->active();
        }

        // The queue that matters most: a flat battery is a guest who cannot
        // get in, and it is entirely preventable given a day's notice.
        if ($request->boolean('low_battery')) {
            $query->lowBattery();
        }

        return SmartLockResource::collection(
            $query->orderBy('name')->paginate($this->perPage()),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', SmartLock::class);

        $data = $request->validate([
            'property_id' => ['required', 'string', 'size:26'],
            'unit_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['required', 'string', 'max:48'],
            'connection_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'external_lock_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'location' => ['sometimes', 'string', 'max:64'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ]);

        abort_unless(
            $this->providers->has($data['provider']),
            422,
            sprintf('No lock provider is registered under "%s".', $data['provider']),
        );

        $lock = new SmartLock;
        $lock->fill($data);
        $lock->organization_id = $this->organization()->getKey();
        // Taken from the adapter rather than accepted from the caller: whether
        // this connection reaches a real lock is not a matter of opinion.
        $lock->is_simulated = ! $this->providers->make($data['provider'])->isLive();
        $lock->save();

        return (new SmartLockResource($lock))->response()->setStatusCode(201);
    }

    public function show(SmartLock $lock): SmartLockResource
    {
        $this->authorize('view', $lock);

        return new SmartLockResource($lock->load('property'));
    }

    public function update(Request $request, SmartLock $lock): SmartLockResource
    {
        $this->authorize('update', $lock);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'location' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', 'in:active,offline,inactive'],
            'unit_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ]);

        $lock->fill($data)->save();

        return new SmartLockResource($lock->fresh());
    }

    /**
     * Ask the lock how it is.
     *
     * Refreshes battery and reachability from the provider rather than from
     * our own last guess, because the answer to "will this open tomorrow"
     * depends on the door, not on our records.
     */
    public function status(SmartLock $lock): JsonResponse
    {
        $this->authorize('view', $lock);

        $provider = $this->providers->make($lock->provider);
        $status = $provider->status($lock);

        $lock->forceFill([
            'status' => $status->online ? SmartLock::ACTIVE : SmartLock::OFFLINE,
            'battery_percent' => $status->batteryPercent,
            'last_seen_at' => $status->lastSeenAt ?? $lock->last_seen_at,
            'is_simulated' => ! $provider->isLive(),
        ])->save();

        return response()->json([
            'data' => [
                'online' => $status->online,
                'locked' => $status->locked,
                'battery_percent' => $status->batteryPercent,
                'last_seen_at' => $status->lastSeenAt?->format(DATE_ATOM),
                // Never inferred by the client: a green tick from a simulated
                // provider says nothing about a real door.
                'is_simulated' => ! $provider->isLive(),
                'lock' => new SmartLockResource($lock->fresh()),
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // Codes
    // ------------------------------------------------------------------

    public function codes(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SmartLock::class);

        $query = AccessCode::query()->with('lock');

        if ($request->filled('reservation_id')) {
            $query->where('reservation_id', $request->string('reservation_id')->toString());
        }

        if ($request->filled('smart_lock_id')) {
            $query->where('smart_lock_id', $request->string('smart_lock_id')->toString());
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }

        if ($request->boolean('usable_only')) {
            $query->usable();
        }

        return AccessCodeResource::collection(
            $query->orderByDesc('valid_from')->paginate($this->perPage()),
        );
    }

    /**
     * Issue codes for a booking, across every lock it needs.
     */
    public function issueForReservation(Reservation $reservation): JsonResponse
    {
        $this->authorize('locks.manage');

        $codes = $this->codes->issueForReservation($reservation);

        $failed = array_filter(
            $codes,
            fn (AccessCode $code): bool => $code->status === AccessCode::FAILED,
        );

        return response()->json([
            'data' => AccessCodeResource::collection($codes)->resolve(),
            'meta' => [
                'issued' => count($codes) - count($failed),
                // Reported at the top rather than buried in a row, because a
                // guest with one working code out of two is a guest who cannot
                // get through the front door.
                'failed' => count($failed),
                'simulated' => count(array_filter(
                    $codes,
                    fn (AccessCode $code): bool => (bool) $code->is_simulated,
                )),
            ],
        ], 201);
    }

    /**
     * Issue one code by hand — a contractor, an owner visit.
     */
    public function issue(Request $request, SmartLock $lock): JsonResponse
    {
        $this->authorize('operate', $lock);

        $data = $request->validate([
            'valid_from' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after:valid_from'],
            'purpose' => ['sometimes', 'in:guest,cleaner,maintenance,owner,master'],
        ]);

        $code = $this->codes->issue(
            $lock,
            [CarbonImmutable::parse($data['valid_from']), CarbonImmutable::parse($data['valid_until'])],
            $data['purpose'] ?? AccessCode::CLEANER,
        );

        abort_if($code === null, 422, 'The code could not be issued.');

        // The plain code is returned here and only here: this caller holds
        // locks.manage and has just asked for it. Listings show four digits.
        return response()->json([
            'data' => (new AccessCodeResource($code))->withCode()->resolve(),
        ], $code->status === AccessCode::FAILED ? 422 : 201);
    }

    /**
     * Withdraw a code.
     */
    public function revoke(Request $request, AccessCode $code): AccessCodeResource
    {
        $this->authorize('operate', $code->lock);

        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:255']]);

        return new AccessCodeResource($this->codes->revoke($code, $data['reason'] ?? null));
    }

    /**
     * Reveal a code to somebody entitled to see it.
     *
     * Separate from the listing on purpose: a list of working door codes is a
     * set of keys, and the ordinary case — identifying which code is which —
     * needs only the last four digits.
     */
    public function reveal(AccessCode $code): JsonResponse
    {
        $this->authorize('operate', $code->lock);

        return response()->json([
            'data' => (new AccessCodeResource($code))->withCode()->resolve(),
        ]);
    }
}
