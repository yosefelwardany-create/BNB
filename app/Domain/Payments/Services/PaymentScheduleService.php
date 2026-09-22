<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentSchedule;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * When a booking's money is due.
 *
 * The schedule is the *plan*; payments are what actually happened against it.
 * Keeping the two apart is what lets a guest pay early, late, or in an
 * instalment nobody planned for without corrupting either record — and it lets
 * the plan be renegotiated without rewriting what was received.
 *
 * Two behaviours are worth stating plainly.
 *
 * **Instalments are allocated, never rounded independently.** A deposit of
 * 30% on 100.01 and a balance of 70% must sum back to 100.01 exactly, not to
 * 100.00 or 100.02. The Money allocator guarantees that; percentage
 * arithmetic on each part separately does not.
 *
 * **Auto-charging is opt-in per instalment.** Taking a guest's stored card
 * without them pressing anything is something they agreed to at booking. A
 * schedule row with `auto_charge` false is a reminder, not an instruction.
 */
class PaymentScheduleService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Replace a booking's schedule with a new plan.
     *
     * Instalments already paid are left alone: they are history, and the
     * remaining plan is built around them. An attempt to re-plan a fully paid
     * booking is therefore a no-op rather than an error.
     *
     * @param  list<array{label: string, due_on: string|CarbonImmutable, amount?: int, percent?: float, auto_charge?: bool}>  $instalments
     * @return Collection<int, PaymentSchedule>
     */
    public function plan(Reservation $reservation, array $instalments): Collection
    {
        $organization = $this->tenancy->organizationOrFail();

        return DB::transaction(function () use ($reservation, $instalments, $organization): Collection {
            $settled = PaymentSchedule::query()
                ->where('reservation_id', $reservation->getKey())
                ->whereIn('status', ['paid', 'partially_paid'])
                ->get();

            // Only the unsettled part of the plan is replaceable. Deleting a
            // paid instalment would orphan the money received against it.
            PaymentSchedule::query()
                ->where('reservation_id', $reservation->getKey())
                ->whereNotIn('status', ['paid', 'partially_paid'])
                ->delete();

            $alreadyPlanned = $settled->sum('amount');
            $remaining = Money::of(
                max(0, $reservation->grand_total - (int) $alreadyPlanned),
                $reservation->currency,
            );

            $amounts = $this->allocate($remaining, $instalments);
            $sequence = (int) $settled->max('sequence');
            $created = $settled->values();

            foreach ($instalments as $index => $instalment) {
                $amount = $amounts[$index];

                if (! $amount->isPositive()) {
                    continue;
                }

                $created->push(PaymentSchedule::query()->create([
                    'organization_id' => $organization->getKey(),
                    'reservation_id' => $reservation->getKey(),
                    'sequence' => ++$sequence,
                    'label' => $instalment['label'],
                    'amount' => $amount->minorUnits,
                    'currency' => $reservation->currency,
                    'due_on' => CarbonImmutable::parse($instalment['due_on'])->toDateString(),
                    'auto_charge' => (bool) ($instalment['auto_charge'] ?? false),
                ]));
            }

            return $created->sortBy('sequence')->values();
        });
    }

    /**
     * The conventional two-part plan: a deposit now, the balance before
     * arrival.
     *
     * Offered as a default because it is what most operators want and nobody
     * should have to hand-build it. The percentage and the lead time are the
     * two things they actually vary.
     *
     * @return Collection<int, PaymentSchedule>
     */
    public function standardPlan(
        Reservation $reservation,
        float $depositPercent = 30.0,
        int $balanceDaysBeforeArrival = 14,
        bool $autoCharge = false,
    ): Collection {
        $balanceDue = CarbonImmutable::parse($reservation->check_in_date)
            ->subDays($balanceDaysBeforeArrival);

        // A booking made inside the balance window has no time left to wait:
        // the balance is due today, not on a date already past.
        $today = CarbonImmutable::today();

        return $this->plan($reservation, [
            [
                'label' => 'Deposit',
                'due_on' => $today,
                'percent' => $depositPercent,
                'auto_charge' => $autoCharge,
            ],
            [
                'label' => 'Balance',
                'due_on' => $balanceDue->lessThan($today) ? $today : $balanceDue,
                'percent' => 100.0 - $depositPercent,
                'auto_charge' => $autoCharge,
            ],
        ]);
    }

    /**
     * Take an instalment now.
     *
     * Returns the payment so the caller can report what the processor said.
     * The schedule is updated from the payment rather than assumed: a partial
     * capture leaves the instalment partially paid, which is the truth.
     */
    public function charge(PaymentSchedule $schedule): Payment
    {
        if ($schedule->status === 'paid') {
            throw new PaymentException('This instalment has already been paid.', 'already_paid');
        }

        $outstanding = $schedule->outstandingAmount();

        if (! $outstanding->isPositive()) {
            throw new PaymentException('This instalment has nothing outstanding.', 'nothing_due');
        }

        $reservation = $schedule->reservation;

        $schedule->forceFill([
            'attempts' => (int) $schedule->attempts + 1,
            'last_attempt_at' => now(),
        ])->save();

        try {
            $payment = $this->payments->charge($outstanding, $reservation, [
                'guest_id' => $reservation?->guest_id,
                'property_id' => $reservation?->property_id,
                'description' => sprintf('%s for %s', $schedule->label, $reservation?->confirmation_code),
                'metadata' => ['payment_schedule_id' => $schedule->getKey()],
            ]);
        } catch (\Throwable $exception) {
            $schedule->forceFill([
                'status' => 'failed',
                'last_failure_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        $this->settle($schedule, $payment->capturedAmount());

        return $payment;
    }

    /**
     * Record money received against an instalment.
     *
     * Separate from {@see charge()} because plenty of money arrives without
     * the platform asking for it — a bank transfer, cash, a channel payout.
     */
    public function settle(PaymentSchedule $schedule, Money $amount): PaymentSchedule
    {
        $paid = $schedule->paidAmount()->add($amount);

        $schedule->forceFill([
            'paid_amount' => min($paid->minorUnits, $schedule->amount),
            'status' => $paid->greaterThanOrEqual($schedule->amount()) ? 'paid' : 'partially_paid',
            'paid_at' => $paid->greaterThanOrEqual($schedule->amount()) ? now() : $schedule->paid_at,
            'last_failure_message' => null,
        ])->save();

        return $schedule;
    }

    /**
     * Sweep instalments that have fallen due.
     *
     * One failure must never stop the run: a declined card on one booking has
     * nothing to do with the next, and a scheduler that stops at the first
     * problem quietly stops collecting money for everybody.
     *
     * @return array{charged: int, failed: int, marked_overdue: int}
     */
    public function processDue(?string $on = null): array
    {
        $on ??= CarbonImmutable::today()->toDateString();

        $charged = 0;
        $failed = 0;

        PaymentSchedule::query()
            ->dueForCharge($on)
            ->with('reservation')
            ->orderBy('due_on')
            ->chunkById(100, function (Collection $schedules) use (&$charged, &$failed): void {
                foreach ($schedules as $schedule) {
                    // A cancelled booking owes nothing. Charging it would be
                    // taking money for a stay that is not happening.
                    if ($schedule->reservation === null || ! $schedule->reservation->blocksInventory()) {
                        $schedule->forceFill(['status' => 'cancelled'])->save();

                        continue;
                    }

                    try {
                        $this->charge($schedule);
                        $charged++;
                    } catch (\Throwable $exception) {
                        $failed++;

                        Log::warning('Scheduled instalment could not be taken.', [
                            'payment_schedule_id' => $schedule->getKey(),
                            'reservation_id' => $schedule->reservation_id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        // Anything still outstanding past its date is overdue whether or not
        // it was ever going to be charged automatically, because somebody has
        // to chase it.
        $overdue = PaymentSchedule::query()
            ->whereIn('status', ['pending', 'partially_paid'])
            ->where('due_on', '<', $on)
            ->update(['status' => 'overdue']);

        return ['charged' => $charged, 'failed' => $failed, 'marked_overdue' => $overdue];
    }

    /**
     * Split a total across instalments so the parts sum back exactly.
     *
     * Explicit amounts are taken as given and the rest is allocated by weight.
     * Doing it any other way — computing each percentage independently and
     * rounding — loses or invents a minor unit on most totals, and a guest
     * whose instalments do not add up to their booking notices.
     *
     * @param  list<array<string, mixed>>  $instalments
     * @return list<Money>
     */
    private function allocate(Money $total, array $instalments): array
    {
        $amounts = [];
        $weighted = [];
        $remainder = $total;

        foreach ($instalments as $index => $instalment) {
            if (isset($instalment['amount'])) {
                $amount = Money::of((int) $instalment['amount'], $total->currency);
                $amounts[$index] = $amount;
                $remainder = $remainder->subtract($amount);

                continue;
            }

            $weighted[$index] = (float) ($instalment['percent'] ?? 0);
        }

        // Weights that sum to nothing cannot allocate anything; treat those
        // instalments as zero rather than letting the allocator refuse the
        // whole plan over a caller who left every percentage blank.
        if ($weighted !== [] && array_sum($weighted) > 0) {
            if ($remainder->isNegative()) {
                $remainder = Money::zero($total->currency);
            }

            $shares = $remainder->allocateByWeights(array_values($weighted));

            foreach (array_keys($weighted) as $position => $index) {
                $amounts[$index] = $shares[$position];
            }
        } else {
            foreach (array_keys($weighted) as $index) {
                $amounts[$index] = Money::zero($total->currency);
            }
        }

        ksort($amounts);

        return array_values($amounts);
    }
}
