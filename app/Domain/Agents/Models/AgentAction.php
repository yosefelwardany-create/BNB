<?php

declare(strict_types=1);

namespace App\Domain\Agents\Models;

use App\Domain\Agents\Enums\AgentCapability;
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
 * Something an agent proposed to do.
 *
 * Most of these wait. Without a row there is nowhere for a proposal to sit, and
 * without somewhere to sit every action is autonomous whatever the settings say.
 *
 * `arguments` is stored as proposed and executed as stored. Approving "block the
 * 3rd to the 5th" must do that, not whatever the agent would propose if asked
 * again at the moment somebody pressed the button.
 */
class AgentAction extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    /** Waiting for a person. */
    public const STATUS_PROPOSED = 'proposed';

    /** A person said yes; it has not run yet. */
    public const STATUS_APPROVED = 'approved';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_REJECTED = 'rejected';

    /** It ran and the channel refused it. */
    public const STATUS_FAILED = 'failed';

    /** Nobody decided in time. */
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'organization_id', 'property_id', 'reservation_id', 'conversation_id',
        'requested_by_id', 'approved_by_id', 'capability', 'arguments', 'summary',
        'status', 'was_autonomous', 'outcome', 'external_reference',
        'expires_at', 'decided_at', 'executed_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_PROPOSED,
        'was_autonomous' => false,
    ];

    protected function casts(): array
    {
        return [
            'capability' => AgentCapability::class,
            'arguments' => 'array',
            'was_autonomous' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'executed_at' => 'immutable_datetime',
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /**
     * Still waiting on somebody, and still worth deciding.
     *
     * Deliberately not the same as `status === proposed`: a proposal past its
     * expiry is proposed until something sweeps it, and must not be approvable
     * in the meantime. An approval given to a week-old proposal is an approval
     * given to a situation that has moved on.
     */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PROPOSED
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function scopeLapsed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROPOSED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROPOSED);
    }
}
