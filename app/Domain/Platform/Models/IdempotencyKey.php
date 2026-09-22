<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Records that a uniquely-identified operation has already been performed.
 *
 * Used by the public API, payment provider callbacks, channel webhooks and
 * automation actions. Not tenant-scoped through the global scope because a
 * webhook is matched *before* the tenant is known.
 */
class IdempotencyKey extends BaseModel
{
    use HasFactory;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'scope',
        'key',
        'status',
        'request_fingerprint',
        'subject_type',
        'subject_id',
        'response',
        'locked_at',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'locked_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }
}
