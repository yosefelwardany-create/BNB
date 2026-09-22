<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Operations\Models\Task;
use App\Domain\Operations\Models\Vendor;
use App\Domain\Owners\Models\Owner;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\Unit;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A cost incurred against a property.
 *
 * `billable_to` is the field that matters most on this record: it decides
 * whether the cost lands on an owner's statement, is recharged to a guest, or
 * stays with the manager. Get it wrong and somebody is silently billed for
 * work they never agreed to pay for.
 *
 * `owner_statement_id` is the other load-bearing column. It is set when the
 * expense is swept into a *finalised* statement, and its presence is what
 * makes double-billing impossible: a statement rebuild only ever picks up
 * expenses where it is null.
 */
class Expense extends BaseModel
{
    use Auditable, BelongsToOrganization, HasFactory, SoftDeletes;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const PAID = 'paid';

    /** Who ultimately bears the cost. */
    public const TO_OWNER = 'owner';

    public const TO_GUEST = 'guest';

    public const TO_MANAGER = 'manager';

    protected $fillable = [
        'organization_id', 'reference',
        'property_id', 'unit_id', 'owner_id', 'task_id', 'vendor_id',
        'expense_date', 'category', 'description',
        'amount', 'tax_amount', 'currency',
        'billable_to', 'markup_percent', 'markup_amount',
        'status', 'payment_method', 'receipt_path', 'notes', 'metadata',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'immutable_date',
            'amount' => 'integer',
            'tax_amount' => 'integer',
            'markup_amount' => 'integer',
            'markup_percent' => 'decimal:4',
            'is_paid' => 'boolean',
            'approved_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    protected $attributes = [
        'status' => self::DRAFT,
        'billable_to' => self::TO_MANAGER,
        'tax_amount' => 0,
        'markup_amount' => 0,
        'markup_percent' => 0,
        'is_paid' => false,
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function scopeBillableToOwner(Builder $query): Builder
    {
        return $query->where('billable_to', self::TO_OWNER);
    }

    /**
     * Costs a statement may still pick up.
     *
     * Approved, because a draft cost is somebody's unverified claim; and not
     * already consumed, because an expense belongs to exactly one statement.
     */
    public function scopeAvailableForStatement(Builder $query): Builder
    {
        return $query->billableToOwner()
            ->whereIn('status', [self::APPROVED, self::PAID])
            ->whereNull('owner_statement_id');
    }

    public function scopeInPeriod(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('expense_date', [$from, $to]);
    }

    // ------------------------------------------------------------------
    // Money
    // ------------------------------------------------------------------

    public function amount(): Money
    {
        return Money::of((int) $this->amount, $this->currency);
    }

    public function taxAmount(): Money
    {
        return Money::of((int) $this->tax_amount, $this->currency);
    }

    public function markupAmount(): Money
    {
        return Money::of((int) $this->markup_amount, $this->currency);
    }

    /**
     * What the bearer is actually charged.
     *
     * The underlying cost plus tax plus whatever handling margin was agreed.
     * The three stay separate on the record so an owner can always see the
     * contractor's real price underneath the manager's margin.
     */
    public function chargeableAmount(): Money
    {
        return $this->amount()->add($this->taxAmount())->add($this->markupAmount());
    }

    public function isEditable(): bool
    {
        // Once swept into a finalised statement the figures are an owner's
        // evidence, not a working draft.
        return $this->owner_statement_id === null
            && in_array($this->status, [self::DRAFT, self::APPROVED], true);
    }

    public function isApproved(): bool
    {
        return in_array($this->status, [self::APPROVED, self::PAID], true);
    }
}
