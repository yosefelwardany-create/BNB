<?php

declare(strict_types=1);

namespace App\Domain\Channels\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a channel told us.
 *
 * Written before the payload is interpreted, so that "the channel never sent
 * it" and "we received it and got it wrong" are distinguishable afterwards.
 * They are the only two explanations when a booking does not appear, and
 * nothing else can tell them apart.
 */
class ChannelWebhookEvent extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const PENDING = 'pending';

    public const PROCESSED = 'processed';

    /** Understood, and nothing to do about it. */
    public const IGNORED = 'ignored';

    public const FAILED = 'failed';

    protected $fillable = [
        'organization_id', 'channel_account_id', 'type', 'provider_event_id',
        'payload', 'status', 'outcome', 'received_at', 'processed_at',
    ];

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    /**
     * Close this event with what happened to it.
     */
    public function settle(string $status, ?string $outcome = null): void
    {
        $this->forceFill([
            'status' => $status,
            'outcome' => $outcome === null ? null : mb_substr($outcome, 0, 2000),
            'processed_at' => now(),
        ])->save();
    }
}
