<?php

declare(strict_types=1);

namespace App\Domain\Reservations\Models;

use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single non-accommodation line on a reservation.
 *
 * Discounts are stored as negative amounts so that summing every line always
 * yields the total; nothing has to remember which signs to flip.
 *
 * `calculation_trace` records the inputs and the rule that produced the line,
 * which is what makes a tax or fee explainable to a guest, an owner or an
 * auditor.
 */
class ReservationCharge extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const KIND_FEE = 'fee';

    public const KIND_TAX = 'tax';

    public const KIND_DISCOUNT = 'discount';

    public const KIND_UPSELL = 'upsell';

    public const KIND_DAMAGE = 'damage';

    public const KIND_ADJUSTMENT = 'adjustment';

    public const KIND_COMMISSION = 'commission';

    public const KIND_DEPOSIT = 'deposit';

    public const ORIGIN_SYSTEM = 'system';

    public const ORIGIN_MANUAL = 'manual';

    public const ORIGIN_CHANNEL = 'channel';

    protected $fillable = [
        'organization_id',
        'reservation_id',
        'kind',
        'code',
        'label',
        'description',
        'quantity',
        'unit_amount',
        'amount',
        'currency',
        'is_taxable',
        'tax_rule_id',
        'fee_rule_id',
        'promotion_id',
        'upsell_order_id',
        'is_refundable',
        'origin',
        'calculation_trace',
        'metadata',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'is_taxable' => 'boolean',
            'is_refundable' => 'boolean',
            'calculation_trace' => 'array',
            'metadata' => 'array',
        ];
    }

    protected $attributes = [
        'quantity' => 1,
        'is_taxable' => false,
        'is_refundable' => true,
        'origin' => self::ORIGIN_SYSTEM,
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function amount(): Money
    {
        return Money::of((int) $this->amount, $this->currency);
    }

    public function scopeOfKind(Builder $query, string|array $kind): Builder
    {
        return $query->whereIn('kind', (array) $kind);
    }

    public function scopeTaxable(Builder $query): Builder
    {
        return $query->where('is_taxable', true);
    }

    /** Lines that count towards a refund calculation. */
    public function scopeRefundable(Builder $query): Builder
    {
        return $query->where('is_refundable', true);
    }
}
