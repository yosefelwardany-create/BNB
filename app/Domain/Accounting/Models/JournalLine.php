<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of a journal entry.
 *
 * Amounts are integer minor units, and exactly one of `debit`/`credit` is
 * non-zero (enforced by a database check constraint).
 *
 * The `base_*` columns carry the same amount converted into the
 * organization's reporting currency together with the rate used. The
 * transaction currency figures are never overwritten by a later rate change.
 */
class JournalLine extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'journal_entry_id',
        'ledger_account_id',
        'debit',
        'credit',
        'currency',
        'base_debit',
        'base_credit',
        'base_currency',
        'exchange_rate',
        'memo',
        'property_id',
        'owner_id',
        'reservation_id',
        'unit_id',
        'owner_statement_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'integer',
            'credit' => 'integer',
            'base_debit' => 'integer',
            'base_credit' => 'integer',
            'exchange_rate' => 'decimal:10',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (JournalLine $line): void {
            // Statement assignment is the only mutation a line ever receives.
            $changed = array_keys($line->getDirty());
            $allowed = ['owner_statement_id', 'updated_at'];

            if (array_diff($changed, $allowed) !== []) {
                throw new \RuntimeException('Journal lines are immutable once written.');
            }
        });
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function amount(): Money
    {
        return Money::of((int) ($this->debit ?: $this->credit), $this->currency);
    }

    public function baseAmount(): Money
    {
        return Money::of((int) ($this->base_debit ?: $this->base_credit), $this->base_currency);
    }

    public function isDebit(): bool
    {
        return (int) $this->debit !== 0;
    }

    /**
     * Signed amount in minor units: positive for debits, negative for credits.
     */
    public function signedMinorUnits(): int
    {
        return (int) $this->debit - (int) $this->credit;
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->whereHas('entry', fn (Builder $q) => $q->where('status', JournalEntry::STATUS_POSTED));
    }

    public function scopeForOwner(Builder $query, string $ownerId): Builder
    {
        return $query->where('owner_id', $ownerId);
    }

    public function scopeUnstatemented(Builder $query): Builder
    {
        return $query->whereNull('owner_statement_id');
    }
}
