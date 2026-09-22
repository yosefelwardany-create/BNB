<?php

declare(strict_types=1);

namespace App\Domain\Availability\Models;

use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\Unit;
use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Anything that occupies inventory without being a reservation.
 *
 * Owner stays, maintenance closures, manual holds and blocks imported from an
 * external calendar all take nights off the market and must therefore be
 * visible to the availability engine exactly as reservations are. Modelling
 * them separately (rather than as fake reservations) keeps revenue reporting
 * honest: an owner stay is not a booking and must never appear as one.
 *
 * `end_date` is exclusive, matching reservations.
 */
class CalendarBlock extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const KIND_OWNER_STAY = 'owner_stay';

    public const KIND_MAINTENANCE = 'maintenance';

    public const KIND_CLEANING = 'cleaning';

    public const KIND_MANUAL = 'manual';

    public const KIND_EXTERNAL = 'external';

    public const KIND_HOLD = 'hold';

    public const KIND_RENOVATION = 'renovation';

    protected $fillable = [
        'organization_id',
        'property_id',
        'unit_id',
        'listing_id',
        'kind',
        'start_date',
        'end_date',
        'title',
        'notes',
        'owner_id',
        'task_id',
        'source',
        'external_id',
        'channel_account_id',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
        ];
    }

    protected $attributes = [
        'source' => 'manual',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Blocks overlapping a half-open date range.
     */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->where('start_date', '<', $to)->where('end_date', '>', $from);
    }

    public function scopeOfKind(Builder $query, string|array $kind): Builder
    {
        return $query->whereIn('kind', (array) $kind);
    }

    /**
     * @return list<string>
     */
    public function nightDates(): array
    {
        $dates = [];

        foreach (CarbonPeriod::create($this->start_date, '1 day', $this->end_date->subDay()) as $date) {
            $dates[] = $date->format('Y-m-d');
        }

        return $dates;
    }

    public function nights(): int
    {
        return (int) $this->start_date->diffInDays($this->end_date);
    }

    /**
     * Whether the block represents revenue-relevant owner use, which owner
     * statements report separately from lost revenue.
     */
    public function isOwnerStay(): bool
    {
        return $this->kind === self::KIND_OWNER_STAY;
    }

    public function label(): string
    {
        return $this->title ?: match ($this->kind) {
            self::KIND_OWNER_STAY => 'Owner stay',
            self::KIND_MAINTENANCE => 'Maintenance',
            self::KIND_CLEANING => 'Cleaning',
            self::KIND_RENOVATION => 'Renovation',
            self::KIND_EXTERNAL => 'Blocked on another channel',
            self::KIND_HOLD => 'Held',
            default => 'Blocked',
        };
    }
}
