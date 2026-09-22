<?php

declare(strict_types=1);

namespace App\Domain\Channels\Models;

use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Models\Property;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The mapping between one of our listings and one on a channel.
 *
 * Carries both what we intend and what we last successfully pushed. Keeping
 * them apart is the point: the gap between the two is why a flat is still
 * bookable on an OTA an hour after we closed it, and a design that stored only
 * the intent could not tell you that the gap existed.
 */
class ChannelListing extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const STATUS_MAPPED = 'mapped';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ERROR = 'error';

    public const STATUS_PAUSED = 'paused';

    protected $fillable = [
        'organization_id', 'channel_account_id', 'listing_id', 'property_id', 'unit_type_id',
        'external_listing_id', 'external_name', 'external_url',
        'status', 'is_active', 'rate_adjustment_basis_points', 'commission_basis_points',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'availability_dirty' => 'boolean',
            'rates_dirty' => 'boolean',
            'dirty_from' => 'immutable_date',
            'dirty_to' => 'immutable_date',
            'availability_pushed_at' => 'immutable_datetime',
            'rates_pushed_at' => 'immutable_datetime',
            'last_error_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    protected $attributes = [
        'status' => self::STATUS_MAPPED,
        'is_active' => true,
        'rate_adjustment_basis_points' => 0,
        'availability_dirty' => false,
        'rates_dirty' => false,
        'consecutive_failures' => 0,
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class, 'channel_account_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function syncJobs(): HasMany
    {
        return $this->hasMany(SyncJob::class)->latest();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereIn('status', [self::STATUS_MAPPED, self::STATUS_PUBLISHED]);
    }

    /** Mappings whose channel state is behind ours. */
    public function scopeDirty(Builder $query): Builder
    {
        return $query->active()
            ->where(fn (Builder $q) => $q->where('availability_dirty', true)->orWhere('rates_dirty', true));
    }

    /**
     * A mapping that has failed repeatedly.
     *
     * Surfaced rather than retried forever: after a few consecutive failures
     * the cause is almost never transient, and a queue quietly burning retries
     * is how a listing stays wrong for a week.
     */
    public function scopeFailing(Builder $query, int $threshold = 3): Builder
    {
        return $query->where('consecutive_failures', '>=', $threshold);
    }

    public function isFailing(int $threshold = 3): bool
    {
        return (int) $this->consecutive_failures >= $threshold;
    }

    /**
     * Mark that something changed locally and has not reached the channel.
     *
     * The dirty window widens rather than being replaced, so two changes in
     * different date ranges both get pushed instead of the second overwriting
     * the first's bounds.
     */
    public function markDirty(string $what, ?string $from = null, ?string $to = null): void
    {
        $updates = match ($what) {
            'availability' => ['availability_dirty' => true],
            'rates' => ['rates_dirty' => true],
            default => ['availability_dirty' => true, 'rates_dirty' => true],
        };

        if ($from !== null) {
            $updates['dirty_from'] = $this->dirty_from === null || $from < $this->dirty_from->toDateString()
                ? $from
                : $this->dirty_from->toDateString();
        }

        if ($to !== null) {
            $updates['dirty_to'] = $this->dirty_to === null || $to > $this->dirty_to->toDateString()
                ? $to
                : $this->dirty_to->toDateString();
        }

        $this->forceFill($updates)->save();
    }

    /**
     * The rate to publish, after this mapping's markup.
     */
    public function adjustedRate(int $minorUnits): int
    {
        if ($this->rate_adjustment_basis_points === 0) {
            return $minorUnits;
        }

        return (int) round($minorUnits * (1 + $this->rate_adjustment_basis_points / 10000));
    }

    /** This mapping's commission, falling back to the account's. */
    public function commissionBasisPoints(): int
    {
        return $this->commission_basis_points ?? $this->account?->commission_basis_points ?? 0;
    }
}
