<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An immutable record of a sensitive change.
 *
 * Audit rows are append-only: the model blocks updates and deletes so that a
 * bug elsewhere cannot rewrite history. Corrections are expressed by writing a
 * new entry.
 *
 * @property string $action
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 */
class AuditLog extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'user_id',
        'actor_type',
        'actor_label',
        'action',
        'auditable_type',
        'auditable_id',
        'description',
        'old_values',
        'new_values',
        'context',
        'ip_address',
        'user_agent',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'context' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): bool {
            throw new \RuntimeException('Audit log entries are immutable and cannot be updated.');
        });

        static::deleting(function (): bool {
            throw new \RuntimeException('Audit log entries are immutable and cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForEntity(Builder $query, string $type, string $id): Builder
    {
        return $query->where('auditable_type', $type)->where('auditable_id', $id);
    }

    public function scopeAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }
}
