<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A balanced set of journal lines describing one financial event.
 *
 * Once posted an entry is immutable. A mistake is corrected by posting a
 * reversing entry, which keeps the history of what was believed at the time —
 * the property that makes a ledger auditable.
 *
 * @property string $reference
 * @property string $status
 */
class JournalEntry extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'organization_id',
        'reference',
        'entry_date',
        'description',
        'source',
        'source_type',
        'source_id',
        'currency',
        'status',
        'posted_at',
        'posted_by_id',
        'reverses_entry_id',
        'reversed_by_entry_id',
        'property_id',
        'owner_id',
        'reservation_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'posted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected static function booted(): void
    {
        static::updating(function (JournalEntry $entry): void {
            // A posted entry may only be marked as reversed; nothing else
            // about it can change.
            if ($entry->getOriginal('status') !== self::STATUS_POSTED) {
                return;
            }

            $changed = array_keys($entry->getDirty());
            $allowed = ['reversed_by_entry_id', 'status', 'updated_at'];

            if (array_diff($changed, $allowed) !== []) {
                throw new \RuntimeException(sprintf(
                    'Journal entry %s is posted and cannot be modified. Post a reversing entry instead.',
                    $entry->reference,
                ));
            }
        });

        static::deleting(function (JournalEntry $entry): void {
            if ($entry->status === self::STATUS_POSTED) {
                throw new \RuntimeException(sprintf(
                    'Journal entry %s is posted and cannot be deleted.',
                    $entry->reference,
                ));
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo('source');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isReversed(): bool
    {
        return $this->reversed_by_entry_id !== null;
    }

    /**
     * Total debits, which by construction equals total credits.
     */
    public function total(): Money
    {
        $sum = 0;

        foreach ($this->lines as $line) {
            $sum += (int) $line->debit;
        }

        return Money::of($sum, $this->currency);
    }

    /**
     * Whether debits equal credits. Enforced before posting.
     */
    public function isBalanced(): bool
    {
        $debits = 0;
        $credits = 0;

        foreach ($this->lines as $line) {
            $debits += (int) $line->debit;
            $credits += (int) $line->credit;
        }

        return $debits === $credits;
    }
}
