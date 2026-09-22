<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentSchedule;
use App\Domain\Payments\Services\PaymentScheduleService;
use App\Domain\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\PaymentScheduleResource;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * When a booking's money is due.
 *
 * Scoped under a reservation for writes, because a schedule without a booking
 * is meaningless, and exposed flat for reads, because "what is falling due
 * next week" is a question about the whole portfolio.
 */
class PaymentScheduleController extends Controller
{
    public function __construct(private readonly PaymentScheduleService $schedules) {}

    /**
     * Instalments across the portfolio.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Payment::class);

        $query = PaymentSchedule::query();

        if ($request->filled('reservation_id')) {
            $query->where('reservation_id', $request->string('reservation_id')->toString());
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }

        if ($request->boolean('outstanding_only')) {
            $query->outstanding();
        }

        if ($request->filled('due_before')) {
            $query->where('due_on', '<=', $request->date('due_before')->toDateString());
        }

        return PaymentScheduleResource::collection(
            $query->orderBy('due_on')->paginate($this->perPage()),
        );
    }

    /**
     * A booking's plan.
     */
    public function forReservation(Reservation $reservation): AnonymousResourceCollection
    {
        $this->authorize('view', $reservation);

        return PaymentScheduleResource::collection(
            PaymentSchedule::query()
                ->where('reservation_id', $reservation->getKey())
                ->orderBy('sequence')
                ->get(),
        );
    }

    /**
     * Set or replace a booking's plan.
     *
     * Either an explicit list of instalments, or the conventional deposit-plus-
     * balance shape most operators want.
     */
    public function store(Request $request, Reservation $reservation): JsonResponse
    {
        $this->authorize('charge', Payment::class);

        $data = $request->validate([
            'instalments' => ['sometimes', 'array', 'min:1'],
            'instalments.*.label' => ['required', 'string', 'max:120'],
            'instalments.*.due_on' => ['required', 'date'],
            // One of the two per instalment: a fixed amount in minor units, or
            // a share of what is left. Mixing them across a plan is fine — the
            // fixed parts are taken first and the rest is allocated.
            'instalments.*.amount' => ['sometimes', 'integer', 'min:0'],
            'instalments.*.percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'instalments.*.auto_charge' => ['sometimes', 'boolean'],

            'deposit_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'balance_days_before_arrival' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'auto_charge' => ['sometimes', 'boolean'],
        ]);

        $schedules = isset($data['instalments'])
            ? $this->schedules->plan($reservation, $data['instalments'])
            : $this->schedules->standardPlan(
                $reservation,
                (float) ($data['deposit_percent'] ?? 30.0),
                (int) ($data['balance_days_before_arrival'] ?? 14),
                (bool) ($data['auto_charge'] ?? false),
            );

        return PaymentScheduleResource::collection($schedules)
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, PaymentSchedule $schedule): PaymentScheduleResource
    {
        $this->authorize('charge', Payment::class);

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:120'],
            'due_on' => ['sometimes', 'date'],
            'amount' => ['sometimes', 'integer', 'min:1'],
            'auto_charge' => ['sometimes', 'boolean'],
        ]);

        // A paid instalment is history. Moving its date or amount after the
        // fact would make the money received disagree with what it was for.
        abort_if($schedule->status === 'paid', 422, 'A paid instalment can no longer be changed.');

        $schedule->fill($data)->save();

        return new PaymentScheduleResource($schedule);
    }

    /**
     * Take an instalment now rather than waiting for the sweep.
     */
    public function charge(PaymentSchedule $schedule): PaymentResource
    {
        $this->authorize('charge', Payment::class);

        return new PaymentResource($this->schedules->charge($schedule));
    }

    /**
     * Record money received against an instalment without charging a card.
     */
    public function settle(Request $request, PaymentSchedule $schedule): PaymentScheduleResource
    {
        $this->authorize('charge', Payment::class);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        return new PaymentScheduleResource($this->schedules->settle(
            $schedule,
            Money::of((int) $data['amount'], $schedule->currency),
        ));
    }

    /**
     * Waive an instalment.
     *
     * Kept on the plan rather than deleted, because "we decided not to collect
     * this" is an answer somebody will need when the totals do not match.
     */
    public function waive(Request $request, PaymentSchedule $schedule): PaymentScheduleResource
    {
        $this->authorize('charge', Payment::class);

        abort_if($schedule->status === 'paid', 422, 'A paid instalment cannot be waived.');

        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        $schedule->forceFill([
            'status' => 'waived',
            'metadata' => array_filter(($schedule->metadata ?? []) + [
                'waived_reason' => $data['reason'] ?? null,
                'waived_by' => $this->currentUser()->getKey(),
                'waived_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return new PaymentScheduleResource($schedule);
    }
}
