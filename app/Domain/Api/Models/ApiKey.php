<?php

declare(strict_types=1);

namespace App\Domain\Api\Models;

use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A credential for the public API.
 *
 * The key itself is never stored. Only a SHA-256 hash of it lives in the
 * database, so a disclosure hands an attacker nothing usable, and the key is
 * shown to its creator exactly once. An integrator who loses it issues a new
 * one; there is no recovery, because a system that can show you your key can
 * show it to somebody else.
 *
 * The prefix is stored in clear on purpose. It is not the key and cannot be
 * used as one, but it lets a list of keys be read by a human — "which of these
 * four is the one on the staging server" is a question somebody will have.
 *
 * `abilities` caps what requests made with this key can do, independently of
 * what the person who created it can do. A key made by an administrator is not
 * thereby an administrator: that is the difference between a scoped
 * integration credential and a spare password.
 */
class ApiKey extends BaseModel
{
    use Auditable, BelongsToOrganization, HasFactory;

    /** Identifies our keys in a log or a config file at a glance. */
    public const TOKEN_PREFIX = 'pms_';

    protected $fillable = [
        'organization_id', 'name', 'prefix', 'token_hash', 'abilities',
        'allowed_ips', 'rate_limit_per_minute', 'expires_at', 'metadata',
        'created_by_id',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'allowed_ips' => 'array',
            'metadata' => 'array',
            'rate_limit_per_minute' => 'integer',
            'request_count' => 'integer',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected $attributes = [
        'rate_limit_per_minute' => 120,
        'request_count' => 0,
    ];

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Mint a key.
     *
     * Returns the plain-text token alongside the record, because this is the
     * only moment it exists in a readable form. The caller shows it once and
     * discards it.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{key: self, token: string}
     */
    public static function issue(array $attributes): array
    {
        $token = self::TOKEN_PREFIX.Str::random(48);

        $key = new self;
        $key->fill($attributes);
        $key->prefix = substr($token, 0, 12);
        $key->token_hash = self::hash($token);
        $key->save();

        return ['key' => $key, 'token' => $token];
    }

    /**
     * Find the key a token belongs to, if it is still usable.
     *
     * Looked up by hash rather than by comparing candidates, so the work is a
     * single indexed lookup regardless of how many keys exist — and so no
     * comparison is made against a stored secret, because none is stored.
     */
    public static function findByToken(string $token): ?self
    {
        return self::query()
            ->withoutGlobalScope('organization')
            ->usable()
            ->where('token_hash', self::hash($token))
            ->first();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function hasAbility(string $ability): bool
    {
        $abilities = $this->abilities ?? [];

        // No list at all means no abilities, not every ability. A key created
        // without scopes should be able to do nothing, so that forgetting to
        // set them fails closed.
        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    /**
     * Whether a request from this address may use the key.
     *
     * An empty list means anywhere, which is the common case; a populated one
     * is an integrator who knows their egress addresses and wants the extra
     * guarantee.
     */
    public function allowsAddress(?string $ip): bool
    {
        $allowed = $this->allowed_ips ?? [];

        if ($allowed === []) {
            return true;
        }

        return $ip !== null && in_array($ip, $allowed, true);
    }

    public function revoke(?string $reason = null): void
    {
        $this->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ])->save();
    }

    /**
     * Record that the key was used.
     *
     * Deliberately not a model save: this runs on every authenticated API
     * request, and a full save would fire events and rewrite every column to
     * update a timestamp.
     */
    public function markUsed(?string $ip = null): void
    {
        self::query()
            ->withoutGlobalScope('organization')
            ->whereKey($this->getKey())
            ->update([
                'last_used_at' => now(),
                'last_used_ip' => $ip,
                'request_count' => DB::raw('request_count + 1'),
            ]);
    }
}
