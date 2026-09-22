<?php

declare(strict_types=1);

namespace App\Domain\Locks\Models;

use App\Domain\Reservations\Models\Reservation;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * A key.
 *
 * Encrypted at rest, because that is what this is: a database disclosure
 * should not be a set of working codes to every door in the portfolio. It is
 * hidden on the model and revealed only where somebody is entitled to it —
 * the guest whose stay it belongs to, and staff with the lock permission.
 *
 * `is_simulated` is the most important column in this table. A code that
 * exists only here, because no live lock provider is configured, must never be
 * reported as issued: sending it to a guest produces somebody standing outside
 * a door that has never heard of them.
 */
class AccessCode extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    public const FAILED = 'failed';

    public const GUEST = 'guest';

    public const CLEANER = 'cleaner';

    public const MAINTENANCE = 'maintenance';

    protected $fillable = [
        'organization_id', 'smart_lock_id', 'reservation_id', 'code',
        'external_code_id', 'purpose', 'valid_from', 'valid_until',
        'status', 'is_simulated', 'metadata',
    ];

    protected $hidden = ['code'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'is_simulated' => 'boolean',
            'use_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected $attributes = [
        'purpose' => self::GUEST,
        'status' => self::PENDING,
        'is_simulated' => false,
        'use_count' => 0,
    ];

    protected static function booted(): void
    {
        // The last digits are kept in clear so a code can be identified in a
        // list — "the one ending 4417" — without decrypting every row, and
        // without the list itself being a set of keys.
        static::saving(function (AccessCode $code): void {
            $plain = $code->code;

            $code->code_last4 = blank($plain) ? null : substr((string) $plain, -4);
        });
    }

    public function lock(): BelongsTo
    {
        return $this->belongsTo(SmartLock::class, 'smart_lock_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * The code itself, encrypted at rest.
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : Crypt::decryptString($value),
            set: fn (?string $value): ?string => $value === null ? null : Crypt::encryptString($value),
        );
    }

    public function scopeUsable(Builder $query, ?string $at = null): Builder
    {
        $at ??= now();

        return $query->where('status', self::ACTIVE)
            ->where('valid_from', '<=', $at)
            ->where('valid_until', '>', $at);
    }

    /**
     * Codes whose window has passed but which are still marked active.
     *
     * A sweep works from this. A lock has a finite number of code slots, and
     * an expired code still occupying one is why the next guest's code cannot
     * be issued.
     */
    public function scopeStale(Builder $query, ?string $at = null): Builder
    {
        return $query->where('status', self::ACTIVE)
            ->where('valid_until', '<=', $at ?? now());
    }

    /**
     * Codes that should be programmed soon.
     */
    public function scopeDueForIssue(Builder $query, ?string $before = null): Builder
    {
        return $query->where('status', self::PENDING)
            ->where('valid_from', '<=', $before ?? now()->addDay());
    }

    public function isUsable(): bool
    {
        return $this->status === self::ACTIVE
            && $this->valid_from?->isPast()
            && $this->valid_until?->isFuture();
    }

    public function hasExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    /**
     * Whether this code will actually open a door.
     *
     * False for a simulated code however active it looks, because the flag is
     * the difference between a guest with a key and a guest with a number.
     */
    public function opensARealDoor(): bool
    {
        return $this->isUsable() && ! $this->is_simulated;
    }
}
