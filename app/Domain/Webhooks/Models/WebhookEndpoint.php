<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Models;

use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Where domain events are sent.
 *
 * The signing secret is encrypted rather than hashed, because unlike an API
 * key it must be readable to compute a signature on every send. It is hidden
 * on the model and never returned by the API: an integrator who loses it
 * rotates it rather than retrieving it, which is the only arrangement under
 * which a leaked secret can be reasoned about.
 *
 * An empty `events` list means every event. That default is deliberate: an
 * integrator who forgets to subscribe gets too much rather than silence, and
 * silence is the failure mode nobody notices until something has been broken
 * for a month.
 */
class WebhookEndpoint extends BaseModel
{
    use Auditable, BelongsToOrganization, HasFactory;

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const DISABLED = 'disabled';

    protected $fillable = [
        'organization_id', 'name', 'url', 'events', 'status',
        'failure_threshold', 'timeout_seconds', 'max_attempts',
        'metadata', 'created_by_id',
    ];

    protected $hidden = ['signing_secret', 'headers'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'metadata' => 'array',
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
        ];
    }

    protected $attributes = [
        'status' => self::ACTIVE,
        'consecutive_failures' => 0,
        'failure_threshold' => 20,
        'timeout_seconds' => 10,
        'max_attempts' => 6,
    ];

    protected static function booted(): void
    {
        static::creating(function (WebhookEndpoint $endpoint): void {
            if (blank($endpoint->getRawOriginal('signing_secret'))) {
                $endpoint->signing_secret = self::freshSecret();
            }
        });
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * The signing secret, decrypted.
     *
     * Encrypted at rest so a database disclosure does not let anybody forge
     * signed payloads that this platform would appear to have sent.
     */
    protected function signingSecret(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : Crypt::decryptString($value),
            set: fn (?string $value): ?string => $value === null ? null : Crypt::encryptString($value),
        );
    }

    /**
     * Extra headers the receiver asked for, decrypted.
     *
     * Encrypted because they frequently carry a token of their own; a gateway
     * key in a header is no less a credential for being in a header.
     */
    protected function headers(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                if (blank($value)) {
                    return [];
                }

                return json_decode(Crypt::decryptString($value), true) ?: [];
            },
            set: fn (?array $value): ?string => blank($value)
                ? null
                : Crypt::encryptString(json_encode($value)),
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    /**
     * Endpoints that want a given event.
     *
     * A null or empty subscription list matches everything — see the class
     * docblock for why that is the default rather than the opposite.
     */
    public function scopeSubscribedTo(Builder $query, string $eventName): Builder
    {
        return $query->active()->where(function (Builder $q) use ($eventName): void {
            $q->whereNull('events')
                ->orWhereJsonLength('events', 0)
                ->orWhereJsonContains('events', $eventName);
        });
    }

    public function wantsEvent(string $eventName): bool
    {
        $events = $this->events;

        return blank($events) || in_array($eventName, $events, true);
    }

    public function isHealthy(): bool
    {
        return $this->status === self::ACTIVE && (int) $this->consecutive_failures === 0;
    }

    /**
     * How long to wait before attempt number `$attempt`.
     *
     * Exponential with a ceiling: an endpoint that is down for an hour should
     * not be hammered, and one that is down for a day should still be retried
     * occasionally rather than every few seconds.
     */
    public function backoffSeconds(int $attempt): int
    {
        return (int) min(3600, 10 * (2 ** max(0, $attempt - 1)));
    }

    public static function freshSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }
}
