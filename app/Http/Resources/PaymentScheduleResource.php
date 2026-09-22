<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Payments\Models\PaymentSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentSchedule
 */
class PaymentScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'sequence' => (int) $this->sequence,
            'label' => $this->label,

            'amount' => $this->amount()->jsonSerialize(),
            'paid_amount' => $this->paidAmount()->jsonSerialize(),
            'outstanding_amount' => $this->outstandingAmount()->jsonSerialize(),
            'currency' => $this->currency,

            'due_on' => $this->due_on?->toDateString(),
            'status' => $this->status,

            // Derived rather than stored: a schedule becomes overdue by the
            // passage of time, and a status column would only be as current as
            // the last job that swept it.
            'is_overdue' => $this->isOverdue(),

            // Taking a guest's money automatically is something they agreed to
            // at booking, so it is reported explicitly rather than assumed.
            'auto_charge' => (bool) $this->auto_charge,
            'attempts' => (int) $this->attempts,
            'last_attempt_at' => $this->last_attempt_at?->toIso8601String(),
            'last_failure_message' => $this->last_failure_message,

            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
