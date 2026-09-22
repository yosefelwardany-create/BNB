<?php

declare(strict_types=1);

namespace App\Domain\OwnerAccounting\Models;

use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an owner statement.
 *
 * Amounts are signed: positive adds to what the owner is due, negative takes
 * away. That means the statement sums to its own total with no rules to
 * remember — which is exactly what an owner checks first, and what makes a
 * disagreement about the total resolvable by pointing at a line.
 */
class OwnerStatementLine extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const REVENUE = 'revenue';

    public const FEE = 'fee';

    public const TAX = 'tax';

    public const COMMISSION = 'commission';

    public const MANAGEMENT_FEE = 'management_fee';

    public const EXPENSE = 'expense';

    public const ADJUSTMENT = 'adjustment';

    public const RESERVE = 'reserve';

    public const OPENING_BALANCE = 'opening_balance';

    protected $fillable = [
        'organization_id', 'owner_statement_id', 'property_id', 'reservation_id',
        'expense_id', 'category', 'line_date', 'description', 'explanation',
        'amount', 'currency', 'ownership_percentage', 'full_amount',
        'sort_order', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'line_date' => 'immutable_date',
            'amount' => 'integer',
            'full_amount' => 'integer',
            'ownership_percentage' => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    protected $attributes = ['ownership_percentage' => 100, 'sort_order' => 0];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(OwnerStatement::class, 'owner_statement_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function amount(): Money
    {
        return Money::of((int) $this->amount, $this->currency);
    }

    /**
     * The whole figure before the owner's share was applied.
     *
     * Shown alongside the share on a jointly-owned property, so a co-owner can
     * see both what the property earned and what portion is theirs.
     */
    public function fullAmount(): ?Money
    {
        return $this->full_amount === null
            ? null
            : Money::of((int) $this->full_amount, $this->currency);
    }

    public function isDeduction(): bool
    {
        return $this->amount()->isNegative();
    }
}
