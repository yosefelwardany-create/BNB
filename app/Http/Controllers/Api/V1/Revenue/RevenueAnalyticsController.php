<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Revenue;

use App\Domain\Pricing\Services\RevenueAnalytics;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Occupancy, ADR, RevPAR and pace.
 *
 * Every figure here is computed from reservation nights at request time rather
 * than read from a summary table. A rollup would be faster and would
 * eventually disagree with the bookings it was meant to summarise — and a
 * revenue report that disagrees with the reservations list is worse than no
 * report, because somebody will act on it.
 *
 * Authorisation is by permission name rather than by model policy: there is no
 * record being acted on here, only an aggregate over records the caller's
 * tenancy already bounds.
 */
class RevenueAnalyticsController extends Controller
{
    public function __construct(private readonly RevenueAnalytics $analytics) {}

    /**
     * Headline performance for a period.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('revenue.view');

        [$from, $to, $properties] = $this->window($request);

        return response()->json([
            'data' => $this->analytics->summary($from, $to, $properties),
        ]);
    }

    /**
     * One row per day: what a pace or occupancy chart is drawn from.
     */
    public function daily(Request $request): JsonResponse
    {
        $this->authorize('revenue.view');

        [$from, $to, $properties] = $this->window($request, maxDays: 400);

        return response()->json([
            'data' => $this->analytics->daily($from, $to, $properties),
        ]);
    }

    /**
     * Where the business came from.
     */
    public function bySource(Request $request): JsonResponse
    {
        $this->authorize('revenue.view');

        [$from, $to, $properties] = $this->window($request);

        return response()->json([
            'data' => $this->analytics->bySource($from, $to, $properties),
        ]);
    }

    /**
     * Per-property performance, ranked by revenue.
     */
    public function byProperty(Request $request): JsonResponse
    {
        $this->authorize('revenue.view');

        [$from, $to, $properties] = $this->window($request);

        return response()->json([
            'data' => $this->analytics->byProperty($from, $to, $properties),
        ]);
    }

    /**
     * What is already on the books for a future period.
     *
     * The leading indicator: it counts by when the booking was made, which is
     * the difference between noticing in January that August is soft and
     * noticing it in August.
     */
    public function pace(Request $request): JsonResponse
    {
        $this->authorize('revenue.view');

        [$from, $to, $properties] = $this->window($request, defaultForward: true);

        return response()->json([
            'data' => $this->analytics->pace($from, $to, $properties),
        ]);
    }

    /**
     * The window and scope every endpoint here shares.
     *
     * Bounded rather than unlimited: an unbounded range over a large portfolio
     * is a table scan somebody will run by accident, and the answer would be
     * unreadable anyway.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: list<string>}
     */
    private function window(Request $request, int $maxDays = 1100, bool $defaultForward = false): array
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'property_ids' => ['sometimes', 'array'],
            'property_ids.*' => ['string', 'size:26'],
        ]);

        $today = CarbonImmutable::today();

        // Pace looks forward by default; everything else looks back. Both
        // defaults are what somebody opening the screen means.
        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'])->startOfDay()
            : ($defaultForward ? $today : $today->subDays(29));

        $to = isset($data['to'])
            ? CarbonImmutable::parse($data['to'])->startOfDay()
            : ($defaultForward ? $today->addDays(89) : $today);

        abort_if(
            $from->diffInDays($to) > $maxDays,
            422,
            sprintf('The period may cover at most %d days.', $maxDays),
        );

        return [$from, $to, array_values($data['property_ids'] ?? [])];
    }
}
