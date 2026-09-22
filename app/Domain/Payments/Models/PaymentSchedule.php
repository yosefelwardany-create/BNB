<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Reservations\Models\Reservation;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When money is due on a booking.
 *
 * The plan, kept separate from the payments that happen against it. A guest
 * who pays early, late, or in an instalment nobody planned for does not
 * corrupt the schedule, and the schedule can be renegotiated without
 * rewriting what was actually received.
 */
class PaymentSchedule extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'reservation_id', 'sequence', 'label',
        'amount', 'currency', 'due_on', 'status', 'paid_amount',
        'auto_charge', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_amount' => 'integer',
            'due_on' => 'immutable_date',
            'auto_charge' => 'boolean',
            'last_attempt_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    protected $attributes = [
        'status' => 'pending',
        'paid_amount' => 0,
        'auto_charge' => false,
        'attempts' => 0,
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'partially_paid', 'overdue', 'failed']);
    }

    /**
     * Instalments the scheduler should try to take.
     *
     * Only those marked auto_charge: taking a guest's money automatically is
     * something they agreed to at booking, never a default.
     */
    public function scopeDueForCharge(Builder $query, ?string $on = null): Builder
    {
        return $query->outstanding()
            ->where('auto_charge', true)
            ->where('due_on', '<=', $on ?? now()->toDateString());
    }

    public function amount(): Money
    {
        return Money::of((int) $this->amount, $this->currency);
    }

    public function paidAmount(): Money
    {
        return Money::of((int) $this->paid_amount, $this->currency);
    }

    public function outstandingAmount(): Money
    {
        return $this->amount()->subtract($this->paidAmount());
    }

    public function isOverdue(): bool
    {
        return $this->outstandingAmount()->isPositive() && $this->due_on->isPast();
    }
}
