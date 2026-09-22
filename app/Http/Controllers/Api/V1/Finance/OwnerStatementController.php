<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Domain\Notifications\Services\Notifier;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\OwnerAccounting\Services\OwnerStatementBuilder;
use App\Domain\Owners\Models\Owner;
use App\Domain\Properties\Models\Property;
use App\Http\Controllers\Controller;
use App\Http\Resources\OwnerStatementResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * What an owner earned, what it cost, and what is owed.
 *
 * The lifecycle is the point of this controller. A **draft** can be rebuilt as
 * often as anybody likes and consumes nothing. **Approving** freezes every
 * figure and marks the revenue and expenses behind them as spent, so no second
 * statement can pick them up. **Sending** puts the figures in front of the
 * owner, after which they are a claim the business has made.
 *
 * Nothing here deletes. A statement that was wrong is voided and superseded,
 * because the owner is holding a copy of what it said.
 */
class OwnerStatementController extends Controller
{
    public function __construct(
        private readonly OwnerStatementBuilder $builder,
        private readonly Notifier $notifier,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', OwnerStatement::class);

        $query = OwnerStatement::query()->with(['owner', 'property']);

        // An owner reading their own statements sees only theirs, and only the
        // ones that have actually been sent. Enforced here as well as in the
        // policy: the policy guards a single record, this guards the list.
        $ownerId = $this->currentUser()->ownerId();

        if ($ownerId !== null) {
            $query->where('owner_id', $ownerId)
                ->whereIn('status', [OwnerStatement::STATUS_SENT, OwnerStatement::STATUS_PAID]);
        } elseif ($request->filled('owner_id')) {
            $query->where('owner_id', $request->string('owner_id')->toString());
        }

        if ($request->filled('property_id')) {
            $query->where('property_id', $request->string('property_id')->toString());
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }

        if ($request->filled('from')) {
            $query->where('period_end', '>=', $request->date('from')->toDateString());
        }

        if ($request->filled('to')) {
            $query->where('period_start', '<=', $request->date('to')->toDateString());
        }

        return OwnerStatementResource::collection(
            $query->orderByDesc('period_start')->paginate($this->perPage()),
        );
    }

    public function show(OwnerStatement $statement): OwnerStatementResource
    {
        $this->authorize('view', $statement);

        return new OwnerStatementResource($statement->load(['lines', 'owner', 'property']));
    }

    /**
     * Build, or rebuild, a draft for a period.
     *
     * Rebuilding is safe and expected: nothing is consumed until approval, so
     * a statement can be regenerated after a late expense or a corrected
     * booking without any risk of double-counting.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', OwnerStatement::class);

        $data = $request->validate([
            'owner_id' => ['required', 'string', 'size:26'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            // Optional: a per-property statement for an owner who wants each
            // apartment accounted for separately.
            'property_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        $owner = Owner::query()->findOrFail($data['owner_id']);

        $property = isset($data['property_id'])
            ? Property::query()->findOrFail($data['property_id'])
            : null;

        $statement = $this->builder->build(
            $owner,
            CarbonImmutable::parse($data['period_start'])->startOfDay(),
            CarbonImmutable::parse($data['period_end'])->startOfDay(),
            $property,
        );

        return (new OwnerStatementResource($statement->load('lines')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Freeze the figures and consume what they were built from.
     */
    public function approve(OwnerStatement $statement): OwnerStatementResource
    {
        $this->authorize('approve', $statement);

        return new OwnerStatementResource(
            $this->builder->approve($statement)->load('lines'),
        );
    }

    /**
     * Put the statement in front of the owner.
     *
     * The notification goes through the same dispatcher as everything else, so
     * an operator running without a real mail transport gets a recorded
     * simulated delivery rather than the impression that a statement was sent.
     */
    public function send(OwnerStatement $statement): OwnerStatementResource
    {
        $this->authorize('send', $statement);

        $owner = $statement->owner;
        $user = $owner?->user;

        if ($user !== null) {
            $this->notifier->notify(
                $user,
                'owner_statement.sent',
                sprintf('Your statement for %s is ready', $statement->period_start?->format('F Y')),
                sprintf(
                    'Statement %s covering %s to %s. Amount due to you: %s.',
                    $statement->reference,
                    $statement->period_start?->toDateString(),
                    $statement->period_end?->toDateString(),
                    $statement->payoutAmount()->toDecimal(),
                ),
                subject: $statement,
            );
        }

        $statement->forceFill([
            'status' => OwnerStatement::STATUS_SENT,
            'sent_at' => now(),
        ])->save();

        return new OwnerStatementResource($statement);
    }

    /**
     * Withdraw a statement that should never have been issued.
     *
     * Voided, not deleted, and only before it has been paid. The lines it
     * consumed are released so a corrected statement can pick them up.
     */
    public function void(Request $request, OwnerStatement $statement): OwnerStatementResource
    {
        $this->authorize('void', $statement);

        abort_if(
            $statement->status === OwnerStatement::STATUS_PAID,
            422,
            'A statement that has been paid cannot be voided; issue a correcting statement instead.',
        );

        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        return new OwnerStatementResource(
            $this->builder->void($statement, $data['reason'] ?? null),
        );
    }
}
