<?php

declare(strict_types=1);

namespace App\Domain\Channels\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One synchronisation attempt, and what came back.
 *
 * Kept because a channel that silently rejected a price change looks exactly
 * like one that accepted it — until a guest books at the old rate. The record
 * of what was sent, when, and what the channel said is the only way to tell
 * those two apart afterwards.
 */
class SyncJob extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $fillable = [
        'organization_id', 'channel_account_id', 'channel_listing_id',
        'kind', 'direction', 'status', 'range_start', 'range_end',
        'records_sent', 'records_received', 'records_failed',
        'error_code', 'error_message', 'is_retryable', 'attempts',
        'next_attempt_at', 'is_simulated', 'payload_summary', 'result',
        'idempotency_key', 'started_at', 'finished_at', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'range_start' => 'immutable_date',
            'range_end' => 'immutable_date',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'is_retryable' => 'boolean',
            'is_simulated' => 'boolean',
            'payload_summary' => 'array',
            'result' => 'array',
        ];
    }

    protected $attributes = [
        'status' => self::PENDING,
        'direction' => 'push',
        'records_sent' => 0,
        'records_received' => 0,
        'records_failed' => 0,
        'is_retryable' => false,
        'is_simulated' => false,
        'attempts' => 0,
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class, 'channel_account_id');
    }

    public function channelListing(): BelongsTo
    {
        return $this->belongsTo(ChannelListing::class, 'channel_listing_id');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::FAILED);
    }

    /**
     * Failures worth another attempt, whose backoff has elapsed.
     */
    public function scopeDueForRetry(Builder $query): Builder
    {
        return $query->failed()
            ->where('is_retryable', true)
            ->where('attempts', '<', (int) config('pms.channels.max_attempts', 6))
            ->where(fn (Builder $q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
    }

    public function succeeded(): bool
    {
        return $this->status === self::SUCCEEDED;
    }

    /**
     * How long to wait before trying again.
     *
     * Exponential, capped: a channel that is down stays down for minutes, not
     * milliseconds, and hammering it is how an account gets rate-limited into
     * a worse state than it started in.
     */
    public function backoffSeconds(): int
    {
        $base = (int) config('pms.channels.retry_base_delay', 30);

        return (int) min($base * (2 ** max(0, $this->attempts - 1)), 3600);
    }
}
