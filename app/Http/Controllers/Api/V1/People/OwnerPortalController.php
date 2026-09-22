<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\People;

use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Services\OwnerPortalService;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The owner's own view of their portfolio.
 *
 * Authenticated as a real user, unlike the guest portal, because an owner
 * relationship lasts years and a link that lasts years is not a credential.
 *
 * The subject is always the signed-in owner. There is no owner id in any of
 * these routes, so there is no parameter to tamper with: staff wanting to see
 * an owner's figures use the ordinary owner and statement endpoints, which are
 * gated on staff permissions. Keeping the two surfaces apart is what stops a
 * single missed authorisation check turning into every owner reading every
 * other owner's revenue.
 */
class OwnerPortalController extends Controller
{
    public function __construct(private readonly OwnerPortalService $portal) {}

    /**
     * Performance, statements, payouts and balance.
     */
    public function summary(Request $request): JsonResponse
    {
        $owner = $this->owner();

        [$from, $to] = $this->period($request);

        return response()->json([
            'data' => $this->portal->summary($owner, $from, $to),
        ]);
    }

    /**
     * What is booked at the owner's properties.
     */
    public function upcoming(Request $request): JsonResponse
    {
        $owner = $this->owner();

        $data = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        return response()->json([
            'data' => $this->portal->upcomingStays($owner, (int) ($data['days'] ?? 90)),
            'meta' => [
                // Said rather than left to be noticed: an owner who expects
                // guest names and finds none should know it is deliberate.
                'notice' => 'Stays are shown without guest details. Who stayed is held by the managing agent.',
            ],
        ]);
    }

    /**
     * Statements the owner has been sent.
     */
    public function statements(): JsonResponse
    {
        return response()->json([
            'data' => $this->portal->statements($this->owner(), limit: 36),
        ]);
    }

    public function payouts(): JsonResponse
    {
        return response()->json([
            'data' => $this->portal->payouts($this->owner(), limit: 36),
        ]);
    }

    /**
     * The owner this login belongs to.
     *
     * A 403 rather than a 404 when there is none: this endpoint exists, the
     * caller simply is not an owner, and saying so avoids a support ticket
     * about a missing page.
     */
    private function owner(): Owner
    {
        $ownerId = $this->currentUser()->ownerId();

        abort_if(
            $ownerId === null,
            403,
            'This account is not linked to an owner. Owner portal access is granted from the owner record.',
        );

        return Owner::query()->findOrFail($ownerId);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $today = CarbonImmutable::today();

        // The year to date by default, which is the period an owner asks
        // about when they ask "how has it been going".
        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'])->startOfDay()
            : $today->startOfYear();

        $to = isset($data['to'])
            ? CarbonImmutable::parse($data['to'])->startOfDay()
            : $today;

        abort_if($from->diffInDays($to) > 1100, 422, 'The period may cover at most three years.');

        return [$from, $to];
    }
}
