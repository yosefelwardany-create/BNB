<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to deliver one event to one endpoint.
 *
 * Per attempt rather than per event, because the attempt that failed is the
 * one an integrator needs to see, and collapsing them into a row with a
 * counter discards exactly the response body that explains why.
 *
 * The payload is stored as it was sent rather than regenerated from the
 * subject. Regenerating it would show the record as it stands now, which is
 * useless for settling a signature dispute and misleading when the record has
 * since changed.
 */
class WebhookDelivery extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const PENDING = 'pending';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /** Given up on: the endpoint's attempt limit was reached. */
    public const ABANDONED = 'abandoned';

    protected $fillable = [
        'organization_id', 'webhook_endpoint_id', 'domain_event_id', 'event_name',
        'payload', 'attempt', 'status', 'next_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempt' => 'integer',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'dispatched_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
        ];
    }

    protected $attributes = [
        'status' => self::PENDING,
        'attempt' => 1,
    ];

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('status', [self::FAILED, self::ABANDONED]);
    }

    /**
     * Attempts waiting to be retried.
     */
    public function scopeDueForRetry(Builder $query, ?string $at = null): Builder
    {
        return $query->where('status', self::FAILED)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', $at ?? now());
    }

    public function succeeded(): bool
    {
        return $this->status === self::SUCCEEDED;
    }

    /**
     * Whether the receiver's answer means "do not bother trying again".
     *
     * A 4xx other than 408 and 429 is the receiver saying the request itself
     * is wrong — a bad signature, an unknown path, a rejected payload — and
     * repeating it verbatim cannot fix that. Everything else is worth another
     * attempt.
     */
    public static function isPermanent(?int $status): bool
    {
        if ($status === null) {
            return false;
        }

        if ($status === 408 || $status === 429) {
            return false;
        }

        return $status >= 400 && $status < 500;
    }
}
