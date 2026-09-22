<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Models\SyncJob;
use App\Http\Controllers\Controller;
use App\Http\Resources\SyncJobResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The record of every conversation with a channel.
 *
 * Read-only by design. A sync job is evidence — what was sent, what came back,
 * how long it took, and whether the adapter that handled it was real. Letting
 * anybody edit it would destroy the only account of why a calendar went wrong.
 *
 * The health endpoint exists because the question operators actually ask is
 * not "show me the log" but "is distribution working right now". Answering it
 * from the same rows keeps the two from ever disagreeing.
 */
class SyncJobController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ChannelAccount::class);

        $query = SyncJob::query();

        if ($request->filled('channel_account_id')) {
            $query->where('channel_account_id', $request->string('channel_account_id')->toString());
        }

        if ($request->filled('channel_listing_id')) {
            $query->where('channel_listing_id', $request->string('channel_listing_id')->toString());
        }

        if ($request->filled('kind')) {
            $query->where('kind', $request->string('kind')->toString());
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }

        if ($request->boolean('failed_only')) {
            $query->failed();
        }

        return SyncJobResource::collection(
            $query->latest()->paginate($this->perPage()),
        );
    }

    public function show(SyncJob $job): SyncJobResource
    {
        $this->authorize('viewAny', ChannelAccount::class);

        return new SyncJobResource($job->load('account'));
    }

    /**
     * Is distribution working?
     *
     * Counts over the last day, plus the mappings that are behind or failing.
     * `simulated` is reported separately from `succeeded` on purpose: a wall
     * of green ticks from a simulated adapter says nothing about whether real
     * bookings can arrive, and an operator reading this screen deserves to
     * know which they are looking at.
     */
    public function health(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ChannelAccount::class);

        $since = now()->subDay();

        $counts = SyncJob::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as total, sum(case when is_simulated then 1 else 0 end) as simulated')
            ->groupBy('status')
            ->get();

        $accounts = ChannelAccount::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => [
                'window_hours' => 24,
                'jobs' => $counts->mapWithKeys(fn ($row): array => [
                    $row->status => [
                        'total' => (int) $row->total,
                        'simulated' => (int) $row->simulated,
                    ],
                ]),
                'accounts' => $accounts,
                'listings_behind' => ChannelListing::query()->dirty()->count(),
                'listings_failing' => ChannelListing::query()->failing()->count(),
                'retries_waiting' => SyncJob::query()->dueForRetry()->count(),
            ],
        ]);
    }
}
