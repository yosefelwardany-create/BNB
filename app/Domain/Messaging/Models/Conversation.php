<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Guests\Models\Guest;
use App\Domain\Listings\Models\Listing;
use App\Domain\Operations\Models\Team;
use App\Domain\Owners\Models\Owner;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasTags;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A thread with a guest, an owner or a vendor.
 *
 * Deliberately transport-agnostic: the same conversation can carry a message
 * that arrived through a channel's inbox, a reply sent by email and a note
 * added by a colleague. An operator should not have to look in three places
 * for one exchange.
 *
 * Response-time fields are maintained as messages arrive rather than computed
 * on read, because "which guests are waiting" is the inbox's primary sort and
 * must not require aggregating every message on every page load.
 */
class Conversation extends BaseModel
{
    use BelongsToOrganization, HasFactory, HasTags;

    public const STATUS_OPEN = 'open';

    public const STATUS_SNOOZED = 'snoozed';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'organization_id', 'reservation_id', 'guest_id', 'owner_id',
        'property_id', 'listing_id', 'subject', 'participant_type',
        'status', 'priority', 'assigned_to_id', 'team_id',
        'channel', 'channel_account_id', 'external_thread_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'immutable_datetime',
            'last_inbound_at' => 'immutable_datetime',
            'last_outbound_at' => 'immutable_datetime',
            'first_response_at' => 'immutable_datetime',
            'snoozed_until' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    protected $attributes = [
        'status' => self::STATUS_OPEN,
        'priority' => 'normal',
        'participant_type' => 'guest',
        'unread_count' => 0,
        'messages_count' => 0,
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    /**
     * Threads that belong in the working inbox: open, or snoozed past their
     * wake time.
     */
    public function scopeInbox(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->where('status', self::STATUS_OPEN)
                ->orWhere(fn (Builder $inner) => $inner
                    ->where('status', self::STATUS_SNOOZED)
                    ->where('snoozed_until', '<=', now()));
        });
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('unread_count', '>', 0);
    }

    /** Threads where the guest spoke last and nobody has replied. */
    public function scopeAwaitingReply(Builder $query): Builder
    {
        return $query->whereNotNull('last_inbound_at')
            ->where(function (Builder $q): void {
                $q->whereNull('last_outbound_at')
                    ->orWhereColumn('last_inbound_at', '>', 'last_outbound_at');
            });
    }

    public function scopeAssignedTo(Builder $query, string $userId): Builder
    {
        return $query->where('assigned_to_id', $userId);
    }

    // ------------------------------------------------------------------
    // Behaviour
    // ------------------------------------------------------------------

    public function isAwaitingReply(): bool
    {
        if ($this->last_inbound_at === null) {
            return false;
        }

        return $this->last_outbound_at === null
            || $this->last_inbound_at->greaterThan($this->last_outbound_at);
    }

    /**
     * How long a waiting guest has been waiting, in minutes.
     */
    public function minutesWaiting(): ?int
    {
        if (! $this->isAwaitingReply()) {
            return null;
        }

        return (int) $this->last_inbound_at->diffInMinutes(now());
    }

    public function displayTitle(): string
    {
        if (filled($this->subject)) {
            return $this->subject;
        }

        return match ($this->participant_type) {
            'owner' => $this->owner?->display_name ?? 'Owner',
            default => $this->guest?->display_name ?? 'Guest',
        };
    }
}
