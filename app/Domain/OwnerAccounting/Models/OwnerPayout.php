<?php

declare(strict_types=1);

namespace App\Domain\OwnerAccounting\Models;

use App\Domain\Owners\Models\Owner;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money actually sent to an owner.
 *
 * `destination_snapshot` holds the banking details as they stood when the
 * payout was made. An owner who changes account next year must not be able to
 * rewrite where last year's money went — that record is the answer to "you
 * never paid me", and it has to be fixed.
 */
class OwnerPayout extends BaseModel
{
    use Auditable, BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'owner_id', 'owner_statement_id', 'reference',
        'amount', 'currency', 'scheduled_for', 'status', 'method',
        'external_reference', 'destination_snapshot', 'notes', 'metadata', 'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'scheduled_for' => 'immutable_date',
            'paid_at' => 'immutable_datetime',
            'destination_snapshot' => 'array',
            'metadata' => 'array',
        ];
    }

    protected $attributes = ['status' => 'pending'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(OwnerStatement::class, 'owner_statement_id');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'approved', 'processing']);
    }

    public function amount(): Money
    {
        return Money::of((int) $this->amount, $this->currency);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
