<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Reservations\Models\Reservation;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money given back.
 *
 * Its own record rather than a negative payment, so that "how much did we
 * take" and "how much did we return" stay separately answerable. A netted
 * figure loses the second question entirely, and it is the one a guest
 * disputing a charge is asking.
 */
class Refund extends BaseModel
{
    use Auditable, BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'payment_id', 'reservation_id', 'reference',
        'amount', 'currency', 'status', 'reason', 'notes',
        'provider', 'provider_reference', 'is_simulated',
        'metadata', 'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'is_simulated' => 'boolean',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    protected $attributes = ['status' => 'pending', 'is_simulated' => false];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function amount(): Money
    {
        return Money::of((int) $this->amount, $this->currency);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
