<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\People;

use App\Domain\Documents\Services\DocumentBuilder;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Services\ClientAccounts;
use App\Domain\Owners\Services\ClientFinancials;
use App\Domain\Owners\Services\ClientPortal;
use App\Domain\Owners\Services\OwnerPortalService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientPropertyResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    public function __construct(
        private readonly OwnerPortalService $portal,
        private readonly ClientAccounts $clients,
        private readonly ClientPortal $reads,
        private readonly ClientFinancials $financials,
        private readonly DocumentBuilder $documents,
    ) {}

    /**
     * The client's properties, in a shape carrying nothing operational.
     */
    public function properties(): JsonResponse
    {
        return response()->json([
            'data' => ClientPropertyResource::collection($this->reads->properties($this->owner()))->resolve(),
        ]);
    }

    public function property(string $property): JsonResponse
    {
        $found = $this->reads->property($this->owner(), $property);

        // 404 for a property that is not theirs, exactly as for one that does
        // not exist: the difference is itself a disclosure.
        abort_if($found === null, 404);

        return response()->json([
            'data' => (new ClientPropertyResource($found))->resolve(),
        ]);
    }

    /**
     * The client's calendar: sold, closed or free, with stays as anonymous bars.
     */
    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
        ]);

        return response()->json($this->reads->calendar(
            $this->owner(),
            CarbonImmutable::parse($data['from'])->startOfDay(),
            CarbonImmutable::parse($data['to'])->startOfDay(),
        ));
    }

    /**
     * Revenue, the management commission and what is left, per property and
     * per currency, with every reason the figures might not be final.
     */
    public function financials(Request $request): JsonResponse
    {
        $owner = $this->owner();

        [$from, $to] = $this->period($request);

        return response()->json([
            'data' => $this->financials->forOwner($owner, $from, $to),
        ]);
    }

    /**
     * A statement the client was sent, as a PDF.
     *
     * Scoped to the signed-in client and to issued statements: a draft is the
     * management company's working figure and is never handed out here.
     */
    public function statementDocument(string $statement): StreamedResponse
    {
        $owner = $this->owner();

        $record = OwnerStatement::query()
            ->where('owner_id', $owner->getKey())
            ->whereIn('status', [OwnerStatement::STATUS_SENT, OwnerStatement::STATUS_PAID])
            ->find($statement);

        abort_if($record === null, 404);

        $document = $this->documents->ownerStatement($record);
        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($document->path), 404, 'The file is no longer in storage.');

        return $disk->download($document->path, $document->name);
    }

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
        // Their own owner record first; failing that, the account holder of
        // the client organization they belong to. A client account may have a
        // second login (a partner, an accountant) with no owner record of its
        // own, and that person reads the same portfolio.
        $owner = $this->clients->portalOwnerFor($this->currentUser(), $this->organization());

        abort_if(
            $owner === null,
            403,
            'This account is not linked to a client. Portal access is granted by the management company.',
        );

        return $owner;
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
