<?php

declare(strict_types=1);

namespace App\Domain\Upsells\Models;

use App\Domain\Operations\Models\Task;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a guest actually ordered.
 *
 * The price is copied onto the order rather than read from the product when
 * somebody fulfils it. A guest who ordered a transfer at 40 pays 40, whatever
 * the product costs by the time somebody drives them — and a price that could
 * change between the order and the invoice is a price nobody agreed to.
 */
class UpsellOrder extends BaseModel
{
    use Auditable, BelongsToOrganization, HasFactory;

    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    public const FULFILLED = 'fulfilled';

    public const CANCELLED = 'cancelled';

    public const REFUNDED = 'refunded';

    protected $fillable = [
        'organization_id', 'upsell_product_id', 'reservation_id', 'reference',
        'quantity', 'unit_price', 'total_price', 'currency', 'status',
        'service_date', 'guest_notes', 'internal_notes', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'total_price' => 'integer',
            'service_date' => 'immutable_date',
            'approved_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'fulfilled_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    protected $attributes = [
        'status' => self::REQUESTED,
        'quantity' => 1,
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(UpsellProduct::class, 'upsell_product_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Orders somebody still has to decide about.
     */
    public function scopeAwaitingDecision(Builder $query): Builder
    {
        return $query->where('status', self::REQUESTED);
    }

    /**
     * Orders that have been agreed and not yet delivered.
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [self::REQUESTED, self::APPROVED]);
    }

    public function scopeChargeable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::APPROVED, self::FULFILLED]);
    }

    public function totalPrice(): Money
    {
        return Money::of((int) $this->total_price, $this->currency);
    }

    public function unitPrice(): Money
    {
        return Money::of((int) $this->unit_price, $this->currency);
    }

    /**
     * Whether the guest is being charged for this.
     *
     * A declined or cancelled order is not. This is the question the
     * reservation total asks, and getting it wrong bills somebody for a
     * transfer that never happened.
     */
    public function isChargeable(): bool
    {
        return in_array($this->status, [self::APPROVED, self::FULFILLED], true);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [
            self::FULFILLED, self::DECLINED, self::CANCELLED, self::REFUNDED,
        ], true);
    }
}
