<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Guests\Models\Guest;
use App\Domain\Owners\Models\Owner;
use App\Domain\Payments\Enums\PaymentKind;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The platform's record of an attempt to move money.
 *
 * Deliberately not the processor's record. The processor is the authority on
 * whether money moved; this is the authority on what it was for, whose it was
 * and which booking it belongs to. Conflating the two makes reconciliation
 * impossible precisely when it matters — when they disagree.
 *
 * `is_collected_by_us` carries a fact that is easy to lose: a channel that
 * takes the guest's money itself produces a payment record here so revenue is
 * recognised, but no cash ever passed through us. Posting it as cash would
 * inflate the bank balance by every OTA booking.
 *
 * @property PaymentStatus $status
 * @property PaymentKind $kind
 */
class Payment extends BaseModel
{
    use Auditable, BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'reference',
        'reservation_id', 'guest_id', 'owner_id', 'invoice_id', 'property_id',
        'kind', 'direction', 'amount', 'currency',
        'base_amount', 'base_currency', 'exchange_rate', 'fee_amount',
        'status', 'method', 'provider', 'provider_reference', 'provider_status',
        'is_collected_by_us', 'is_simulated',
        'instrument_brand', 'instrument_last4', 'instrument_token',
        'description', 'metadata', 'created_by_id',
    ];

    protected $hidden = ['instrument_token'];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'kind' => PaymentKind::class,
            'amount' => 'integer',
            'base_amount' => 'integer',
            'fee_amount' => 'integer',
            'captured_amount' => 'integer',
            'refunded_amount' => 'integer',
            'exchange_rate' => 'decimal:10',
            'is_collected_by_us' => 'boolean',
            'is_simulated' => 'boolean',
            'authorized_at' => 'immutable_datetime',
            'captured_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    protected $attributes = [
        'kind' => 'booking',
        'direction' => 'inbound',
        'status' => 'pending',
        'fee_amount' => 0,
        'captured_amount' => 0,
        'refunded_amount' => 0,
        'is_collected_by_us' => true,
        'is_simulated' => false,
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function scopeCaptured(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PaymentStatus::Captured->value,
            PaymentStatus::PartiallyRefunded->value,
            PaymentStatus::Refunded->value,
        ]);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PaymentStatus::Pending->value,
            PaymentStatus::RequiresAction->value,
            PaymentStatus::Authorized->value,
        ]);
    }

    /** Money the platform actually received, as opposed to merely recorded. */
    public function scopeCollectedByUs(Builder $query): Builder
    {
        return $query->where('is_collected_by_us', true);
    }

    // ------------------------------------------------------------------
    // Money
    // ------------------------------------------------------------------

    public function amount(): Money
    {
        return Money::of((int) $this->amount, $this->currency);
    }

    public function capturedAmount(): Money
    {
        return Money::of((int) $this->captured_amount, $this->currency);
    }

    public function refundedAmount(): Money
    {
        return Money::of((int) $this->refunded_amount, $this->currency);
    }

    public function feeAmount(): Money
    {
        return Money::of((int) $this->fee_amount, $this->currency);
    }

    /** What is left on the card after everything already taken. */
    public function capturableAmount(): Money
    {
        return $this->amount()->subtract($this->capturedAmount());
    }

    /** What could still be given back. */
    public function refundableAmount(): Money
    {
        return $this->capturedAmount()->subtract($this->refundedAmount());
    }

    /** Gross less what the processor kept. */
    public function netAmount(): Money
    {
        return $this->capturedAmount()->subtract($this->feeAmount());
    }

    public function isFullyRefunded(): bool
    {
        return $this->capturedAmount()->isPositive()
            && $this->refundedAmount()->equals($this->capturedAmount());
    }
}
