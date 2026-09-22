<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Domain\OwnerAccounting\Models\OwnerPayout;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\OwnerAccounting\Services\OwnerPayoutService;
use App\Domain\Owners\Models\Owner;
use App\Http\Controllers\Controller;
use App\Http\Resources\OwnerPayoutResource;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Money sent to owners.
 *
 * Raising a payout and settling one are separate calls because they are
 * separate events: the first says what we intend to send, the second says the
 * transfer actually happened. Only the second moves the ledger, which is why
 * an unsent payout can be cancelled freely and a sent one cannot be touched at
 * all.
 */
class OwnerPayoutController extends Controller
{
    public function __construct(private readonly OwnerPayoutService $payouts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', OwnerPayout::class);

        $query = OwnerPayout::query()->with('owner');

        $ownerId = $this->currentUser()->ownerId();

        if ($ownerId !== null) {
            $query->where('owner_id', $ownerId);
        } elseif ($request->filled('owner_id')) {
            $query->where('owner_id', $request->string('owner_id')->toString());
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }

        if ($request->boolean('outstanding_only')) {
            $query->outstanding();
        }

        return OwnerPayoutResource::collection(
            $query->latest()->paginate($this->perPage()),
        );
    }

    public function show(OwnerPayout $payout): OwnerPayoutResource
    {
        $this->authorize('view', $payout);

        return new OwnerPayoutResource($payout->load(['owner', 'statement']));
    }

    /**
     * Raise a payout — either against an approved statement, or directly.
     *
     * Raising against a statement is idempotent: a second request returns the
     * payout already raised rather than sending the money twice.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', OwnerPayout::class);

        $data = $request->validate([
            'owner_statement_id' => ['sometimes', 'string', 'size:26'],
            'owner_id' => ['required_without:owner_statement_id', 'string', 'size:26'],
            'amount' => ['required_without:owner_statement_id', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'scheduled_for' => ['sometimes', 'nullable', 'date'],
            'method' => ['sometimes', 'nullable', 'string', 'max:32'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $attributes = array_filter([
            'scheduled_for' => $data['scheduled_for'] ?? null,
            'method' => $data['method'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        if (isset($data['owner_statement_id'])) {
            $statement = OwnerStatement::query()->findOrFail($data['owner_statement_id']);

            $payout = $this->payouts->fromStatement($statement, $attributes);
        } else {
            $owner = Owner::query()->findOrFail($data['owner_id']);

            $payout = $this->payouts->create(
                $owner,
                Money::of(
                    (int) $data['amount'],
                    $data['currency'] ?? $owner->payout_currency ?: $this->organization()->base_currency,
                ),
                $attributes,
            );
        }

        return (new OwnerPayoutResource($payout))->response()->setStatusCode(201);
    }

    /**
     * The transfer has gone out.
     *
     * This is the moment the ledger moves: the owner payable is discharged and
     * cash goes down.
     */
    public function settle(Request $request, OwnerPayout $payout): OwnerPayoutResource
    {
        $this->authorize('settle', $payout);

        $data = $request->validate([
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);

        return new OwnerPayoutResource(
            $this->payouts->markPaid($payout, $data['external_reference'] ?? null),
        );
    }

    /**
     * The transfer bounced.
     *
     * Nothing is reversed, because nothing was posted: the owner is still
     * owed, and the failed attempt stays on the record so the next one is
     * visibly a second attempt.
     */
    public function fail(Request $request, OwnerPayout $payout): OwnerPayoutResource
    {
        $this->authorize('update', $payout);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return new OwnerPayoutResource($this->payouts->markFailed($payout, $data['reason']));
    }

    /**
     * Withdraw a payout that has not gone out.
     */
    public function destroy(Request $request, OwnerPayout $payout): OwnerPayoutResource
    {
        $this->authorize('update', $payout);

        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return new OwnerPayoutResource($this->payouts->cancel($payout, $data['reason'] ?? null));
    }
}
