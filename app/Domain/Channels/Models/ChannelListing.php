<?php

declare(strict_types=1);

namespace App\Domain\Channels\Models;

use App\Domain\Availability\Events\CalendarDaysChanged;
use App\Domain\Channels\Jobs\PushChannelChanges;
use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Models\Property;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            'dirty_ranges' => 'array',
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

    /**
     * Mappings a full sync restates: switched on, and working or refused
     * before. A full sync is how a refused one is tried again once its
     * cause is put right; one paused on purpose stays paused.
     */
    public function scopeRestatable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereIn('status', [self::STATUS_MAPPED, self::STATUS_PUBLISHED, self::STATUS_ERROR]);
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
     * the first's bounds. A push for the connection is queued a few seconds
     * out ({@see PushChannelChanges}), so changes made together travel
     * together.
     *
     * @param  list<string>|null  $fields  The rate rules a calendar edit set
     *                                     ({@see CalendarDaysChanged::ariFields()});
     *                                     null when every rule may have moved.
     */
    public function markDirty(string $what, ?string $from = null, ?string $to = null, ?array $fields = null): void
    {
        $updates = match ($what) {
            'availability' => ['availability_dirty' => true],
            'rates' => ['rates_dirty' => true],
            default => ['availability_dirty' => true, 'rates_dirty' => true],
        };

        DB::transaction(function () use ($what, $from, $to, $fields, &$updates): void {
            // What this change touched, kept beside the window so that a
            // channel sent only what changed is sent this change and not the
            // nights between it and another. Read under a lock, so that two
            // changes made at once are both kept.
            $ranges = $this->freshRanges(lock: true);

            foreach ($what === 'both' ? ['availability', 'rates'] : [$what] as $kind) {
                $ranges[] = [
                    'id' => (string) Str::ulid(),
                    'kind' => $kind,
                    'from' => $from,
                    'to' => $to,
                    'fields' => $kind === 'rates' && $fields !== null ? array_values(array_unique($fields)) : null,
                ];
            }

            $updates['dirty_ranges'] = self::compact($ranges);

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
        });

        // Sent within seconds, not at the next five-minute run.
        PushChannelChanges::soon($this->channel_account_id);
    }

    /**
     * The changes still to be pushed for one kind, as stored now.
     *
     * Read from the database rather than this instance, which may have been
     * loaded before a change made while a push was on its way.
     *
     * @return list<array{id: string, kind: string, from: ?string, to: ?string, fields: ?list<string>}>
     */
    public function pendingRanges(string $kind): array
    {
        return array_values(array_filter($this->freshRanges(), fn (array $range): bool => $range['kind'] === $kind));
    }

    /**
     * Forget the changes a push has delivered, and say how many of that kind
     * are still to go: one made while the push was on its way stays.
     *
     * @param  list<string>  $ids
     */
    public function forgetRanges(string $kind, array $ids): int
    {
        return DB::transaction(function () use ($kind, $ids): int {
            $ranges = array_values(array_filter($this->freshRanges(lock: true), fn (array $range): bool => ! in_array($range['id'], $ids, true)));

            $this->forceFill(['dirty_ranges' => $ranges === [] ? null : $ranges])->saveQuietly();

            return count(array_filter($ranges, fn (array $range): bool => $range['kind'] === $kind));
        });
    }

    /**
     * @return list<array{id: string, kind: string, from: ?string, to: ?string, fields: ?list<string>}>
     */
    private function freshRanges(bool $lock = false): array
    {
        if (! $this->exists) {
            return array_values($this->dirty_ranges ?? []);
        }

        $query = static::query()->withoutGlobalScopes()->whereKey($this->getKey());
        $stored = ($lock ? $query->lockForUpdate() : $query)->value('dirty_ranges');
        $ranges = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($ranges) ? array_values($ranges) : [];
    }

    /**
     * Keep the list short while a channel is down: past fifty changes of one
     * kind, they become one covering them all.
     *
     * @param  list<array{id: string, kind: string, from: ?string, to: ?string, fields: ?list<string>}>  $ranges
     * @return list<array{id: string, kind: string, from: ?string, to: ?string, fields: ?list<string>}>
     */
    private static function compact(array $ranges): array
    {
        foreach (['availability', 'rates'] as $kind) {
            $mine = array_values(array_filter($ranges, fn (array $range): bool => $range['kind'] === $kind));

            if (count($mine) <= 50) {
                continue;
            }

            $unbounded = fn (string $end): bool => in_array(null, array_column($mine, $end), true);
            $fields = in_array(null, array_column($mine, 'fields'), true)
                ? null
                : array_values(array_unique(array_merge(...array_column($mine, 'fields'))));

            $ranges = array_values(array_filter($ranges, fn (array $range): bool => $range['kind'] !== $kind));
            $ranges[] = [
                'id' => (string) Str::ulid(),
                'kind' => $kind,
                'from' => $unbounded('from') ? null : min(array_column($mine, 'from')),
                'to' => $unbounded('to') ? null : max(array_column($mine, 'to')),
                'fields' => $fields,
            ];
        }

        return $ranges;
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
