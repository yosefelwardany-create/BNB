<?php

declare(strict_types=1);

namespace App\Domain\Events\Models;

use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The persisted event stream.
 *
 * Every business-significant change appends one row here. Automation rules,
 * outbound webhooks, notifications and analytics all consume this single
 * stream, which means a new consumer never requires changes to the domain
 * services that produce the events, and a failed consumer can be replayed.
 *
 * Rows are append-only.
 *
 * @property string $name
 * @property array<string, mixed>|null $payload
 */
class DomainEvent extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'name',
        'subject_type',
        'subject_id',
        'payload',
        'metadata',
        'actor_id',
        'actor_type',
        'idempotency_key',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): bool {
            throw new \RuntimeException('Domain events are immutable.');
        });

        static::deleting(function (): bool {
            throw new \RuntimeException('Domain events are immutable.');
        });
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeNamed(Builder $query, string|array $names): Builder
    {
        return $query->whereIn('name', (array) $names);
    }

    public function scopeForSubject(Builder $query, string $type, string $id): Builder
    {
        return $query->where('subject_type', $type)->where('subject_id', $id);
    }
}
