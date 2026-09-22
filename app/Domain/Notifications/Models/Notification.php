<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An in-app notification for one user.
 *
 * Separate from Laravel's generic notifications table because these are
 * tenant-scoped, queryable by subject, and drive an in-product inbox rather
 * than being a delivery record.
 */
class Notification extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'user_id', 'type', 'title', 'body', 'url', 'icon',
        'priority', 'subject_type', 'subject_id', 'read_at', 'data',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'immutable_datetime',
            'data' => 'array',
        ];
    }

    protected $attributes = ['priority' => 'normal'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }
}
