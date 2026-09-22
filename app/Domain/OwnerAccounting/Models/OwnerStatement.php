<?php

declare(strict_types=1);

namespace App\Domain\OwnerAccounting\Models;

use App\Domain\Owners\Models\Owner;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What an owner earned in a period, what was taken out, and what is owed.
 *
 * The most scrutinised document this product produces. Owners read it line by
 * line, and a disagreement about one line is a disagreement about the whole
 * relationship — so once approved it is frozen, and every figure on it is
 * stored rather than recomputed.
 *
 * Recomputing on read would mean a statement sent in March could quietly say
 * something different in June, after a backdated expense or a corrected rate.
 * The owner would be right to stop trusting all of them.
 */
class OwnerStatement extends BaseModel
{
    use Auditable, BelongsToOrganization, HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SENT = 'sent';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'organization_id', 'owner_id', 'property_id', 'reference',
        'period_start', 'period_end', 'currency',
        'gross_revenue', 'accommodation_revenue', 'fee_revenue', 'taxes_collected',
        'channel_commission', 'payment_fees', 'management_fee',
        'expenses_total', 'adjustments_total',
        'net_due', 'reserve_withheld', 'opening_balance', 'closing_balance',
        'payout_amount', 'nights_sold', 'reservations_count',
        'status', 'agreement_snapshot', 'notes', 'document_path', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'approved_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'agreement_snapshot' => 'array',
            'metadata' => 'array',
        ];
    }

    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected static function booted(): void
    {
        static::updating(function (OwnerStatement $statement): void {
            $original = $statement->getOriginal('status');

            // A draft can be rebuilt freely. Anything the owner has seen is
            // fixed: its status and delivery timestamps can move forward, its
            // figures cannot.
            if ($original === self::STATUS_DRAFT || $original === null) {
                return;
            }

            $frozen = array_intersect(array_keys($statement->getDirty()), [
                'gross_revenue', 'accommodation_revenue', 'fee_revenue', 'taxes_collected',
                'channel_commission', 'payment_fees', 'management_fee',
                'expenses_total', 'adjustments_total', 'net_due',
                'reserve_withheld', 'opening_balance', 'closing_balance', 'payout_amount',
                'period_start', 'period_end', 'agreement_snapshot',
            ]);

            if ($frozen !== []) {
                throw new \RuntimeException(sprintf(
                    'Statement %s has been approved and its figures cannot change. Void it and issue a new one.',
                    $statement->reference,
                ));
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OwnerStatementLine::class)->orderBy('sort_order')->orderBy('line_date');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(OwnerPayout::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /** Statements the owner has seen; their figures are fixed. */
    public function scopeIssued(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_SENT, self::STATUS_PAID]);
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function grossRevenue(): Money
    {
        return Money::of((int) $this->gross_revenue, $this->currency);
    }

    public function managementFee(): Money
    {
        return Money::of((int) $this->management_fee, $this->currency);
    }

    public function expensesTotal(): Money
    {
        return Money::of((int) $this->expenses_total, $this->currency);
    }

    public function netDue(): Money
    {
        return Money::of((int) $this->net_due, $this->currency);
    }

    public function payoutAmount(): Money
    {
        return Money::of((int) $this->payout_amount, $this->currency);
    }

    public function closingBalance(): Money
    {
        return Money::of((int) $this->closing_balance, $this->currency);
    }

    /**
     * Whether the period ended owing the manager money rather than the owner.
     *
     * Happens when expenses exceed revenue — a quiet month with a broken
     * boiler — and is carried into the next statement rather than written off.
     */
    public function isInDeficit(): bool
    {
        return $this->closingBalance()->isNegative();
    }
}
