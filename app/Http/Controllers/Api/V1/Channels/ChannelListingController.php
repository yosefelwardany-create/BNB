<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelSynchroniser;
use App\Domain\Listings\Models\Listing;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelListingResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The mapping between one of our listings and one of theirs.
 *
 * A wrong mapping is the most expensive mistake in distribution: it points a
 * channel's calendar at the wrong apartment, and every booking that follows is
 * sold against inventory that does not exist. So a mapping is unique on both
 * sides — one channel listing maps to exactly one of ours — and the uniqueness
 * is enforced by the database, not by a check that a concurrent request could
 * slip past.
 */
class ChannelListingController extends Controller
{
    public function __construct(private readonly ChannelSynchroniser $sync) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ChannelListing::class);

        $query = ChannelListing::query()->with(['account', 'listing']);

        if ($request->filled('channel_account_id')) {
            $query->where('channel_account_id', $request->string('channel_account_id')->toString());
        }

        if ($request->filled('listing_id')) {
            $query->where('listing_id', $request->string('listing_id')->toString());
        }

        if ($request->filled('property_id')) {
            $query->where('property_id', $request->string('property_id')->toString());
        }

        // The two operational queues: what is behind, and what keeps failing.
        if ($request->boolean('dirty_only')) {
            $query->dirty();
        }

        if ($request->boolean('failing_only')) {
            $query->failing();
        }

        return ChannelListingResource::collection(
            $query->orderByDesc('updated_at')->paginate($this->perPage()),
        );
    }

    public function show(ChannelListing $mapping): ChannelListingResource
    {
        $this->authorize('view', $mapping);

        return new ChannelListingResource($mapping->load(['account', 'listing']));
    }

    /**
     * Map one of our listings to one of theirs.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ChannelListing::class);

        $data = $request->validate([
            'channel_account_id' => ['required', 'string', 'size:26'],
            'listing_id' => ['required', 'string', 'size:26'],
            'external_listing_id' => ['required', 'string', 'max:191'],
            'external_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'external_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            // A channel-specific uplift or discount on our rates, in basis
            // points. Signed, because a channel can be cheaper as well as
            // dearer.
            'rate_adjustment_basis_points' => ['sometimes', 'integer', 'min:-10000', 'max:10000'],
            'commission_basis_points' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'status' => ['sometimes', 'string', Rule::in([
                ChannelListing::STATUS_MAPPED,
                ChannelListing::STATUS_PUBLISHED,
            ])],
        ]);

        $account = ChannelAccount::query()->findOrFail($data['channel_account_id']);
        $listing = Listing::query()->findOrFail($data['listing_id']);

        $mapping = new ChannelListing;
        $mapping->fill($data);
        $mapping->organization_id = $this->organization()->getKey();
        $mapping->property_id = $listing->property_id;
        $mapping->unit_type_id = $listing->unit_type_id;
        $mapping->status ??= ChannelListing::STATUS_MAPPED;
        $mapping->save();

        // A brand new mapping knows nothing about our calendar, so everything
        // is outstanding. Marked rather than pushed: the scheduler drains it,
        // and a slow channel must never hold up an operator's request.
        $mapping->markDirty('both');

        return (new ChannelListingResource($mapping->load('account')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, ChannelListing $mapping): ChannelListingResource
    {
        $this->authorize('update', $mapping);

        $data = $request->validate([
            'external_listing_id' => ['sometimes', 'string', 'max:191'],
            'external_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'external_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'rate_adjustment_basis_points' => ['sometimes', 'integer', 'min:-10000', 'max:10000'],
            'commission_basis_points' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', 'max:24'],
        ]);

        $mapping->fill($data)->save();

        // Changing the rate uplift changes what the channel should be
        // charging, so the rate calendar there is now wrong.
        if (array_key_exists('rate_adjustment_basis_points', $data)) {
            $mapping->markDirty('rates');
        }

        return new ChannelListingResource($mapping->fresh());
    }

    /**
     * Push this mapping now rather than waiting for the scheduler.
     *
     * Returns what each push actually did, including whether it was skipped
     * because nothing had changed — a skip is a successful outcome here, and
     * reporting it as one stops operators pushing repeatedly in the belief
     * that nothing happened.
     */
    public function push(Request $request, ChannelListing $mapping): JsonResponse
    {
        $this->authorize('sync', $mapping);

        $data = $request->validate([
            'what' => ['sometimes', 'string', 'in:availability,rates,both'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $what = $data['what'] ?? 'both';
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : null;
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : null;

        $results = [];

        if ($what === 'availability' || $what === 'both') {
            $results['availability'] = $this->describe($this->sync->pushAvailability($mapping, $from, $to));
        }

        if ($what === 'rates' || $what === 'both') {
            $results['rates'] = $this->describe($this->sync->pushRates($mapping, $from, $to));
        }

        return response()->json([
            'data' => $results + ['mapping' => new ChannelListingResource($mapping->fresh())],
        ]);
    }

    /**
     * Stop selling this listing on this channel.
     *
     * The mapping is deactivated, not removed: bookings that came through it
     * point at it, and deleting the row would leave them unable to say where
     * they came from.
     */
    public function destroy(ChannelListing $mapping): JsonResponse
    {
        $this->authorize('delete', $mapping);

        $mapping->forceFill([
            'is_active' => false,
            'status' => ChannelListing::STATUS_PAUSED,
        ])->save();

        return response()->json([
            'message' => 'The listing is no longer published to this channel. Bookings taken through it are unchanged.',
            'data' => new ChannelListingResource($mapping->fresh()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(?object $result): array
    {
        // A null result means the synchroniser decided there was nothing to
        // do — the mapping is clean, or the channel does not support this.
        if ($result === null) {
            return ['performed' => false, 'reason' => 'Nothing had changed since the last push.'];
        }

        return [
            'performed' => true,
            'successful' => $result->successful,
            'error_code' => $result->errorCode,
            'message' => $result->errorMessage,
            'retryable' => $result->retryable,
        ];
    }
}
