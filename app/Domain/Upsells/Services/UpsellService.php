<?php

declare(strict_types=1);

namespace App\Domain\Upsells\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Operations\Enums\TaskKind;
use App\Domain\Operations\Services\TaskService;
use App\Domain\Platform\Services\SequenceGenerator;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Upsells\Exceptions\UpsellUnavailableException;
use App\Domain\Upsells\Models\UpsellOrder;
use App\Domain\Upsells\Models\UpsellProduct;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Selling extras, and making sure somebody actually delivers them.
 *
 * Two rules, both learned the same way.
 *
 * **Nothing is sold that cannot be delivered.** Lead time and daily capacity
 * are checked before an order is taken, not after. An early check-in ordered
 * an hour before arrival is a promise the housekeeping team cannot keep, and
 * taking the money first converts a small extra into a complaint and a refund.
 *
 * **An accepted order creates work.** An upsell that produces a charge and no
 * task is an upsell nobody delivers: the guest paid for an airport transfer
 * and, on the day, no driver knows about it. So approval raises the task in
 * the same transaction as the charge.
 */
class UpsellService
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly SequenceGenerator $sequences,
        private readonly ReservationService $reservations,
        private readonly TaskService $tasks,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * What a guest can buy for this stay, right now.
     *
     * Filtered by lead time, so the menu never shows something that cannot be
     * delivered in the time remaining. A menu that offers what it cannot
     * provide is worse than a shorter menu.
     *
     * @return list<array<string, mixed>>
     */
    public function availableFor(Reservation $reservation): array
    {
        $products = UpsellProduct::query()
            ->active()
            ->forProperty((string) $reservation->property_id)
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        $serviceAt = $this->defaultServiceDate($reservation);
        $nights = (int) $reservation->nights;
        $guests = (int) $reservation->adults + (int) $reservation->children;

        return $products
            ->filter(fn (UpsellProduct $product): bool => $product->canBeOrderedFor($serviceAt))
            ->map(fn (UpsellProduct $product): array => [
                'id' => $product->getKey(),
                'code' => $product->code,
                'name' => $product->name,
                'description' => $product->description,
                'kind' => $product->kind,
                'charge_basis' => $product->charge_basis,
                'unit_price' => $product->price()->jsonSerialize(),
                // What this stay would actually be charged, worked out rather
                // than left for the guest to multiply.
                'price_for_this_stay' => $product->priceFor(1, $nights, $guests)->jsonSerialize(),
                'max_quantity' => (int) $product->max_quantity,
                'requires_approval' => (bool) $product->requires_approval,
                'lead_time_hours' => (int) $product->lead_time_hours,
            ])
            ->values()
            ->all();
    }

    /**
     * Take an order.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function order(
        Reservation $reservation,
        UpsellProduct $product,
        int $quantity = 1,
        array $attributes = [],
    ): UpsellOrder {
        $organization = $this->tenancy->organizationOrFail();

        $serviceDate = isset($attributes['service_date'])
            ? CarbonImmutable::parse($attributes['service_date'])
            : $this->defaultServiceDate($reservation);

        $this->assertOrderable($product, $reservation, $quantity, $serviceDate);

        return DB::transaction(function () use (
            $reservation, $product, $quantity, $attributes, $serviceDate, $organization
        ): UpsellOrder {
            $nights = (int) $reservation->nights;
            $guests = (int) $reservation->adults + (int) $reservation->children;

            $total = $product->priceFor($quantity, $nights, $guests);

            $order = UpsellOrder::query()->create([
                'organization_id' => $organization->getKey(),
                'upsell_product_id' => $product->getKey(),
                'reservation_id' => $reservation->getKey(),
                'reference' => $this->sequences->next(
                    $organization->getKey(),
                    SequenceGenerator::UPSELL,
                    'UPS',
                    5,
                ),
                'quantity' => $quantity,
                // Copied onto the order, never read back from the product. A
                // guest who ordered a transfer at 40 pays 40, whatever it
                // costs by the time somebody drives them.
                'unit_price' => $product->price()->minorUnits,
                'total_price' => $total->minorUnits,
                'currency' => $total->currency,
                'service_date' => $serviceDate->toDateString(),
                'guest_notes' => $attributes['guest_notes'] ?? null,
                'status' => UpsellOrder::REQUESTED,
            ]);

            // Where no human decision is needed, the order is agreed at once —
            // and that means charged and scheduled at once too.
            if (! $product->requires_approval) {
                return $this->approve($order->fresh(), automatic: true);
            }

            return $order;
        });
    }

    /**
     * Agree to an order: charge it, and raise the work it implies.
     */
    public function approve(UpsellOrder $order, bool $automatic = false): UpsellOrder
    {
        if ($order->isChargeable()) {
            return $order;
        }

        if ($order->isSettled()) {
            throw new UpsellUnavailableException('This order has already been settled and cannot be approved.');
        }

        return DB::transaction(function () use ($order, $automatic): UpsellOrder {
            $order->forceFill([
                'status' => UpsellOrder::APPROVED,
                'approved_at' => now(),
                'approved_by_id' => $automatic ? null : auth()->id(),
            ])->save();

            $this->charge($order);
            $this->schedule($order);

            $this->audit->record(
                action: 'upsell.approved',
                subject: $order,
                description: sprintf(
                    '%s %s for %s.',
                    $automatic ? 'Automatically accepted' : 'Accepted',
                    $order->product?->name ?? 'an extra',
                    $order->reservation?->confirmation_code,
                ),
            );

            return $order->fresh();
        });
    }

    /**
     * Refuse an order.
     *
     * Kept rather than deleted: a guest who asked for something and was told
     * no will refer to it, and a missing record makes that conversation
     * impossible.
     */
    public function decline(UpsellOrder $order, string $reason): UpsellOrder
    {
        if ($order->isChargeable()) {
            throw new UpsellUnavailableException(
                'This order has already been accepted and charged. Cancel it instead, so the charge is reversed.',
            );
        }

        $order->forceFill([
            'status' => UpsellOrder::DECLINED,
            'declined_at' => now(),
            'declined_reason' => $reason,
        ])->save();

        return $order->fresh();
    }

    /**
     * Mark an order delivered.
     */
    public function fulfil(UpsellOrder $order): UpsellOrder
    {
        if (! $order->isChargeable()) {
            throw new UpsellUnavailableException('Only an accepted order can be marked as delivered.');
        }

        $order->forceFill([
            'status' => UpsellOrder::FULFILLED,
            'fulfilled_at' => now(),
        ])->save();

        return $order->fresh();
    }

    /**
     * Withdraw an order, reversing what it added.
     *
     * The charge is removed from the booking rather than left with a credit
     * beside it: an extra that never happened should not appear on the guest's
     * bill at all, and a line plus its own reversal is confusing on an invoice
     * a guest reads once.
     */
    public function cancel(UpsellOrder $order, ?string $reason = null): UpsellOrder
    {
        if ($order->status === UpsellOrder::FULFILLED) {
            throw new UpsellUnavailableException(
                'This extra has already been delivered. Refund it rather than cancelling it.',
            );
        }

        return DB::transaction(function () use ($order, $reason): UpsellOrder {
            if ($order->reservation_charge_id !== null) {
                $this->reservations->removeCharge($order->reservation, $order->reservation_charge_id);
            }

            $order->forceFill([
                'status' => UpsellOrder::CANCELLED,
                'reservation_charge_id' => null,
                'internal_notes' => trim(
                    ($order->internal_notes ? $order->internal_notes."\n" : '').($reason ?? ''),
                ) ?: $order->internal_notes,
            ])->save();

            return $order->fresh();
        });
    }

    /**
     * Put the cost on the booking.
     */
    private function charge(UpsellOrder $order): void
    {
        $reservation = $order->reservation;

        if ($reservation === null) {
            return;
        }

        $charge = $this->reservations->addCharge(
            $reservation,
            'upsell',
            sprintf('%s × %d', $order->product?->name ?? 'Extra', (int) $order->quantity),
            $order->totalPrice(),
            [
                'description' => $order->guest_notes,
                'is_taxable' => (bool) ($order->product?->is_taxable ?? true),
                'is_refundable' => true,
            ],
        );

        $order->forceFill(['reservation_charge_id' => $charge->getKey()])->save();
    }

    /**
     * Raise the work the extra implies.
     *
     * An upsell that produces a charge and no task is an upsell nobody
     * delivers. Not every extra needs one — a late checkout is a calendar fact
     * rather than a job — so the mapping is explicit rather than universal.
     */
    private function schedule(UpsellOrder $order): void
    {
        $kind = match ($order->product?->kind) {
            'extra_cleaning' => TaskKind::Cleaning,
            'early_check_in' => TaskKind::Cleaning,
            'transfer', 'experience', 'equipment' => TaskKind::GuestRequest,
            default => null,
        };

        if ($kind === null || $order->reservation === null) {
            return;
        }

        $task = $this->tasks->create([
            'kind' => $kind->value,
            'property_id' => $order->reservation->property_id,
            'unit_id' => $order->reservation->unit_id,
            'reservation_id' => $order->reservation->getKey(),
            'title' => sprintf('%s — %s', $order->product?->name, $order->reservation->confirmation_code),
            'description' => $order->guest_notes,
            'due_at' => CarbonImmutable::parse($order->service_date)->setTime(12, 0),
        ]);

        $order->forceFill(['task_id' => $task->getKey()])->save();
    }

    /**
     * Refuse an order nobody could deliver.
     */
    private function assertOrderable(
        UpsellProduct $product,
        Reservation $reservation,
        int $quantity,
        CarbonImmutable $serviceDate,
    ): void {
        if (! $product->is_active) {
            throw new UpsellUnavailableException('That extra is no longer offered.');
        }

        if ($quantity < 1 || $quantity > (int) $product->max_quantity) {
            throw new UpsellUnavailableException(sprintf(
                'Between 1 and %d of that extra can be ordered.',
                (int) $product->max_quantity,
            ));
        }

        if (! $product->canBeOrderedFor($serviceDate)) {
            throw new UpsellUnavailableException(sprintf(
                '%s must be arranged at least %d hour(s) ahead.',
                $product->name,
                (int) $product->lead_time_hours,
            ));
        }

        // A finite service — one driver, one van — sold twice for the same day
        // is a guest left at an airport.
        if ($product->daily_capacity !== null) {
            $taken = UpsellOrder::query()
                ->where('upsell_product_id', $product->getKey())
                ->where('service_date', $serviceDate->toDateString())
                ->chargeable()
                ->sum('quantity');

            if ($taken + $quantity > (int) $product->daily_capacity) {
                throw new UpsellUnavailableException(sprintf(
                    '%s is fully booked on %s.',
                    $product->name,
                    $serviceDate->toDateString(),
                ));
            }
        }
    }

    /**
     * When an extra happens if nobody says.
     *
     * Arrival for most things, because that is when a guest is thinking about
     * them. A late checkout is the exception and names its own date.
     */
    private function defaultServiceDate(Reservation $reservation): CarbonImmutable
    {
        return CarbonImmutable::parse($reservation->check_in_date)->setTime(15, 0);
    }
}
