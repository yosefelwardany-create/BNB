<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the platform needs to tell its tenants.
 *
 * Shown inside the product rather than emailed, because the people who need to
 * know that the channel sync will be down on Sunday are the people looking at
 * the channel screen, not whoever reads the billing inbox.
 *
 * Platform-owned, and deliberately not tenant-scoped: an announcement chooses
 * its audience, which is the opposite of being filtered by one.
 */
class PlatformAnnouncement extends BaseModel
{
    use Auditable;

    public const LEVEL_INFO = 'info';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_CRITICAL = 'critical';

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_TRIAL = 'trial';

    public const AUDIENCE_ACTIVE = 'active';

    public const AUDIENCE_PAST_DUE = 'past_due';

    public const AUDIENCE_SPECIFIC = 'specific';

    protected $table = 'platform_announcements';

    protected $fillable = [
        'title', 'body', 'level', 'audience', 'organization_ids',
        'starts_at', 'ends_at', 'is_published', 'is_dismissible',
    ];

    protected function casts(): array
    {
        return [
            'organization_ids' => 'array',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'is_published' => 'boolean',
            'is_dismissible' => 'boolean',
        ];
    }

    protected $attributes = [
        'level' => self::LEVEL_INFO,
        'audience' => self::AUDIENCE_ALL,
        'is_published' => false,
        'is_dismissible' => true,
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Published and inside its window.
     *
     * A null start means "already begun" and a null end means "until further
     * notice", so an operator writing a notice for right now does not have to
     * fill in two dates to say so.
     */
    public function scopeLive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_published', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    /**
     * Whether this announcement is for a particular tenant.
     *
     * Evaluated here rather than in a query, because the audience rules are
     * about the organization's current status and are cheap to check once the
     * live set is small.
     */
    public function appliesTo(Organization $organization): bool
    {
        return match ($this->audience) {
            self::AUDIENCE_ALL => true,
            self::AUDIENCE_SPECIFIC => in_array(
                $organization->getKey(),
                $this->organization_ids ?? [],
                true,
            ),
            self::AUDIENCE_TRIAL => $organization->status->value === 'trial',
            self::AUDIENCE_ACTIVE => $organization->status->value === 'active',
            self::AUDIENCE_PAST_DUE => $organization->status->value === 'past_due',
            default => false,
        };
    }

    public function isLive(): bool
    {
        if (! $this->is_published) {
            return false;
        }

        $now = now();

        return ($this->starts_at === null || $this->starts_at->lte($now))
            && ($this->ends_at === null || $this->ends_at->gte($now));
    }
}
