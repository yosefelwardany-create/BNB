<?php

declare(strict_types=1);

namespace App\Domain\Agents\Models;

use App\Domain\Agents\Enums\AgentAudience;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question put to a property's bot, waiting for an answer or carrying one.
 *
 * The record exists because the answer does not arrive in the same breath as the
 * question. Habitat asks, the bot takes as long as it takes, and the answer lands
 * on a separate request minutes later — by which time the only thing connecting
 * the two is this row.
 *
 * It is also what makes a slow bot honest on screen. An ask that has gone out and
 * not come back is `pending` and says so, with the time it was sent; it is never
 * rendered as an empty answer or as nothing having happened.
 */
class AgentAsk extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ANSWERED = 'answered';

    /** The bot was asked and said it could not answer, or never got the question. */
    public const STATUS_FAILED = 'failed';

    /** Nobody answered in time, and the callback token is no longer good. */
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'organization_id', 'property_id', 'reservation_id', 'conversation_id',
        'asked_by_id', 'status', 'audience', 'question', 'history', 'guest_name',
        'callback_token_hash', 'expires_at', 'bot_name', 'endpoint_host',
        'sent_fact_keys', 'withheld', 'reply', 'intent', 'confidence',
        'would_auto_send', 'held_because', 'failure', 'dispatched_at', 'answered_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'audience' => AgentAudience::Guest->value,
        'would_auto_send' => false,
    ];

    /**
     * Never serialised, even by accident.
     *
     * It is a hash, so it is not the credential — but it is enough to recognise
     * a token somebody already holds, and nothing on any screen needs it.
     *
     * @var list<string>
     */
    protected $hidden = ['callback_token_hash'];

    protected function casts(): array
    {
        return [
            'audience' => AgentAudience::class,
            'history' => 'array',
            'sent_fact_keys' => 'array',
            'withheld' => 'array',
            'confidence' => 'float',
            'would_auto_send' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'answered_at' => 'immutable_datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function askedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asked_by_id');
    }

    /**
     * Still waiting, and still inside its window.
     *
     * Deliberately not the same as `status === pending`: a row whose expiry has
     * passed is pending until something sweeps it, and it must not accept an
     * answer in the meantime.
     */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * Asks that have run out of time but are still marked pending.
     */
    public function scopeLapsed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('expires_at', '<=', now());
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
